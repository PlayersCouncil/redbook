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
     * Companions marked as the starting fellowship (Ketura, 2026-11-03): blueprint IDs
     * of draw-deck companions. Optional bookkeeping; Gemp doesn't see it.
     */
    #[ORM\Column(name: 'starting_fellowship', type: 'json', nullable: true)]
    protected ?array $starting = null;

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

    /** @return string[] */
    public function getStarting(): array
    {
        return $this->starting ?? [];
    }

    /** @param string[]|null $codes */
    public function setStarting(?array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes ?? []), fn ($c) => preg_match('/^\d+_\d+$/', $c))));
        $this->starting = $codes ?: null;
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
            'starting' => $this->getStarting(),
        ];
    }
}
