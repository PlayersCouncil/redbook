<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Classes\SlotCollectionInterface;
use App\Entity\Card;
use App\Entity\CommonDeckInterface;
use App\Services\Gemp\DeckValidation;
use App\Services\Gemp\GempClient;
use App\Services\Gemp\GempRejectedException;
use App\Services\Gemp\GempUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deck validation, always Gemp's (decision 0019). Red Book never decides
 * legality itself: it builds the Gemp deck string and stores the answer.
 */
class DeckValidator
{
    public function __construct(
        private readonly GempClient $gemp,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Validates a deck or decklist and stores the result on it (null: Gemp unavailable) */
    public function validateDeck(CommonDeckInterface $deck): ?DeckValidation
    {
        $validation = $this->validateSlots($deck->getSlots(), $deck->getTargetFormat());
        $deck->setValidation($validation);
        return $validation;
    }

    public function validateSlots(SlotCollectionInterface $slots, string $format): ?DeckValidation
    {
        return $this->validateGempString($slots->toGempString(), $format);
    }

    /**
     * For the deckbuilder's live checklist: a content map as the browser holds it.
     * Unknown blueprint IDs are left out, and listed as problems.
     */
    public function validateContent(array $content, string $format): ?DeckValidation
    {
        [$triples, $unknown] = $this->resolve($content);
        $validation = $this->validateGempString(DeckContent::toGempString($triples), $format);
        if ($validation !== null && $unknown) {
            $validation = new DeckValidation(
                $validation->formatCode, $validation->formatName, false,
                array_merge(array_map(fn ($code) => "Unknown card $code", $unknown), $validation->problems),
                $validation->validWithErrata === null ? null : false,
                $validation->problemsWithErrata,
                $validation->freePeoplesCount, $validation->shadowCount,
            );
        }
        return $validation;
    }

    public function validateGempString(string $deck, string $format): ?DeckValidation
    {
        try {
            return $this->gemp->validate($format, $deck);
        } catch (GempUnavailableException) {
            return null;
        } catch (GempRejectedException $e) {
            // Gemp refused the deck string outright; report it like any other problem
            return new DeckValidation($format, $format, false, [$e->getMessage()], null, null, 0, 0);
        }
    }

    /**
     * @return array{0: array<array{0: string, 1: string, 2: int}>, 1: string[]} [blueprint ID, role, quantity] triples, and unknown IDs
     */
    public function resolve(array $content): array
    {
        $codes = [];
        foreach (array_keys($content) as $key) {
            $codes[] = DeckContent::parseKey((string) $key)[0];
        }
        $cards = [];
        foreach ($this->em->getRepository(Card::class)->findBy(['code' => array_unique($codes)]) as $card) {
            $cards[$card->getCode()] = $card;
        }
        $triples = [];
        $unknown = [];
        foreach ($content as $key => $quantity) {
            [$code, $isRingBearer] = DeckContent::parseKey((string) $key);
            if (!isset($cards[$code])) {
                $unknown[] = $code;
                continue;
            }
            $role = $isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::roleForType($cards[$code]->getType()->getCode());
            $triples[] = [$code, $role, max(0, (int) $quantity)];
        }
        return [$triples, $unknown];
    }
}
