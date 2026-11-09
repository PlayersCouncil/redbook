<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Classes\SlotCollectionInterface;
use App\Entity\Card;
use App\Entity\CommonDeckInterface;
use App\Entity\Format;
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
        private readonly CardVersions $versions,
    ) {
    }

    /**
     * The Gemp deck string, a generic slot as its stand-in in the format (Ketura, 2026-11-08):
     * Gemp knows only particular cards
     */
    public function gempString(SlotCollectionInterface $slots, ?string $formatCode): string
    {
        $format = $this->format($formatCode);
        $triples = [];
        foreach ($slots as $slot) {
            $code = $slot->getCard()->getCode();
            $triples[] = [$slot->isGeneric() ? $this->versions->standIn($code, $format) : $code, $slot->getRole(), (int) $slot->getQuantity()];
        }
        return DeckContent::toGempString($triples);
    }

    private function format(?string $code): ?Format
    {
        return $code ? $this->em->getRepository(Format::class)->findOneBy(['code' => $code]) : null;
    }

    /** "Any" cards no other version of which is legal in the format: they can't be any version there */
    private function genericProblems(array $content, ?string $formatCode): array
    {
        $format = $this->format($formatCode);
        $problems = [];
        foreach (array_keys($content) as $key) {
            [$code, , $generic] = DeckContent::parseKey((string) $key);
            if ($generic && $format && !$this->versions->canBeGeneric($code, $format)) {
                $card = $this->em->getRepository(Card::class)->findOneBy(['code' => $code]);
                $problems[] = sprintf('%s (any): no other version is legal in %s', $card?->getName() ?? $code, $format->getName());
            }
        }
        return $problems;
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
        return $this->withProblems($this->validateGempString($this->gempString($slots, $format), $format), $this->genericProblems($slots->getContent(), $format));
    }

    private function withProblems(?DeckValidation $validation, array $problems): ?DeckValidation
    {
        if ($validation === null || !$problems) {
            return $validation;
        }
        return new DeckValidation(
            $validation->formatCode, $validation->formatName, false,
            array_merge($problems, $validation->problems),
            $validation->validWithErrata === null ? null : false,
            $validation->problemsWithErrata,
            $validation->freePeoplesCount, $validation->shadowCount,
        );
    }

    /**
     * For the deckbuilder's live checklist: a content map as the browser holds it.
     * Unknown blueprint IDs are left out, and listed as problems.
     */
    public function validateContent(array $content, string $format): ?DeckValidation
    {
        [$triples, $unknown] = $this->resolve($content, $format);
        $validation = $this->validateGempString(DeckContent::toGempString($triples), $format);
        return $this->withProblems($validation, array_merge(array_map(fn ($code) => "Unknown card $code", $unknown), $this->genericProblems($content, $format)));
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
    public function resolve(array $content, ?string $formatCode = null): array
    {
        $format = $this->format($formatCode);
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
            [$code, $isRingBearer, $generic] = DeckContent::parseKey((string) $key);
            if (!isset($cards[$code])) {
                $unknown[] = $code;
                continue;
            }
            $role = $isRingBearer ? DeckContent::ROLE_RING_BEARER : DeckContent::roleForType($cards[$code]->getType()->getCode());
            // any version: its stand-in in the format
            $triples[] = [$generic ? $this->versions->standIn($code, $format) : $code, $role, max(0, (int) $quantity)];
        }
        return [$triples, $unknown];
    }
}
