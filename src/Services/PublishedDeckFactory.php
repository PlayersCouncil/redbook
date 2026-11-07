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

    /** The starting fellowship, starting notes and card annotations go with the deck */
    private function copyNotes(Deck $deck, PublishedDeck $decklist): void
    {
        $decklist->setStarting($deck->getStarting());
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
        $name = mb_substr(trim((string) $deck->getName()) ?: 'Untitled', 0, 60);
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

    /** The decklist's cards, cultures, sizes and notes, from the deck as it stands */
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
        if ($deck instanceof Deck) {
            $this->copyNotes($deck, $decklist);
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
        $decklist->setEffectiveDate($deck->getEffectiveDate());
        $decklist->setDateUpdate(new DateTime());
        $this->replaceSlots($decklist, $deck);
        $this->validator->validateDeck($decklist);
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
     * @param string|null $half free_peoples or shadow for a deck half
     */
    public function createDecklistFromDeck(
        DeckInterface $deck,
        ?string $name = null,
        ?string $descriptionMd = null,
        ?string $half = null,
        ?DateTimeInterface $effectiveDate = null,
    ): PublishedDeck {
        // increasing deck version
        $deck->setMinorVersion(0);
        $deck->setMajorVersion($deck->getMajorVersion() + 1);

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
        $decklist->setEffectiveDate($effectiveDate ?? $deck->getEffectiveDate());
        $decklist->setHalf($half);
        $this->replaceSlots($decklist, $deck);
        // derived from: what the deck was copied from; its own earlier publications are
        // versions now, in its line (Ketura, 2026-11-07)
        if ($deck->getParent()) {
            $decklist->setPrecedent($deck->getParent());
        }
        $this->continueLine($decklist, $this->defaultPrevious($deck));
        $decklist->setParent($deck);
        $deck->setMinorVersion(1);

        // the snapshot taken at publication (decision 0019)
        $this->validator->validateDeck($decklist);

        return $decklist;
    }
}
