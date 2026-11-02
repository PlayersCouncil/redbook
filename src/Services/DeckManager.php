<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\Deckchange;
use App\Entity\DeckInterface;
use App\Entity\Decklist;
use App\Entity\Deckslot;
use App\Entity\Faction;
use App\Entity\Format;
use App\Entity\UserInterface;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Creating and saving decks (phase3/03).
 */
class DeckManager
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected DeckValidator $validator,
        protected Diff $diff,
    ) {
    }

    /** @see UserInterface::getDecks() */
    public function getByUser(UserInterface $user): Collection
    {
        return $user->getDecks();
    }

    /** The "_" placeholder faction every deck carries (decision 0018) */
    public function getPlaceholderFaction(): Faction
    {
        return $this->em->getRepository(Faction::class)->findOneBy(['code' => '_'])
            ?? throw new InvalidArgumentException('The "_" faction is missing; run the fixtures');
    }

    /** The format a new deck targets when none is chosen: the first hall format */
    public function getDefaultFormat(): ?string
    {
        $formats = $this->em->getRepository(Format::class)->findBy(['hall' => true, 'retired' => false], ['position' => 'ASC'], 1);
        return $formats ? $formats[0]->getCode() : null;
    }

    /**
     * Saves a deck.
     *
     * @param array{name?: string, description?: string, tags?: string|array|null, content: array<string, int>,
     *     target_format?: string, effective_date?: DateTimeInterface|string|null, decklist_id?: int|null,
     *     shared?: bool} $data
     * @param DeckInterface|null $sourceDeck the deck as it was saved before, to record the change in its history
     */
    public function save(UserInterface $user, DeckInterface $deck, array $data, ?DeckInterface $sourceDeck = null): void
    {
        if (!empty($data['decklist_id'])) {
            $decklist = $this->em->getRepository(Decklist::class)->find($data['decklist_id']);
            if ($decklist) {
                $deck->setParent($decklist);
            }
        }

        $deck->setUser($user);
        $deck->setName(trim((string) ($data['name'] ?? '')) ?: 'Untitled deck');
        $deck->setDescriptionMd($data['description'] ?? $deck->getDescriptionMd() ?? '');
        $deck->setFaction($this->getPlaceholderFaction());
        $deck->setTargetFormat($data['target_format'] ?? ($deck->getTargetFormat() ?: (string) $this->getDefaultFormat()));
        $effective = $data['effective_date'] ?? null;
        if (is_string($effective) && $effective !== '') {
            $effective = DateTime::createFromFormat('!Y-m-d', $effective) ?: null;
        }
        $deck->setEffectiveDate($effective instanceof DateTimeInterface ? $effective : ($deck->getId() ? $deck->getEffectiveDate() : new DateTime('today')));
        if (isset($data['shared'])) {
            $deck->setShared((bool) $data['shared']);
        }
        $deck->setMinorVersion($deck->getMinorVersion() + 1);

        $tags = $data['tags'] ?? [];
        if (is_string($tags)) {
            $tags = preg_split('/\s+/', trim($tags), -1, PREG_SPLIT_NO_EMPTY);
        }
        $deck->setTags(implode(' ', array_unique(array_values($tags ?? []))));

        $content = $this->normalizeContent($data['content']);
        [$cards] = $this->loadCards($content);

        $latestPack = null;
        foreach ($cards as $card) {
            $pack = $card->getPack();
            if (!$latestPack || ($pack->getDateRelease() && $latestPack->getDateRelease() < $pack->getDateRelease())) {
                $latestPack = $pack;
            }
        }
        $deck->setLastPack($latestPack);
        $this->em->persist($deck);

        if ($sourceDeck) {
            // record the change since the last save in the deck's history
            [$listings] = $this->diff->diffContents([$content, $sourceDeck->getSlots()->getContent()]);
            foreach ($this->getUnsavedChanges($deck) as $change) {
                $this->em->remove($change);
            }
            if (count($listings[0]) || count($listings[1])) {
                $change = new Deckchange();
                $change->setDeck($deck);
                $change->setVariation(json_encode($listings));
                $change->setIsSaved(true);
                $change->setVersion($deck->getVersion());
                $this->em->persist($change);
            }
            $deck->setMajorVersion($sourceDeck->getMajorVersion());
            $deck->setMinorVersion($sourceDeck->getMinorVersion());
        }

        $this->setSlots($deck, $content, $cards);
        $this->validator->validateDeck($deck);
    }

    /** Replaces a deck's slots with the content map */
    public function setSlots(DeckInterface $deck, array $content, ?array $cards = null): void
    {
        $cards ??= $this->loadCards($content)[0];
        foreach ($deck->getSlots() as $slot) {
            $deck->removeSlot($slot);
            $this->em->remove($slot);
        }
        foreach ($content as $key => $quantity) {
            [$code, $isRingBearer] = DeckContent::parseKey((string) $key);
            if (!isset($cards[$code]) || $quantity < 1) {
                continue;
            }
            $slot = new Deckslot();
            $slot->setCard($cards[$code]);
            $slot->setQuantity($quantity);
            $slot->setRole($isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::roleForType($cards[$code]->getType()->getCode()));
            $slot->setDeck($deck);
            $deck->addSlot($slot);
        }
    }

    /** Positive integer quantities keyed by content key; a single Ring-bearer */
    public function normalizeContent(array $content): array
    {
        $clean = [];
        $ringBearer = false;
        foreach ($content as $key => $quantity) {
            $key = trim((string) $key);
            $quantity = (int) $quantity;
            if ($quantity < 1 || !preg_match('/^(rb:)?\d+_\d+$/', $key)) {
                continue;
            }
            if (str_starts_with($key, DeckContent::RING_BEARER_PREFIX)) {
                if ($ringBearer) {
                    continue;
                }
                $ringBearer = true;
                $quantity = 1;
            }
            $clean[$key] = min($quantity, 99);
        }
        return $clean;
    }

    /** @return array{0: array<string, Card>, 1: string[]} cards by blueprint ID, and unknown IDs */
    public function loadCards(array $content): array
    {
        $codes = array_unique(array_map(fn ($key) => DeckContent::parseKey((string) $key)[0], array_keys($content)));
        $cards = [];
        foreach ($this->em->getRepository(Card::class)->findBy(['code' => $codes]) as $card) {
            $cards[$card->getCode()] = $card;
        }
        return [$cards, array_values(array_diff($codes, array_keys($cards)))];
    }

    /** A new deck for the user, not yet saved */
    public function create(UserInterface $user, string $name, string $format, array $content = []): Deck
    {
        $deck = new Deck();
        $deck->setUuid(\Ramsey\Uuid\Uuid::uuid4());
        $this->save($user, $deck, ['name' => $name, 'target_format' => $format, 'content' => $content]);
        return $deck;
    }

    /** A copy of another deck for the user */
    public function copy(UserInterface $user, DeckInterface $source, ?string $name = null): Deck
    {
        $deck = new Deck();
        $deck->setUuid(\Ramsey\Uuid\Uuid::uuid4());
        $deck->setParentDeck($source);
        $this->save($user, $deck, [
            'name' => $name ?? $source->getName() . ' (copy)',
            'description' => $source->getDescriptionMd(),
            'tags' => $source->getTags(),
            'content' => $source->getSlots()->getContent(),
            'target_format' => $source->getTargetFormat(),
            'effective_date' => $source->getEffectiveDate(),
        ]);
        return $deck;
    }

    public function revert(DeckInterface $deck): void
    {
        foreach ($this->getUnsavedChanges($deck) as $change) {
            $this->em->remove($change);
        }
        $this->em->flush();
    }

    public function getUnsavedChanges(DeckInterface $deck): array
    {
        return $this->em->getRepository(Deckchange::class)->findBy(['deck' => $deck, 'isSaved' => false]);
    }
}
