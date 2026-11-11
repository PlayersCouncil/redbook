<?php

namespace App\Entity;

use App\Services\Gemp\DeckValidation;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a LOTR deck and decklist add to ThronesDB's (phase3/03): the target
 * format (decision 0020), the effective date (0021) and Gemp's validation (0019).
 */
trait LotrDeckTrait
{
    /** Gemp format code */
    #[ORM\Column(name: 'target_format', type: 'string', length: 50, nullable: false)]
    protected string $targetFormat = '';

    /** When the deck was, or is meant to be, played; picks the card versions shown (0021) */
    #[ORM\Column(name: 'effective_date', type: 'date', nullable: false)]
    protected DateTimeInterface $effectiveDate;

    /** The last result of Gemp's validation (DeckValidation::toArray) */
    #[ORM\Column(name: 'validation', type: 'json', nullable: true)]
    protected ?array $validation = null;

    /** When validation was fetched; null means Gemp couldn't be reached */
    #[ORM\Column(name: 'validated_at', type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $validatedAt = null;

    /**
     * The starting fellowships (Ketura, 2026-11-03; several, each named, since 2026-11-11):
     * groups of draw-deck companions, [{name, codes}] (App\Classes\StartingGroups). Optional
     * bookkeeping; Gemp doesn't see it.
     */
    #[ORM\Column(name: 'starting_fellowship', type: 'json', nullable: true)]
    protected ?array $starting = null;

    /**
     * Starting notes (Ketura, 2026-11-03): the bid, as written ("2", "2-3", "1 if X,
     * otherwise 4"; 100 characters since 2026-11-11)
     */
    #[ORM\Column(name: 'starting_bid', type: 'string', length: 100, nullable: true)]
    protected ?string $startingBid = null;

    /** Starting notes: what to bid, who to start, the first turn (Markdown) */
    #[ORM\Column(name: 'starting_notes', type: 'text', nullable: true)]
    protected ?string $startingNotes = null;

    /**
     * Card annotations (Ketura, 2026-11-03): notes on single cards, in the deck or not
     * (rejected options, cards to watch for): [{code, text}]
     */
    #[ORM\Column(name: 'annotations', type: 'json', nullable: true)]
    protected ?array $annotations = null;

    /** complete, incomplete or unvalidated, from validation; a column so lists can filter on it (0023) */
    #[ORM\Column(name: 'completeness', type: 'string', length: 12, nullable: false, options: ['default' => 'unvalidated'])]
    protected string $completeness = 'unvalidated';

    public function getTargetFormat(): string
    {
        return $this->targetFormat;
    }

    public function setTargetFormat(string $targetFormat): void
    {
        $this->targetFormat = $targetFormat;
    }

    public function getEffectiveDate(): DateTimeInterface
    {
        return $this->effectiveDate ?? new DateTime('today');
    }

    public function setEffectiveDate(DateTimeInterface $effectiveDate): void
    {
        $this->effectiveDate = $effectiveDate;
    }

    public function getValidation(): ?DeckValidation
    {
        return $this->validation === null ? null : DeckValidation::fromArray($this->validation);
    }

    public function getValidationArray(): ?array
    {
        return $this->validation;
    }

    /** Store Gemp's result, or null when Gemp couldn't be reached */
    public function setValidation(?DeckValidation $validation): void
    {
        $this->validation = $validation?->toArray();
        $this->validatedAt = $validation === null ? null : new DateTime();
        $this->completeness = $validation === null
            ? self::COMPLETENESS_UNVALIDATED
            : ($validation->isValid() ? self::COMPLETENESS_COMPLETE : self::COMPLETENESS_INCOMPLETE);
    }

    /** @return string[] every companion that starts in any of its groups, once (the S in the deck list) */
    public function getStarting(): array
    {
        return \App\Classes\StartingGroups::union($this->starting ?? []);
    }

    /** One group of companions, unnamed: as the starting fellowship was before there could be more */
    public function setStarting(?array $codes): void
    {
        $this->setStartingGroups($codes ? [['name' => '', 'codes' => $codes]] : []);
    }

    /** @return array<array{name: string, codes: string[]}> */
    public function getStartingGroups(): array
    {
        return \App\Classes\StartingGroups::normalize($this->starting ?? []);
    }

    public function setStartingGroups(?array $groups): void
    {
        $this->starting = \App\Classes\StartingGroups::normalize($groups ?? []) ?: null;
    }

    public function getStartingBid(): ?string
    {
        return $this->startingBid;
    }

    public function setStartingBid(?string $bid): void
    {
        $bid = $bid === null ? null : mb_substr(trim(strip_tags($bid)), 0, 100);
        $this->startingBid = $bid === '' ? null : $bid;
    }

    public function getStartingNotes(): ?string
    {
        return $this->startingNotes;
    }

    public function setStartingNotes(?string $notes): void
    {
        $notes = $notes === null ? null : mb_substr(trim($notes), 0, 20000);
        $this->startingNotes = $notes === '' ? null : $notes;
    }

    /** @return array<array{code: string, text: string}> */
    public function getAnnotations(): array
    {
        return $this->annotations ?? [];
    }

    /** @param array<array{code?: mixed, text?: mixed}>|null $annotations */
    public function setAnnotations(?array $annotations): void
    {
        $clean = [];
        foreach ($annotations ?? [] as $row) {
            $code = (string) ($row['code'] ?? '');
            if (!preg_match('/^\d+_\d+$/', $code) || isset($clean[$code])) {
                continue;
            }
            $clean[$code] = ['code' => $code, 'text' => mb_substr(trim((string) ($row['text'] ?? '')), 0, 5000)];
        }
        $this->annotations = $clean ? array_values($clean) : null;
    }

    public function getValidatedAt(): ?DateTimeInterface
    {
        return $this->validatedAt;
    }

    /** complete, incomplete or unvalidated (decision 0023) */
    public function getCompleteness(): string
    {
        return $this->completeness;
    }

    /** The validation fields for JSON and the deckbuilder */
    protected function lotrDeckJson(): array
    {
        return [
            'target_format' => $this->targetFormat,
            'effective_date' => $this->getEffectiveDate()->format('Y-m-d'),
            'validation' => $this->validation,
            'validated_at' => $this->validatedAt?->format('c'),
            'completeness' => $this->getCompleteness(),
            // every companion that starts in any group, as before there were groups; and the groups
            'starting' => $this->getStarting(),
            'starting_groups' => $this->getStartingGroups(),
            'starting_bid' => $this->startingBid,
            'starting_notes' => $this->startingNotes,
            'annotations' => $this->getAnnotations(),
        ];
    }
}
