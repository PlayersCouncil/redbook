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
 * Batches of schema version 1 and 2 are read (Ketura, 2026-11-04; phase3/09). A v2 deck
 * names its reporter (who posted it) and, when someone else built it, its author; the deck
 * is filed under the author when there is one. Its plays[] replace v1's tournament, and it
 * may carry a starting setup and card annotations, which become the deck's own starting
 * fellowship, bid, starting notes and card notes. normalize() turns either version into
 * the shape the rest of the importer reads.
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

    /** The fields a deck must have, by schema version */
    private const REQUIRED = [
        1 => ['sourceId', 'sourceUrl', 'title', 'legacyAuthor', 'postedDate', 'drawDeck', 'rawDecklist'],
        2 => ['sourceId', 'sourceUrl', 'title', 'reporter', 'postedDate', 'drawDeck', 'rawDecklist'],
        // v3 adds ratings and reception, and "any:" cards (Ketura, 2026-11-08); a v2 reader reads it
        3 => ['sourceId', 'sourceUrl', 'title', 'reporter', 'postedDate', 'drawDeck', 'rawDecklist'],
    ];

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
        private readonly CardVersions $versions,
    ) {
    }

    /**
     * A card field as a blueprint ID: an "any:" title (v3: any:Frodo, any:The One Ring) as
     * its first printing, the version a generic slot keeps (Ketura, 2026-11-08)
     */
    private function ref(string $field): ?string
    {
        if (!str_starts_with($field, DeckContent::ANY_PREFIX)) {
            return $field;
        }
        $code = $this->versions->byTitle(substr($field, strlen(DeckContent::ANY_PREFIX)));
        // as the deck's content keeps it, once the format is known
        return $code !== null ? ($this->anyChosen[$code] ?? $code) : null;
    }

    /** @var array<string, string> an "any:" card's first pick => the version kept for the deck's format */
    private array $anyChosen = [];

    /**
     * @return array{created: int, updated: int, skipped: int, kept: int, guessed: int, flagged: int, accounts: int, problems: string[]}
     */
    public function import(array $batch, bool $validate = true, bool $dryRun = false, ?callable $progress = null): array
    {
        $this->checkBatch($batch);
        $source = $batch['source'];
        $version = (int) $batch['schemaVersion'];
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
                $missing = array_diff(self::REQUIRED[$version], array_keys($deck));
                if ($missing) {
                    $report['skipped']++;
                    $report['problems'][] = sprintf('deck %d (%s): missing %s', $i, $deck['sourceId'] ?? '?', implode(', ', $missing));
                    continue;
                }
                $deck = self::normalize($deck, $version);
                $existing = $this->em->getRepository(PublishedDeckSource::class)->findOneBy(['source' => $source, 'sourceId' => (string) $deck['sourceId']]);
                $decklist = $existing?->getDecklist() ?? new PublishedDeck();
                if ($decklist->isVerified()) {
                    // a historian has checked it against the source; the scrape doesn't get to undo that
                    $report['kept']++;
                    continue;
                }
                $owner = $this->historicAccount($source, $deck['owner'], $deck['ownerDisplay'], $dryRun, $report);
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
        $source->setLegacyAuthor($deck['ownerDisplay']);
        $source->setPeople($deck['people']);
        $source->setLanguage($deck['language']);
        $source->setStartingQuote($deck['starting']['quote'] ?? null);
        $source->setPostedDateText((string) $deck['postedDate']);
        $source->setPostedDate(self::partialDate($deck['postedDate']));
        $source->setFormatAsWritten($deck['formatAsWritten'] ?? null);
        $source->setRawDecklist((string) $deck['rawDecklist']);
        $source->setRatings($deck['ratings'] ?? null);
        $source->setComments($deck['comments'] ?? []);
        $source->setBatch($generator);
        $source->setParse(is_array($deck['parse'] ?? null) ? $deck['parse'] : null);
        $source->setRelated(is_array($deck['related'] ?? null) ? $deck['related'] : null);

        // the content, with anything unknown kept as an unresolved line
        $unresolved = array_map([self::class, 'withCandidates'], $deck['unresolved'] ?? []);
        $content = [];
        $add = function (?string $code, string $section, int $count = 1, bool $ringBearer = false) use (&$content, &$unresolved) {
            if ($code === null || $code === '') {
                return;
            }
            // any version (v3): the first printing of the title, kept generic
            $generic = str_starts_with($code, DeckContent::ANY_PREFIX);
            $resolved = $this->ref($code);
            if ($resolved === null || !$this->card($resolved)) {
                $unresolved[] = ['line' => $code, 'count' => $count, 'section' => $section, 'reason' => $generic ? 'no card with this title' : 'blueprint not in Red Book'];
                return;
            }
            $key = DeckContent::key($resolved, $ringBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::ROLE_DRAW, $generic);
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

        // an "any:" card kept as its lowest non-zero set's version legal in the format (Ketura, 2026-11-08)
        $formatEntity = $format ? $this->em->getRepository(Format::class)->findOneBy(['code' => $format]) : null;
        $this->anyChosen = [];
        $remapped = [];
        foreach ($content as $key => $quantity) {
            [$code, $isRingBearer, $generic] = DeckContent::parseKey((string) $key);
            if ($generic) {
                $chosen = $this->versions->exemplar($code, $formatEntity);
                $this->anyChosen[$code] = $chosen;
                $code = $chosen;
                $this->card($code);
            }
            $key = DeckContent::key($code, $isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::ROLE_DRAW, $generic);
            $remapped[$key] = ($remapped[$key] ?? 0) + $quantity;
        }
        $content = $remapped;

        $name = mb_substr(trim((string) $deck['title']) ?: 'Untitled', 0, 60);
        $description = self::toMarkdown((string) ($deck['description'] ?? ''), $deck['descriptionFormat'] ?? 'text');
        $posted = self::partialDate($deck['postedDate']) ?? new DateTime('today');
        $effective = self::effectiveDate($deck) ?? $posted;

        $decklist->setName($name);
        // each archive deck is a line of one (Ketura, 2026-11-07)
        if ($decklist->getLine() === null) {
            $decklist->setLine(new \App\Entity\DecklistLine());
            $decklist->setVersion(1);
        }
        $decklist->setNameCanonical($this->texts->slugify($name) . '-' . $decklist->getVersion());
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
            [$code, $isRingBearer, $generic] = DeckContent::parseKey($key);
            $card = $this->card($code);
            $slot = new PublishedDeckSlot();
            $slot->setCard($card);
            $slot->setQuantity($quantity);
            $slot->setGeneric($generic);
            $slot->setRole($isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::roleForType($card->getType()->getCode()));
            $slot->setDecklist($decklist);
            $decklist->getSlots()->add($slot);
            if (!$latestPack || ($card->getPack()->getDateRelease() && $latestPack->getDateRelease() < $card->getPack()->getDateRelease())) {
                $latestPack = $card->getPack();
            }
        }
        $decklist->setLastPack($latestPack);
        $decklist->updateCultures();
        $decklist->updateSizes();

        foreach ($decklist->getPlays()->toArray() as $play) {
            $decklist->removePlay($play);
        }
        foreach ($deck['plays'] as $row) {
            $play = new PublishedDeckPlay();
            $play->setEventName(mb_substr($row['eventName'], 0, 255));
            $play->setEventDate(self::partialDate($row['date'] ?? null));
            // what the post claims; an event's own results win once they're linked (phase3/09)
            $play->setPlacing(isset($row['placingAsWritten']) ? mb_substr((string) $row['placingAsWritten'], 0, 50) : null);
            $play->setPlayerCount(isset($row['players']) ? (int) $row['players'] : null);
            $play->setLocation(isset($row['location']) ? mb_substr((string) $row['location'], 0, 255) : null);
            $play->setPilot($row['pilot']);
            $play->setEventRef(isset($row['eventId']) ? (string) $row['eventId'] : null);
            $play->setPartRef(isset($row['partId']) ? (string) $row['partId'] : null);
            $play->setResultPlayer(isset($row['resultPlayer']) ? (string) $row['resultPlayer'] : null);
            $play->setFormat($format);
            $decklist->addPlay($play);
        }

        // the author's starting setup and card notes, as the source states them (v2)
        $this->fillNotes($decklist, $deck, $content);

        if ($validate && $format !== null) {
            $this->validator->validateDeck($decklist);
        } else {
            $decklist->setValidation(null);
        }

        // for the review queue: what a historian should look at
        $flags = [];
        foreach (array_merge($deck['parse']['issues'] ?? [], $deck['parseIssues'] ?? []) as $issue) {
            if (preg_match('/^\d+ of \d+ lines? unresolved$/', (string) $issue)) {
                continue; // the unresolved flag below says the same
            }
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
     * The starting fellowship (companions in the draw deck only), bid and starting notes,
     * and the card annotations: the deck's own Notes, so the reader shows them and a
     * historian sees them in the deckbuilder. Several notes on one card are joined.
     */
    private function fillNotes(PublishedDeck|Deck $deck, array $source, array $content): void
    {
        $starting = $source['starting'] ?? [];
        $fellowship = array_map(fn ($code) => is_string($code) ? $this->ref($code) : null, (array) ($starting['fellowship'] ?? []));
        $deck->setStarting(array_values(array_filter($fellowship, fn ($code) => $code !== null && (isset($content[$code]) || isset($content[DeckContent::ANY_PREFIX . $code])))));
        $bid = trim((string) ($starting['bid'] ?? ''));
        $deck->setStartingBid($bid === '' ? null : mb_substr($bid, 0, 30));
        $notes = self::toMarkdown((string) ($starting['notes'] ?? ''), $starting['notesFormat'] ?? 'text');
        $deck->setStartingNotes($notes === '' ? null : $notes);

        $notes = [];
        foreach ($source['annotations'] ?? [] as $row) {
            $code = is_array($row) ? (string) $this->ref((string) ($row['card'] ?? '')) : '';
            $text = self::toMarkdown((string) ($row['text'] ?? ''), $row['format'] ?? 'text');
            if ($code === '' || $text === '') {
                continue;
            }
            $notes[$code] = isset($notes[$code]) ? $notes[$code] . "\n\n" . $text : $text;
        }
        $deck->setAnnotations(array_map(fn ($code, $text) => ['code' => $code, 'text' => $text], array_keys($notes), $notes));
    }

    /**
     * A deck of either schema version in the importer's terms (Ketura, 2026-11-04):
     * - owner, ownerDisplay: whom it's filed under, the author when the source names one
     *   (v2), else the reporter (v2) or legacyAuthor (v1); the name keys the historic account
     * - people: v2's {reporter, author}; plays: v2's plays[], or v1's tournament as one play
     * - comments: their authors as names; language: the deck text's, en unless given
     */
    public static function normalize(array $deck, int $version): array
    {
        $name = fn (mixed $person) => trim((string) (is_array($person) ? ($person['name'] ?? '') : ($person ?? '')));
        $display = fn (mixed $person) => trim((string) (is_array($person) ? ($person['displayName'] ?? $person['name'] ?? '') : ($person ?? '')));
        if ($version === 1) {
            $deck['owner'] = $name($deck['legacyAuthor']);
            $deck['ownerDisplay'] = $deck['owner'];
            $deck['people'] = null;
            $tournament = $deck['tournament'] ?? [];
            $deck['plays'] = empty($tournament['name']) ? [] : [[
                'eventName' => (string) $tournament['name'],
                'date' => $tournament['date'] ?? null,
                'placingAsWritten' => $tournament['placing'] ?? null,
                'players' => $tournament['players'] ?? null,
                'location' => $tournament['location'] ?? null,
            ]];
        } else {
            $owner = $name($deck['author'] ?? null) !== '' ? $deck['author'] : $deck['reporter'];
            $deck['owner'] = $name($owner);
            $deck['ownerDisplay'] = $display($owner) ?: $deck['owner'];
            $deck['people'] = ['reporter' => $deck['reporter'], 'author' => $deck['author'] ?? null];
            $plays = [];
            foreach ($deck['plays'] ?? [] as $play) {
                $event = trim((string) ($play['eventName'] ?? $play['eventId'] ?? ''));
                if ($event !== '') {
                    $plays[] = ['eventName' => $event] + $play;
                }
            }
            $deck['plays'] = $plays;
        }
        foreach ($deck['plays'] as &$play) {
            $play['pilot'] = isset($play['pilot']) ? ($display($play['pilot']) ?: null) : null;
        }
        unset($play);
        $deck['comments'] = array_map(fn ($c) => is_array($c) && is_array($c['author'] ?? null) ? ['author' => $display($c['author'])] + $c : $c, $deck['comments'] ?? []);
        $deck['language'] = is_string($deck['language'] ?? null) && $deck['language'] !== '' ? $deck['language'] : 'en';
        return $deck;
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
            $private->setOrigin(['type' => 'archive']);
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
        // the decklist carries its own copy (Ketura, 2026-11-07)
        $decklist->setTags($private->getTags() ?: null);
        $private->setValidation($decklist->getValidation());
        $private->setStarting($decklist->getStarting());
        $private->setStartingBid($decklist->getStartingBid());
        $private->setStartingNotes($decklist->getStartingNotes());
        $private->setAnnotations($decklist->getAnnotations());

        $play = $deck['plays'][0] ?? [];
        $private->setHistoric(DeckManager::normalizeHistoric([
            'author' => $deck['ownerDisplay'],
            'source' => $sourceCode,
            'source_url' => $deck['sourceUrl'],
            'archive_url' => $deck['archiveUrl'] ?? '',
            'event' => $play['eventName'] ?? '',
            'placing' => $play['placingAsWritten'] ?? '',
            'players' => $play['players'] ?? '',
            'date_used' => self::dateUsed($deck) ?? '',
            'date_posted' => $deck['postedDate'],
            'date_retrieved' => self::archiveDate($deck['archiveUrl'] ?? null) ?? '',
            'format_as_written' => $deck['formatAsWritten'] ?? '',
            'notes' => '',
        ]));

        $content = [];
        $cards = [];
        foreach ($decklist->getSlots() as $slot) {
            $content[$slot->getContentKey()] = $slot->getQuantity();
            $cards[$slot->getCard()->getCode()] = $slot->getCard();
        }
        $this->deckManager->setSlots($private, $content, $cards);
        return $private;
    }

    /**
     * The historic account for an author in a source, made on first sight. Follows
     * merges, so a claimed author's decks go to the person who claimed them.
     */
    private function historicAccount(string $source, string $author, string $displayName, bool $dryRun, array &$report): User
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
            $user->setDisplayName(mb_substr(trim($displayName), 0, 100) ?: 'Unknown author');
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
        foreach ([$deck['effectiveDate'] ?? null, $deck['plays'][0]['date'] ?? null, $deck['tournament']['date'] ?? null, $deck['postedDate'] ?? null] as $date) {
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

    /**
     * An unmatched line with the cards it might be, for the deckbuilder's one-click
     * fixes: the batch's own candidates, or the blueprint IDs an "ambiguous: 1_306
     * Pippin, Friend to Frodo, 1_307 ..." reason names.
     */
    public static function withCandidates(mixed $line): mixed
    {
        if (!is_array($line)) {
            return $line;
        }
        $candidates = $line['candidates'] ?? null;
        if (!is_array($candidates)) {
            preg_match_all('/(?<![\w])(\d+_\d+)(?![\w])/', (string) ($line['reason'] ?? ''), $m);
            $candidates = $m[1];
        }
        $candidates = array_values(array_unique(array_filter(array_map('strval', $candidates), fn ($c) => preg_match('/^\d+_\d+$/', $c))));
        if ($candidates) {
            $line['candidates'] = $candidates;
        } else {
            unset($line['candidates']);
        }
        return $line;
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
        if (!isset(self::REQUIRED[$batch['schemaVersion'] ?? null])) {
            throw new InvalidArgumentException(sprintf('Not a seed-deck batch of schemaVersion %s', implode(' or ', array_keys(self::REQUIRED))));
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

    /**
     * The source's write-up as Markdown text. HTML and BBCode are reduced to plain
     * text first. Line breaks are kept (Markdown would otherwise join the lines of a
     * paragraph), and lines Markdown would read as headings are escaped: a leading
     * "#", or a row of dashes or equals signs, which makes the line above a heading.
     */
    public static function toMarkdown(string $text, string $format): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($format === 'html') {
            // newlines in HTML source are just spaces; only the tags break lines
            $text = preg_replace('/\s+/', ' ', $text);
            $text = preg_replace('#\s*(</p>|</div>|</h\d>|</ul>|</ol>|</blockquote>)\s*#i', "\n\n", $text);
            $text = preg_replace('#<br\s*/?>|</li>#i', "\n", $text);
            $text = preg_replace('#<li[^>]*>#i', '- ', $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif ($format === 'bbcode') {
            $text = preg_replace('#\[\*\]#', '- ', $text);
            $text = preg_replace('#\[(/?)(b|i|u|s|url|img|quote|code|size|color|list|\*)[^\]]*\]#i', '', $text);
        }
        $lines = array_map(function (string $line): string {
            $line = rtrim($line);
            if (preg_match('/^\s*(#|([-=*_])(\s*\2){2,}\s*$)/', $line)) {
                $line = '\\' . ltrim($line);
            }
            return $line;
        }, explode("\n", $text));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        // a hard break (two spaces) at the end of each line that isn't the end of a paragraph
        return preg_replace('/(?<=\S)\n(?=[^\n])/', "  \n", $text);
    }
}
