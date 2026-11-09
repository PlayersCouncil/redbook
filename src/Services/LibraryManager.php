<?php

namespace App\Services;

use App\Entity\Library;
use App\Entity\LibraryEntry;
use App\Entity\LibraryRevision;
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
    public const SORTS = ['new', 'old', 'popular', 'name', 'size'];
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
     * @param array{name?: string, description?: string, notes?: string, tags?: string, thumbnail?: array, entries?: array<array{decklist: int, annotation?: string}>} $data
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
        if (is_array($data['thumbnail'] ?? null)) {
            $this->setThumbnail($library, $data['thumbnail']);
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

    /* ---- history (Ketura, 2026-11-09) ---- */

    /**
     * Makes a change to the library and records it as a revision: who, how (a LibraryRevision
     * kind), and what changed. Nothing is recorded when nothing changed, unless it was just
     * made. A library from before there was history gets its state before the change recorded
     * first, as its baseline, so the first recorded change can be undone too.
     * @template T
     * @param callable(): T $apply
     * @param array $extra kept with the changes (a revert's revision, a copy's original)
     * @return T
     */
    public function change(Library $library, ?User $actor, string $kind, callable $apply, array $extra = []): mixed
    {
        $before = null;
        if ($library->getId() !== null) {
            // against the library as it is, so a change nobody recorded isn't put down to this one
            $before = $this->snapshot($library);
            if ($this->latestRevision($library) === null && !self::isEmpty($before)) {
                $baseline = new LibraryRevision($library, null, LibraryRevision::BASELINE, $before, null);
                $baseline->setDateCreation($library->getDateUpdate());
                $this->em->persist($baseline);
            }
        }
        $result = $apply();
        $after = $this->snapshot($library);
        $changes = $before === null ? [] : self::diff($before, $after);
        if ($changes !== [] || $before === null) {
            $changes += $extra;
            $this->em->persist(new LibraryRevision($library, $actor, $kind, $after, $changes ?: null));
        }
        return $result;
    }

    /**
     * The library's contents as a revision keeps them
     * @return array{name: string, description: string, notes: string, tags: string, thumbnail: array, entries: array<array{decklist: int, annotation: string}>}
     */
    public function snapshot(Library $library): array
    {
        $entries = $library->getEntries()->toArray();
        usort($entries, fn (LibraryEntry $a, LibraryEntry $b) => $a->getPosition() <=> $b->getPosition());
        return [
            'name' => $library->getName(),
            'description' => (string) $library->getDescriptionMd(),
            'notes' => (string) $library->getNotesMd(),
            'tags' => (string) $library->getTags(),
            'thumbnail' => [
                'card' => $library->getOwnThumbnailCard()?->getCode(),
                'crop' => $library->getThumbnailCrop(),
                'cultures' => $library->getOwnThumbnailCultures() ?: null,
            ],
            'entries' => array_map(fn (LibraryEntry $e) => ['decklist' => (int) $e->getDecklist()->getId(), 'annotation' => (string) $e->getAnnotationMd()], $entries),
        ];
    }

    private static function isEmpty(array $snapshot): bool
    {
        return $snapshot['entries'] === [] && $snapshot['description'] === '' && $snapshot['notes'] === '' && $snapshot['tags'] === '';
    }

    /**
     * What changed between two snapshots: the texts and thumbnail that differ, the decklists
     * added and removed, those whose commentary changed, and whether the rest moved
     * @return array{fields?: string[], added?: int[], removed?: int[], annotated?: int[], reordered?: bool}
     */
    public static function diff(array $before, array $after): array
    {
        $fields = array_values(array_filter(['name', 'description', 'notes', 'tags', 'thumbnail'], fn ($f) => ($before[$f] ?? null) != ($after[$f] ?? null)));
        $notes = fn (array $s) => array_column($s['entries'] ?? [], 'annotation', 'decklist');
        $a = $notes($before);
        $b = $notes($after);
        $kept = array_keys(array_intersect_key($b, $a));
        $order = array_values(array_filter(array_keys($a), fn ($id) => isset($b[$id])));
        return array_filter([
            'fields' => $fields,
            'added' => array_keys(array_diff_key($b, $a)),
            'removed' => array_keys(array_diff_key($a, $b)),
            'annotated' => array_values(array_filter($kept, fn ($id) => $a[$id] !== $b[$id])),
            'reordered' => $order !== $kept,
        ]);
    }

    public function latestRevision(Library $library): ?LibraryRevision
    {
        return $this->em->getRepository(LibraryRevision::class)->findOneBy(['library' => $library], ['id' => 'DESC']);
    }

    /** @return LibraryRevision[] newest first */
    public function revisions(Library $library): array
    {
        if ($library->getId() === null) {
            return [];
        }
        return $this->em->getRepository(LibraryRevision::class)->findBy(['library' => $library], ['id' => 'DESC']);
    }

    /**
     * Puts the library back as a revision had it, as a new revision. A decklist since withdrawn
     * or deleted can't come back; their ids are returned (and kept with the revert).
     * @return int[]
     */
    public function revert(Library $library, LibraryRevision $to, ?User $actor): array
    {
        $snapshot = $to->getSnapshot();
        $ids = array_column($snapshot['entries'] ?? [], 'decklist');
        $present = [];
        foreach ($ids ? $this->em->getRepository(PublishedDeck::class)->findBy(['id' => $ids]) : [] as $decklist) {
            if (!$decklist->isWithdrawn()) {
                $present[$decklist->getId()] = true;
            }
        }
        $missing = array_values(array_filter($ids, fn ($id) => !isset($present[$id])));
        $this->change($library, $actor, LibraryRevision::REVERT, function () use ($library, $snapshot) {
            $this->save($library, $snapshot);
            $thumbnail = $snapshot['thumbnail'] ?? [];
            $card = isset($thumbnail['card']) ? $this->em->getRepository(\App\Entity\Card::class)->findOneBy(['code' => $thumbnail['card']]) : null;
            $library->setThumbnailCard($card);
            $library->setThumbnailCrop($card ? ($thumbnail['crop'] ?? null) : null);
            $library->setThumbnailCultures($thumbnail['cultures'] ?? null);
        }, array_filter(['revision' => $to->getId(), 'missing' => $missing]));
        return $missing;
    }

    /**
     * Makes a user's library official by copying it (Ketura, 2026-11-09): a new library owned by
     * the PC's account, public, crediting the user as its curator and remembering where it came
     * from. The user's library stays theirs, untouched; the official copy is the Librarians' to
     * look after from then on. Refreshing copies the original's contents over again.
     */
    public function promote(Library $original, User $officialAccount, ?User $actor): Library
    {
        $copy = $this->create($officialAccount, $original->getName());
        $copy->setOfficial(true);
        $copy->setCurator($original->getUser());
        $copy->setPromotedFrom($original);
        $this->change($copy, $actor, LibraryRevision::PROMOTE, fn () => $this->copyContents($original, $copy));
        $this->setVisibility($copy, Library::PUBLIC);
        return $copy;
    }

    /** The official copy, made again from its original: texts, tags, thumbnail and decks */
    public function refreshFromOriginal(Library $copy, ?User $actor): bool
    {
        $original = $copy->getPromotedFrom();
        if ($original === null) {
            return false;
        }
        $this->change($copy, $actor, LibraryRevision::REFRESH, fn () => $this->copyContents($original, $copy));
        return true;
    }

    /** The official copy of a user's library, if one was made */
    public function officialCopyOf(Library $original): ?Library
    {
        return $this->em->getRepository(Library::class)->findOneBy(['promotedFrom' => $original, 'official' => true]);
    }

    private function copyContents(Library $from, Library $to): void
    {
        $this->save($to, [
            'name' => $from->getName(),
            'description' => (string) $from->getDescriptionMd(),
            'notes' => (string) $from->getNotesMd(),
            'tags' => (string) $from->getTags(),
            'entries' => array_map(fn (LibraryEntry $e) => ['decklist' => $e->getDecklist()->getId(), 'annotation' => (string) $e->getAnnotationMd()], $from->getEntries()->toArray()),
        ]);
        $to->setThumbnailCard($from->getOwnThumbnailCard());
        $to->setThumbnailCrop($from->getThumbnailCrop());
        $to->setThumbnailCultures($from->getOwnThumbnailCultures());
    }

    /**
     * The library's own thumbnail (Ketura, 2026-11-08/09): a card (any with a picture, framed
     * by hand or by its template), a banner of one or two cultures in any combination, or
     * neither, for its first deck's. One clears the others.
     * @param array{mode?: string, card?: string, crop?: mixed, cultures?: string[]} $thumbnail
     */
    public function setThumbnail(Library $library, array $thumbnail): void
    {
        $library->setThumbnailCard(null);
        $library->setThumbnailCrop(null);
        $library->setThumbnailCultures(null);
        $mode = (string) ($thumbnail['mode'] ?? 'auto');
        if ($mode === 'card') {
            $card = $this->em->getRepository(\App\Entity\Card::class)->findOneBy(['code' => (string) ($thumbnail['card'] ?? '')]);
            if ($card && $card->getImageUrl()) {
                $library->setThumbnailCard($card);
                $library->setThumbnailCrop(\App\Classes\ThumbnailCrop::sanitize($thumbnail['crop'] ?? null, [$card->getCode()]));
            }
        } elseif ($mode === 'cultures') {
            $parts = [];
            foreach (array_slice(array_values(array_unique(array_map('strval', (array) ($thumbnail['cultures'] ?? [])))), 0, 2) as $code) {
                $culture = $this->em->getRepository(\App\Entity\Culture::class)->findOneBy(['code' => $code]);
                // the cultures with a side have banner art; the One Ring has none
                if ($culture && $culture->getSide()) {
                    $parts[] = ['code' => $culture->getCode(), 'name' => $culture->getName(), 'side' => $culture->getSide()];
                }
            }
            $library->setThumbnailCultures($parts);
        }
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
            'show' => count($show) === count(self::SHOWS) ? [] : $show,
            'sort' => in_array($sort, self::SORTS, true) && $sort !== 'new' ? $sort : null,
            'limit' => in_array($limit, self::LIMITS, true) && $limit !== PublishedDeckManager::DEFAULT_LIMIT ? $limit : null,
        ]);
    }
}
