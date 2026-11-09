<?php

namespace App\Services;

use App\Entity\Deck;
use App\Entity\DecklistLine;
use App\Entity\DeckInterface;
use App\Entity\PublishedDeckPlay;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckSlot;
use DateTime;
use DateTimeInterface;

/**
 * Publishing a deck as a decklist. Publishing is never blocked by validation
 * (decision 0023): the decklist keeps a fresh validation snapshot and shows its
 * problems.
 */
class PublishedDeckFactory
{
    public function __construct(
        protected DeckValidator $validator,
        protected Texts $texts,
        protected \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    /**
     * The deck's notes: its tags, bid, starting notes and card annotations. Copied when it's
     * published and when a historian verifies it; replacing a withdrawn decklist's cards
     * leaves the notes written for the decklist alone (Ketura, 2026-11-07).
     */
    private function copyNotes(Deck $deck, PublishedDeck $decklist): void
    {
        $decklist->setTags(trim((string) $deck->getTags()) ?: null);
        $decklist->setStartingBid($deck->getStartingBid());
        $decklist->setStartingNotes($deck->getStartingNotes());
        $decklist->setAnnotations($deck->getAnnotations());
    }

    /**
     * A historian's Save & verify (Ketura, 2026-11-02): the archive decklist takes the
     * corrected deck in place, keeping its id, date, comments and source. The
     * deck's Historical Deck Information becomes the decklist's play.
     */
    public function refreshFromDeck(PublishedDeck $decklist, Deck $deck): void
    {
        // an archive name can be long (a forum thread's title): kept whole, as the import did
        $name = mb_substr(trim((string) $deck->getName()) ?: 'Untitled', 0, 255);
        $descriptionMd = (string) $deck->getDescriptionMd();
        $decklist->setName($name);
        $decklist->setNameCanonical($this->texts->slugify($name) . '-' . $decklist->getVersion());
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($this->texts->markdown($descriptionMd));
        $decklist->setDateUpdate(new DateTime());
        $decklist->setSignature(md5(json_encode($deck->getSlots()->getContent())));
        $decklist->setLastPack($deck->getLastPack());
        $decklist->setTargetFormat($deck->getTargetFormat());
        $decklist->setEffectiveDate($deck->getEffectiveDate());

        $this->replaceSlots($decklist, $deck);
        $this->copyNotes($deck, $decklist);
        // the deck was validated as it was saved
        $decklist->setValidation($deck->getValidation());

        $historic = $deck->getHistoric() ?? [];
        $event = trim((string) ($historic['event'] ?? ''));
        $plays = $decklist->getPlays()->toArray();
        if ($event === '') {
            foreach ($plays as $play) {
                $decklist->removePlay($play);
            }
        } else {
            $play = $plays[0] ?? new PublishedDeckPlay();
            $play->setEventName(mb_substr($event, 0, 255));
            $date = (string) ($historic['date_used'] ?? '');
            $play->setEventDate(\App\Services\SeedDeckImporter::partialDate($date) ?? $play->getEventDate());
            $play->setPlacing(($historic['placing'] ?? '') !== '' ? mb_substr((string) $historic['placing'], 0, 50) : null);
            $play->setPlayerCount(is_numeric($historic['players'] ?? null) ? (int) $historic['players'] : null);
            $play->setFormat($deck->getTargetFormat() ?: null);
            if (!isset($plays[0])) {
                $decklist->addPlay($play);
            }
        }
    }

    /**
     * The decklist's cards, cultures and sizes, and the starting fellowship marked among
     * them, from the deck as it stands. Whether it's a half follows from the cards
     * (Ketura, 2026-11-07): one side only is that side's half.
     */
    private function replaceSlots(PublishedDeck $decklist, DeckInterface $deck): void
    {
        foreach ($decklist->getSlots()->getSlots() as $slot) {
            $decklist->getSlots()->removeElement($slot);
            $this->em->remove($slot);
        }
        foreach ($deck->getSlots() as $slot) {
            $decklistslot = new PublishedDeckSlot();
            $decklistslot->setQuantity($slot->getQuantity());
            $decklistslot->setCard($slot->getCard());
            $decklistslot->setRole($slot->getRole());
            $decklistslot->setDecklist($decklist);
            $decklist->getSlots()->add($decklistslot);
        }
        $decklist->updateCultures();
        $decklist->updateSizes();
        $kind = $decklist->getKind();
        $decklist->setHalf($kind === 'whole' ? null : $kind);
        if ($deck instanceof Deck) {
            $decklist->setStarting($deck->getStarting());
        }
    }

    /**
     * A withdrawn decklist takes its deck's cards again (Ketura, 2026-11-07), keeping its
     * id and version: the fix for a list published wrong. The format, faction, last pack
     * and date come with them, and it's validated afresh.
     */
    public function replaceCardsFromDeck(PublishedDeck $decklist, Deck $deck): void
    {
        $decklist->setSignature(md5(json_encode($deck->getSlots()->getContent())));
        $decklist->setFaction($deck->getFaction());
        $decklist->setLastPack($deck->getLastPack());
        $decklist->setTargetFormat($deck->getTargetFormat());
        $decklist->setDateUpdate(new DateTime());
        $this->replaceSlots($decklist, $deck);
        $this->validator->validateDeck($decklist);
        // the deck is that version again
        $this->numberDeck($decklist);
    }

    /**
     * A deck published as its latest decklist and not changed since (Ketura, 2026-11-08):
     * the deckbuilder asks before starting a private revision of it
     */
    public function isUnrevised(Deck $deck): ?PublishedDeck
    {
        $latest = $this->defaultPrevious($deck);
        return $latest !== null && !$latest->isWithdrawn() && (int) $deck->getMinorVersion() === 0 && (int) $deck->getMajorVersion() === (int) $latest->getVersion()
            ? $latest : null;
    }

    /**
     * Which line the decklist goes in (Ketura, 2026-11-07): the next version after
     * $previous, or the first of a new line. Sets the version and the address name with it.
     */
    public function continueLine(PublishedDeck $decklist, ?PublishedDeck $previous): void
    {
        $line = $previous?->getLine() ?? new DecklistLine();
        $decklist->setLine($line);
        $decklist->setVersion($this->em->getRepository(PublishedDeck::class)->nextVersion($line));
        $decklist->setNameCanonical($this->texts->slugify($decklist->getName()) . '-' . $decklist->getVersion());
        $this->numberDeck($decklist);
    }

    /**
     * The deck a decklist was published from takes its number as X.0 (Ketura, 2026-11-08):
     * its next revisions are X.1, X.2, private until it's published again
     */
    public function numberDeck(PublishedDeck $decklist): void
    {
        $deck = $decklist->getParent();
        if ($deck instanceof Deck && $decklist->getVersion()) {
            $deck->setMajorVersion((int) $decklist->getVersion());
            $deck->setMinorVersion(0);
        }
    }

    /**
     * The line a deck's next publication continues by default: the latest decklist
     * published from it, if it's still the author's
     */
    public function defaultPrevious(DeckInterface $deck): ?PublishedDeck
    {
        $latest = null;
        foreach ($deck->getChildren() as $child) {
            if ($child->getUser()?->getId() === $deck->getUser()?->getId()
                && ($latest === null || $child->getDateCreation() > $latest->getDateCreation())) {
                $latest = $child;
            }
        }
        return $latest;
    }

    /**
     * The release date a deck is published with (Ketura, 2026-11-07): today, or for a
     * historical deck the date it was used, which its card versions already follow
     */
    public static function releaseDate(DeckInterface $deck): DateTimeInterface
    {
        $historic = $deck instanceof Deck ? $deck->getHistoric() : null;
        return !empty($historic['date_used']) ? $deck->getEffectiveDate() : new DateTime('today');
    }

    public function createDecklistFromDeck(
        DeckInterface $deck,
        ?string $name = null,
        ?string $descriptionMd = null,
        ?DateTimeInterface $effectiveDate = null,
    ): PublishedDeck {
        $name = mb_substr(trim((string) $name) ?: $deck->getName(), 0, 60);
        $descriptionMd = $descriptionMd ?: $deck->getDescriptionMd();

        $decklist = new PublishedDeck();
        $decklist->setName($name);
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($this->texts->markdown($descriptionMd));
        $decklist->setDateCreation(new DateTime());
        $decklist->setDateUpdate(new DateTime());
        $decklist->setDatePublished(new DateTime());
        $decklist->setSignature(md5(json_encode($deck->getSlots()->getContent())));
        $decklist->setFaction($deck->getFaction());
        $decklist->setLastPack($deck->getLastPack());
        $decklist->setNbVotes(0);
        $decklist->setNbfavorites(0);
        $decklist->setNbcomments(0);
        $decklist->setUser($deck->getUser());
        $decklist->setTargetFormat($deck->getTargetFormat());
        $decklist->setEffectiveDate($effectiveDate ?? self::releaseDate($deck));
        $this->replaceSlots($decklist, $deck);
        if ($deck instanceof Deck) {
            $this->copyNotes($deck, $decklist);
        }
        // derived from: what the deck was copied from; its own earlier publications are
        // versions now, in its line (Ketura, 2026-11-07)
        if ($deck->getParent()) {
            $decklist->setPrecedent($deck->getParent());
        }
        $decklist->setParent($deck);
        // the deck becomes X.0 with it (numberDeck)
        $this->continueLine($decklist, $this->defaultPrevious($deck));

        // the snapshot taken at publication (decision 0019)
        $this->validator->validateDeck($decklist);

        return $decklist;
    }
}
