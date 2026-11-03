<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckPlay;
use App\Entity\PublishedDeckSlot;
use App\Entity\PublishedDeckSource;
use App\Entity\Format;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Imports a seed-deck batch from the archive scrapes (phase3/06, seed-deck.schema.json).
 *
 * Each author becomes a historic account (one per source and name; Ketura,
 * 2026-11-02), and each deck a private deck of theirs carrying the Historical Deck
 * Information, published as a decklist keyed by (source, sourceId) so a re-import
 * updates it. Imported decklists wait in the historians' review queue as
 * unverified; a re-import leaves verified ones alone. Gemp validation is stored but
 * informational (historical decks, decision 0019). Nothing the source said is
 * dropped: lines the scraper couldn't map, and blueprint IDs Red Book doesn't
 * know, are kept as unresolved lines and shown on the decklist.
 */
class SeedDeckImporter
{
    /** The single account that owned seeded decks before historic accounts; its decks move on re-import */
    public const LEGACY_ARCHIVE_USERNAME = 'archive';

    /** Decks per flush; the entity manager is cleared after each so a big batch keeps its memory flat */
    public const CHUNK = 100;

    /** @var array<string, Card|null> */
    private array $cards = [];
    /** @var array<string, bool> */
    private array $formats = [];
    /** @var array<string, int> historic key => account id */
    private array $accounts = [];
    /** @var array<string, User> historic key => account, until the next clear */
    private array $accountObjects = [];
    private ?int $nextHistoricNumber = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManagerInterface $userManager,
        private readonly DeckManager $deckManager,
        private readonly DeckValidator $validator,
        private readonly Texts $texts,
        private readonly FormatGuesser $guesser,
    ) {
    }

    /**
     * @return array{created: int, updated: int, skipped: int, kept: int, guessed: int, flagged: int, accounts: int, problems: string[]}
     */
    public function import(array $batch, bool $validate = true, bool $dryRun = false, ?callable $progress = null): array
    {
        $this->checkBatch($batch);
        $source = $batch['source'];
        foreach ($this->em->getRepository(Format::class)->findAll() as $format) {
            $this->formats[$format->getCode()] = true;
        }
        $this->accounts = [];
        $this->accountObjects = [];
        $this->nextHistoricNumber = null;

        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'kept' => 0, 'guessed' => 0, 'flagged' => 0, 'accounts' => 0, 'problems' => []];
        $total = count($batch['decks']);
        foreach (array_chunk($batch['decks'], self::CHUNK, true) as $chunk) {
            $this->preloadCards($chunk);
            foreach ($chunk as $i => $deck) {
                $missing = array_diff(['sourceId', 'sourceUrl', 'title', 'legacyAuthor', 'postedDate', 'drawDeck', 'rawDecklist'], array_keys($deck));
                if ($missing) {
                    $report['skipped']++;
                    $report['problems'][] = sprintf('deck %d (%s): missing %s', $i, $deck['sourceId'] ?? '?', implode(', ', $missing));
                    continue;
                }
                $existing = $this->em->getRepository(PublishedDeckSource::class)->findOneBy(['source' => $source, 'sourceId' => (string) $deck['sourceId']]);
                $decklist = $existing?->getDecklist() ?? new PublishedDeck();
                if ($decklist->isVerified()) {
                    // a historian has checked it against the source; the scrape doesn't get to undo that
                    $report['kept']++;
                    continue;
                }
                $owner = $this->historicAccount($source, (string) $deck['legacyAuthor'], $dryRun, $report);
                $current = $decklist->getUser();
                if ($current instanceof User && !$current->isHistoric() && $current->getUsername() !== self::LEGACY_ARCHIVE_USERNAME) {
                    // claimed before historic accounts existed: it stays with that person
                    $owner = $current;
                }
                $this->fill($decklist, $deck, $source, $owner, $batch['generator'] ?? null, $validate, $report);
                $privateDeck = $this->fillPrivateDeck($decklist, $deck, $source, $owner);
                $existing ? $report['updated']++ : $report['created']++;
                if (!$dryRun) {
                    $this->em->persist($privateDeck);
                    $this->em->persist($decklist);
                }
            }
            if (!$dryRun) {
                $this->em->flush();
                foreach ($this->accountObjects as $key => $account) {
                    $this->accounts[$key] = $account->getId();
                }
                $this->accountObjects = [];
            }
            // detach everything, so 6000 decks cost the memory of 100
            $this->em->clear();
            $this->cards = [];
            if ($progress) {
                $progress(min($total, $report['created'] + $report['updated'] + $report['skipped'] + $report['kept']), $total);
            }
        }
        return $report;
    }

    private function fill(PublishedDeck $decklist, array $deck, string $sourceCode, User $owner, ?string $generator, bool $validate, array &$report): void
    {
        $isNew = $decklist->getId() === null && $decklist->getSource() === null;
        $source = $decklist->getSource() ?? new PublishedDeckSource($decklist, $sourceCode, (string) $deck['sourceId']);
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

        // no format from the scraper: guess one from the deck's date, sites and sets
        $source->setFormatGuess(null);
        if ($format === null && $content) {
            $guess = $this->guesser->guess(
                $this->guesser->slotsFromContent($content),
                self::effectiveDate($deck)
            );
            $top = $guess['candidates'][0] ?? null;
            if ($top !== null) {
                $format = $top['format']->getCode();
                $report['guessed']++;
                $source->setFormatGuess([
                    'format' => $format,
                    'reasons' => $top['reasons'],
                    'alternatives' => array_map(fn ($c) => $c['format']->getCode(), array_slice($guess['candidates'], 1, 4)),
                ]);
            }
        }

        $name = mb_substr(trim((string) $deck['title']) ?: 'Untitled', 0, 60);
        $description = self::toMarkdown((string) ($deck['description'] ?? ''), $deck['descriptionFormat'] ?? 'text');
        $posted = self::partialDate($deck['postedDate']) ?? new DateTime('today');
        $effective = self::effectiveDate($deck) ?? $posted;

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
        }
        // the author's historic account (or whoever claimed it)
        $decklist->setUser($owner);
        $decklist->setDateUpdate(new DateTime());
        $decklist->setFaction($this->deckManager->getPlaceholderFaction());
        $decklist->setTargetFormat($format ?? '');
        $decklist->setEffectiveDate($effective);
        $decklist->setSignature(md5(json_encode($content)));

        foreach ($decklist->getSlots()->getSlots() as $slot) {
            $decklist->getSlots()->removeElement($slot);
            $this->em->remove($slot);
        }
        $latestPack = null;
        foreach ($content as $key => $quantity) {
            [$code, $isRingBearer] = DeckContent::parseKey($key);
            $card = $this->card($code);
            $slot = new PublishedDeckSlot();
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
            $play = new PublishedDeckPlay();
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

        // for the review queue: what a historian should look at
        $flags = [];
        foreach ($deck['parseIssues'] ?? [] as $issue) {
            $flags[] = ['code' => 'parse', 'detail' => mb_substr((string) $issue, 0, 500)];
        }
        if ($unresolved) {
            $flags[] = ['code' => 'unresolved', 'detail' => (string) count($unresolved)];
        }
        if ($format === null) {
            $flags[] = ['code' => 'no_format'];
        } elseif ($source->getFormatGuess() !== null) {
            $flags[] = ['code' => 'format_guessed', 'detail' => $format];
        }
        $problems = $decklist->getValidation()?->getRelevantProblems() ?? [];
        if ($problems) {
            $flags[] = ['code' => 'problems', 'detail' => implode('; ', array_slice($problems, 0, 5))];
        }
        $decklist->setReviewFlags($flags);
        $decklist->markUnverified();
        if ($flags) {
            $report['flagged']++;
        }
    }

    /**
     * The private deck behind the published one: what a historian opens in the
     * deckbuilder, carrying the Historical Deck Information.
     */
    private function fillPrivateDeck(PublishedDeck $decklist, array $deck, string $sourceCode, User $owner): Deck
    {
        $private = $decklist->getParent();
        if (!$private instanceof Deck) {
            $private = new Deck();
            $private->setUuid(Uuid::uuid4());
            $private->setMajorVersion(1);
            $private->setMinorVersion(0);
            $private->setDateCreation($decklist->getDateCreation());
            $decklist->setParent($private);
            $private->addChild($decklist);
        }
        $private->setUser($owner);
        $private->setName($decklist->getName());
        $private->setDescriptionMd($decklist->getDescriptionMd());
        $private->setFaction($decklist->getFaction());
        $private->setLastPack($decklist->getLastPack());
        $private->setTargetFormat($decklist->getTargetFormat());
        $private->setEffectiveDate($decklist->getEffectiveDate());
        $private->setTags(implode(' ', array_map(fn ($t) => preg_replace('/\s+/', '-', trim((string) $t)), $deck['tags'] ?? [])));
        $private->setValidation($decklist->getValidation());

        $tournament = $deck['tournament'] ?? [];
        $private->setHistoric(DeckManager::normalizeHistoric([
            'author' => $deck['legacyAuthor'],
            'source' => $sourceCode,
            'source_url' => $deck['sourceUrl'],
            'archive_url' => $deck['archiveUrl'] ?? '',
            'event' => $tournament['name'] ?? '',
            'placing' => $tournament['placing'] ?? '',
            'players' => $tournament['players'] ?? '',
            'date_used' => self::dateUsed($deck) ?? '',
            'date_posted' => $deck['postedDate'],
            'date_retrieved' => self::archiveDate($deck['archiveUrl'] ?? null) ?? '',
            'format_as_written' => $deck['formatAsWritten'] ?? '',
            'notes' => '',
        ]));

        $content = [];
        $cards = [];
        foreach ($decklist->getSlots() as $slot) {
            $key = $slot->getRole() === DeckContent::ROLE_RING_BEARER ? DeckContent::RING_BEARER_PREFIX . $slot->getCard()->getCode() : $slot->getCard()->getCode();
            $content[$key] = $slot->getQuantity();
            $cards[$slot->getCard()->getCode()] = $slot->getCard();
        }
        $this->deckManager->setSlots($private, $content, $cards);
        return $private;
    }

    /**
     * The historic account for an author in a source, made on first sight. Follows
     * merges, so a claimed author's decks go to the person who claimed them.
     */
    private function historicAccount(string $source, string $author, bool $dryRun, array &$report): User
    {
        $key = self::historicKey($source, $author);
        $repository = $this->em->getRepository(User::class);
        $user = $this->accountObjects[$key]
            ?? (isset($this->accounts[$key]) ? $repository->find($this->accounts[$key]) : $repository->findOneBy(['historicKey' => $key]));
        if (!$user instanceof User) {
            /** @var User $user */
            $user = $this->userManager->createUser();
            $number = $this->nextHistoricNumber();
            $user->setUsername('historic_' . $number);
            $user->setEmail(sprintf('historic+%d@redbook.invalid', $number));
            // no password hash matches this, and the account is disabled: nobody logs in as it
            $user->setPassword('!historic');
            $user->setEnabled(false);
            $user->setHistoric(true);
            $user->setHistoricKey($key);
            $user->setHistoricSource($source);
            $user->setDisplayName(mb_substr(trim($author), 0, 100) ?: 'Unknown author');
            $this->userManager->updateCanonicalFields($user);
            $report['accounts']++;
            if (!$dryRun) {
                $this->em->persist($user);
            }
        }
        $this->accountObjects[$key] = $user;
        return AccountMerger::resolve($user);
    }

    private function nextHistoricNumber(): int
    {
        if ($this->nextHistoricNumber === null) {
            $max = $this->em->getConnection()->fetchOne(
                "SELECT MAX(CAST(SUBSTRING(username, 10) AS UNSIGNED)) FROM user WHERE username LIKE 'historic\\_%'"
            );
            $this->nextHistoricNumber = (int) $max + 1;
        }
        return $this->nextHistoricNumber++;
    }

    /** One historic account per source and author name, ignoring case and spacing */
    public static function historicKey(string $source, string $author): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $author)));
        return mb_substr($source . ':' . ($name === '' ? '?' : $name), 0, 255);
    }

    /** When the deck was played or built: its effective date, else its event, else when it was posted */
    public static function dateUsed(array $deck): ?string
    {
        foreach ([$deck['effectiveDate'] ?? null, $deck['tournament']['date'] ?? null, $deck['postedDate'] ?? null] as $date) {
            if (is_string($date) && self::partialDate($date) !== null) {
                return $date;
            }
        }
        return null;
    }

    /** The card pool date: the end of the Date Used's period (Ketura, 2026-11-02) */
    public static function effectiveDate(array $deck): ?DateTime
    {
        $used = self::dateUsed($deck);
        return $used === null ? null : DeckManager::endOfPeriod($used);
    }

    /** The day a Wayback Machine snapshot was taken, from its URL */
    public static function archiveDate(?string $url): ?string
    {
        if ($url && preg_match('#web\.archive\.org/web/(\d{4})(\d{2})(\d{2})#', $url, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "$m[1]-$m[2]-$m[3]";
        }
        return null;
    }

    /** One query for every card the chunk mentions */
    private function preloadCards(array $decks): void
    {
        $codes = [];
        foreach ($decks as $deck) {
            foreach (array_merge([$deck['ringBearer'] ?? null, $deck['ring'] ?? null, $deck['map'] ?? null], $deck['sites'] ?? [], array_column($deck['drawDeck'] ?? [], 'card')) as $code) {
                if (is_string($code) && $code !== '') {
                    $codes[$code] = true;
                }
            }
        }
        if ($codes) {
            foreach ($this->em->getRepository(Card::class)->findBy(['code' => array_keys($codes)]) as $card) {
                $this->cards[$card->getCode()] = $card;
            }
            foreach (array_keys($codes) as $code) {
                $this->cards[$code] ??= null;
            }
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
