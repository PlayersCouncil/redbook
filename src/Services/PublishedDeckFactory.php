<?php

namespace App\Services;

use App\Entity\Deck;
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
        $decklist->setVersion($deck->getVersion());
        $decklist->setNameCanonical($this->texts->slugify($name) . '-' . $decklist->getVersion());
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($this->texts->markdown($descriptionMd));
        $decklist->setDateCreation(new DateTime());
        $decklist->setDateUpdate(new DateTime());
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
        foreach ($deck->getSlots() as $slot) {
            $decklistslot = new PublishedDeckSlot();
            $decklistslot->setQuantity($slot->getQuantity());
            $decklistslot->setCard($slot->getCard());
            $decklistslot->setRole($slot->getRole());
            $decklistslot->setDecklist($decklist);
            $decklist->getSlots()->add($decklistslot);
        }
        $decklist->updateCultures();
        if ($deck instanceof Deck) {
            $this->copyNotes($deck, $decklist);
        }
        if (count($deck->getChildren())) {
            $decklist->setPrecedent($deck->getChildren()[0]);
        } elseif ($deck->getParent()) {
            $decklist->setPrecedent($deck->getParent());
        }
        $decklist->setParent($deck);
        $deck->setMinorVersion(1);

        // the snapshot taken at publication (decision 0019)
        $this->validator->validateDeck($decklist);

        return $decklist;
    }
}
