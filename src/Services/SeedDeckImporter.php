<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use App\Entity\Decklist;
use App\Entity\DecklistPlay;
use App\Entity\Decklistslot;
use App\Entity\DecklistSource;
use App\Entity\Format;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use InvalidArgumentException;

/**
 * Imports a seed-deck batch from the archive scrapes (phase3/06, seed-deck.schema.json).
 *
 * Each deck becomes a published decklist owned by the "archive" user, keyed by
 * (source, sourceId) so a re-import updates it. Gemp validation is stored but
 * informational (historical decks, decision 0019). Nothing the source said is
 * dropped: lines the scraper couldn't map, and blueprint IDs Red Book doesn't
 * know, are kept as unresolved lines and shown on the decklist.
 */
class SeedDeckImporter
{
    public const ARCHIVE_USERNAME = 'archive';

    /** @var array<string, Card> */
    private array $cards = [];
    /** @var array<string, bool> */
    private array $formats = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManagerInterface $userManager,
        private readonly DeckManager $deckManager,
        private readonly DeckValidator $validator,
        private readonly Texts $texts,
    ) {
    }

    /**
     * @return array{created: int, updated: int, skipped: int, problems: string[]}
     */
    public function import(array $batch, bool $validate = true, bool $dryRun = false): array
    {
        $this->checkBatch($batch);
        $source = $batch['source'];
        $archive = $this->archiveUser();
        foreach ($this->em->getRepository(Format::class)->findAll() as $format) {
            $this->formats[$format->getCode()] = true;
        }

        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'problems' => []];
        foreach ($batch['decks'] as $i => $deck) {
            $missing = array_diff(['sourceId', 'sourceUrl', 'title', 'legacyAuthor', 'postedDate', 'drawDeck', 'rawDecklist'], array_keys($deck));
            if ($missing) {
                $report['skipped']++;
                $report['problems'][] = sprintf('deck %d (%s): missing %s', $i, $deck['sourceId'] ?? '?', implode(', ', $missing));
                continue;
            }
            $existing = $this->em->getRepository(DecklistSource::class)->findOneBy(['source' => $source, 'sourceId' => (string) $deck['sourceId']]);
            $decklist = $existing?->getDecklist() ?? new Decklist();
            $this->fill($decklist, $deck, $source, $archive, $batch['generator'] ?? null, $validate, $report);
            $existing ? $report['updated']++ : $report['created']++;
            if (!$dryRun) {
                $this->em->persist($decklist);
                if (($report['created'] + $report['updated']) % 50 === 0) {
                    $this->em->flush();
                }
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }
        return $report;
    }

    private function fill(Decklist $decklist, array $deck, string $sourceCode, User $archive, ?string $generator, bool $validate, array &$report): void
    {
        $isNew = $decklist->getId() === null && $decklist->getSource() === null;
        $source = $decklist->getSource() ?? new DecklistSource($decklist, $sourceCode, (string) $deck['sourceId']);
        $decklist->setSource($source);

        $source->setSourceUrl((string) $deck['sourceUrl']);
        $source->setArchiveUrl($deck['archiveUrl'] ?? null);
        $source->setLegacyAuthor((string) $deck['legacyAuthor']);
        $source->setPostedDateText((string) $deck['postedDate']);
        $source->setPostedDate(self::partialDate($deck['postedDate']));
        $source->setFormatAsWritten($deck['formatAsWritten'] ?? null);
        $source->setRawDecklist((string) $deck['rawDecklist']);
        $source->setRatings($deck['ratings'] ?? null);
        $source->setComments($deck['comments'] ?? []);
        $source->setBatch($generator);

        // the content, with anything unknown kept as an unresolved line
        $unresolved = $deck['unresolved'] ?? [];
        $content = [];
        $add = function (?string $code, string $section, int $count = 1, bool $ringBearer = false) use (&$content, &$unresolved) {
            if ($code === null || $code === '') {
                return;
            }
            if (!$this->card($code)) {
                $unresolved[] = ['line' => $code, 'count' => $count, 'section' => $section, 'reason' => 'blueprint not in Red Book'];
                return;
            }
            $key = $ringBearer ? DeckContent::RING_BEARER_PREFIX . $code : $code;
            $content[$key] = ($content[$key] ?? 0) + $count;
        };
        $add($deck['ringBearer'] ?? null, 'ringBearer', 1, true);
        $add($deck['ring'] ?? null, 'ring');
        foreach ($deck['sites'] ?? [] as $site) {
            $add($site, 'sites');
        }
        foreach ($deck['drawDeck'] as $row) {
            $add($row['card'] ?? null, 'draw', max(1, (int) ($row['count'] ?? 1)));
        }
        $add($deck['map'] ?? null, 'map');
        $source->setUnresolved($unresolved);

        $format = $deck['targetFormat'] ?? null;
        if ($format !== null && !isset($this->formats[$format])) {
            $report['problems'][] = sprintf('%s: unknown format "%s", left unset', $deck['sourceId'], $format);
            $format = null;
        }

        $name = mb_substr(trim((string) $deck['title']) ?: 'Untitled', 0, 60);
        $description = self::toMarkdown((string) ($deck['description'] ?? ''), $deck['descriptionFormat'] ?? 'text');
        $posted = self::partialDate($deck['postedDate']) ?? new DateTime('today');

        $decklist->setName($name);
        $decklist->setNameCanonical($this->texts->slugify($name) . '-1.0');
        $decklist->setVersion('1.0');
        $decklist->setDescriptionMd($description);
        $decklist->setDescriptionHtml($this->texts->markdown($description));
        if ($isNew) {
            $decklist->setDateCreation($posted);
            $decklist->setNbVotes(0);
            $decklist->setNbfavorites(0);
            $decklist->setNbcomments(0);
            $decklist->setUser($archive);
        }
        $decklist->setDateUpdate(new DateTime());
        $decklist->setFaction($this->deckManager->getPlaceholderFaction());
        $decklist->setTargetFormat($format ?? '');
        $decklist->setEffectiveDate(self::partialDate($deck['effectiveDate'] ?? null) ?? $posted);
        $decklist->setSignature(md5(json_encode($content)));

        foreach ($decklist->getSlots()->getSlots() as $slot) {
            $decklist->getSlots()->removeElement($slot);
            $this->em->remove($slot);
        }
        $latestPack = null;
        foreach ($content as $key => $quantity) {
            [$code, $isRingBearer] = DeckContent::parseKey($key);
            $card = $this->card($code);
            $slot = new Decklistslot();
            $slot->setCard($card);
            $slot->setQuantity($quantity);
            $slot->setRole($isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::roleForType($card->getType()->getCode()));
            $slot->setDecklist($decklist);
            $decklist->getSlots()->add($slot);
            if (!$latestPack || ($card->getPack()->getDateRelease() && $latestPack->getDateRelease() < $card->getPack()->getDateRelease())) {
                $latestPack = $card->getPack();
            }
        }
        $decklist->setLastPack($latestPack);
        $decklist->updateCultures();

        foreach ($decklist->getPlays()->toArray() as $play) {
            $decklist->removePlay($play);
        }
        if (!empty($deck['tournament']['name'])) {
            $play = new DecklistPlay();
            $play->setEventName(mb_substr((string) $deck['tournament']['name'], 0, 255));
            $play->setEventDate(self::partialDate($deck['tournament']['date'] ?? null));
            $play->setPlacing(isset($deck['tournament']['placing']) ? mb_substr((string) $deck['tournament']['placing'], 0, 50) : null);
            $play->setPlayerCount(isset($deck['tournament']['players']) ? (int) $deck['tournament']['players'] : null);
            $play->setLocation($deck['tournament']['location'] ?? null);
            $play->setFormat($format);
            $decklist->addPlay($play);
        }

        if ($validate && $format !== null) {
            $this->validator->validateDeck($decklist);
        } else {
            $decklist->setValidation(null);
        }
    }

    private function card(string $code): ?Card
    {
        if (!array_key_exists($code, $this->cards)) {
            $this->cards[$code] = $this->em->getRepository(Card::class)->findOneBy(['code' => $code]);
        }
        return $this->cards[$code];
    }

    private function checkBatch(array $batch): void
    {
        if (($batch['schemaVersion'] ?? null) !== 1) {
            throw new InvalidArgumentException('Not a seed-deck batch with schemaVersion 1');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', (string) ($batch['source'] ?? ''))) {
            throw new InvalidArgumentException('The batch needs a source code such as "decktech"');
        }
        if (!is_array($batch['decks'] ?? null)) {
            throw new InvalidArgumentException('The batch has no decks array');
        }
    }

    /** The user that owns seeded decklists until someone claims them; it can't log in */
    public function archiveUser(): User
    {
        $user = $this->userManager->findUserByUsername(self::ARCHIVE_USERNAME);
        if ($user instanceof User) {
            return $user;
        }
        /** @var User $user */
        $user = $this->userManager->createUser();
        $user->setUsername(self::ARCHIVE_USERNAME);
        $user->setEmail('archive@redbook.invalid');
        $user->setPlainPassword(bin2hex(random_bytes(24)));
        $user->setEnabled(false);
        $user->setResume('Decks from the decktech.net and Lothlorien archives, kept until their authors claim them.');
        $this->userManager->updateUser($user);
        return $user;
    }

    /** "2003-08-14", "2003-08" or "2003" as a date (the first day of the period) */
    public static function partialDate(mixed $value): ?DateTime
    {
        if (!is_string($value) || !preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $value, $m)) {
            return null;
        }
        return DateTime::createFromFormat('!Y-m-d', sprintf('%s-%s-%s', $m[1], $m[2] ?? '01' ?: '01', $m[3] ?? '01' ?: '01')) ?: null;
    }

    /** The source's write-up as Markdown text; HTML and BBCode are reduced to plain text */
    public static function toMarkdown(string $text, string $format): string
    {
        if ($format === 'html') {
            $text = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif ($format === 'bbcode') {
            $text = preg_replace('#\[(/?)(b|i|u|s|url|img|quote|code|size|color|list|\*)[^\]]*\]#i', '', $text);
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)));
    }
}
