<?php

namespace App\Services;

use App\Entity\DeckInterface;
use App\Entity\Decklist;
use App\Entity\Decklistslot;
use DateTime;
use DateTimeInterface;

/**
 * Publishing a deck as a decklist. Publishing is never blocked by validation
 * (decision 0023): the decklist keeps a fresh validation snapshot and shows its
 * problems.
 */
class DecklistFactory
{
    public function __construct(
        protected DeckValidator $validator,
        protected Texts $texts,
    ) {
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
    ): Decklist {
        // increasing deck version
        $deck->setMinorVersion(0);
        $deck->setMajorVersion($deck->getMajorVersion() + 1);

        $name = mb_substr(trim((string) $name) ?: $deck->getName(), 0, 60);
        $descriptionMd = $descriptionMd ?: $deck->getDescriptionMd();

        $decklist = new Decklist();
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
            $decklistslot = new Decklistslot();
            $decklistslot->setQuantity($slot->getQuantity());
            $decklistslot->setCard($slot->getCard());
            $decklistslot->setRole($slot->getRole());
            $decklistslot->setDecklist($decklist);
            $decklist->getSlots()->add($decklistslot);
        }
        $decklist->updateCultures();
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
