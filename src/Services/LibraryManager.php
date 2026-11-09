<?php

namespace App\Services;

use App\Entity\Library;
use App\Entity\LibraryEntry;
use App\Entity\PublishedDeck;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Libraries (Ketura, 2026-11-08): making them, who may change them, what's in them, their
 * visibility, and the filters their lists take
 */
class LibraryManager
{
    public const SORTS = ['new', 'old', 'name', 'size'];
    public const LIMITS = [10, 30, 50, 80, 100];
    public const SHOWS = [Library::PRIVATE, Library::HIDDEN, Library::PUBLIC];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Texts $texts,
        private readonly Security $security,
    ) {
    }

    public function create(User $user, string $name, string $description = ''): Library
    {
        $library = new Library();
        $library->setUuid(Uuid::uuid4());
        $library->setUser($user);
        $this->rename($library, $name);
        $this->describe($library, $description);
        $this->em->persist($library);
        return $library;
    }

    public function rename(Library $library, string $name): void
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 255) ?: 'Untitled library';
        $library->setName($name);
        $library->setNameCanonical($this->texts->slugify($name) ?: 'library');
    }

    public function describe(Library $library, string $description): void
    {
        $description = trim($description);
        $library->setDescriptionMd($description !== '' ? $description : null);
        $library->setDescriptionHtml($description !== '' ? $this->texts->markdown($description) : null);
    }

    /**
     * The owner changes it; so may a Librarian, once it's public or the PC's own (Ketura,
     * 2026-11-08: public libraries will want looking after, and event libraries have no owner
     * to speak of)
     */
    public function canEdit(?User $user, Library $library): bool
    {
        if ($user === null) {
            return false;
        }
        if ($library->getUser()->getId() === $user->getId()) {
            return true;
        }
        return ($library->isPublic() || $library->isOfficial()) && $this->security->isGranted('ROLE_LIBRARIAN');
    }

    /** Anyone may see a public or hidden library; a private one only who may change it */
    public function canView(?User $user, Library $library): bool
    {
        return $library->getVisibility() !== Library::PRIVATE || $this->canEdit($user, $library);
    }

    /** Public the first time takes the next public number, its address from then on */
    public function setVisibility(Library $library, string $visibility): void
    {
        $library->setVisibility($visibility);
        if ($library->isPublic() && $library->getPublicId() === null) {
            $library->setPublicId($this->em->getRepository(Library::class)->nextPublicId());
            $library->setDatePublic(new DateTime());
        }
        $this->touch($library);
    }

    public function touch(Library $library): void
    {
        $library->setDateUpdate(new DateTime());
    }

    /**
     * Decklists onto the end of the library, skipping those already in it; a withdrawn one
     * can't be added (it's in nobody's view)
     * @param PublishedDeck[] $decklists
     * @return int how many were added
     */
    public function add(Library $library, array $decklists): int
    {
        $position = 0;
        foreach ($library->getEntries() as $entry) {
            $position = max($position, $entry->getPosition() + 1);
        }
        $added = 0;
        foreach ($decklists as $decklist) {
            if ($decklist->isWithdrawn() || $library->hasDecklist($decklist)) {
                continue;
            }
            $entry = new LibraryEntry($decklist);
            $entry->setPosition($position++);
            $library->addEntry($entry);
            $added++;
        }
        if ($added) {
            $this->touch($library);
        }
        return $added;
    }

    /**
     * The editor's Save: the name, description, notes and tags, and the entries in their new
     * order with their commentary. An entry left out is removed; a decklist not yet in it
     * (added from the Build tab) is added.
     * @param array{name?: string, description?: string, notes?: string, tags?: string, thumbnail?: string, entries?: array<array{decklist: int, annotation?: string}>} $data
     */
    public function save(Library $library, array $data): void
    {
        $this->rename($library, (string) ($data['name'] ?? $library->getName()));
        $this->describe($library, (string) ($data['description'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        $library->setNotesMd($notes !== '' ? $notes : null);
        $library->setNotesHtml($notes !== '' ? $this->texts->markdown($notes) : null);
        $tags = array_unique(array_filter(array_map(
            fn ($t) => mb_substr(preg_replace('/\s+/u', '', strip_tags((string) $t)), 0, 60),
            preg_split('/\s+/', (string) ($data['tags'] ?? ''))
        ), 'strlen'));
        $library->setTags($tags ? implode(' ', $tags) : null);
        if (array_key_exists('thumbnail', $data)) {
            $code = trim((string) $data['thumbnail']);
            $library->setThumbnailCard($code !== '' ? $this->em->getRepository(\App\Entity\Card::class)->findOneBy(['code' => $code]) : null);
        }

        $existing = [];
        foreach ($library->getEntries() as $entry) {
            $existing[$entry->getDecklist()->getId()] = $entry;
        }
        $kept = [];
        $position = 0;
        foreach ((array) ($data['entries'] ?? []) as $row) {
            $id = (int) ($row['decklist'] ?? 0);
            if (!$id || isset($kept[$id])) {
                continue;
            }
            $entry = $existing[$id] ?? null;
            if ($entry === null) {
                $decklist = $this->em->getRepository(PublishedDeck::class)->find($id);
                if (!$decklist || $decklist->isWithdrawn()) {
                    continue;
                }
                $entry = new LibraryEntry($decklist);
                $library->addEntry($entry);
            }
            $annotation = trim((string) ($row['annotation'] ?? ''));
            $entry->setAnnotationMd($annotation !== '' ? $annotation : null);
            $entry->setAnnotationHtml($annotation !== '' ? $this->texts->markdown($annotation) : null);
            $entry->setPosition($position++);
            $kept[$id] = true;
        }
        foreach ($existing as $id => $entry) {
            if (!isset($kept[$id])) {
                $library->removeEntry($entry);
            }
        }
        $this->touch($library);
    }

    /** The latest public version of the decklist's line, when it's newer than this one */
    public function newerVersion(PublishedDeck $decklist): ?PublishedDeck
    {
        if ($decklist->getLine() === null) {
            return null;
        }
        foreach ($this->em->getRepository(PublishedDeck::class)->findVersions($decklist) as $version) {
            if (!$version->isWithdrawn() && $version->getVersion() > $decklist->getVersion()) {
                return $version;
            }
        }
        return null;
    }

    /** The filters a list of libraries takes; mine: the user's own list, which has Include */
    public function getFilters(Request $request, bool $mine): array
    {
        $all = $request->query->all();
        $text = fn (string $key) => mb_substr(trim(strip_tags(is_array($all[$key] ?? null) ? '' : (string) ($all[$key] ?? ''))), 0, 100);
        $tags = fn (string $key) => array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_substr(preg_replace('/\s+/u', '', strip_tags((string) $t)), 0, 60),
            (array) ($all[$key] ?? [])
        ))));
        $date = function (string $key) use ($text): ?string {
            $value = $text($key);
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $day && $day->format('Y-m-d') === $value ? $value : null;
        };
        $sort = $text('sort');
        $limit = (int) $request->query->get('limit');
        $show = $mine ? array_values(array_intersect(self::SHOWS, (array) ($all['show'] ?? []))) : [];
        return array_filter([
            'name' => $text('name'),
            'author' => $mine ? '' : $text('author'),
            'tags' => $tags('tags'),
            'xtags' => $tags('xtags'),
            'from' => $date('from'),
            'to' => $date('to'),
            'official' => (bool) $request->query->get('official'),
            'show' => count($show) === count(self::SHOWS) ? [] : $show,
            'sort' => in_array($sort, self::SORTS, true) && $sort !== 'new' ? $sort : null,
            'limit' => in_array($limit, self::LIMITS, true) && $limit !== PublishedDeckManager::DEFAULT_LIMIT ? $limit : null,
        ]);
    }
}
