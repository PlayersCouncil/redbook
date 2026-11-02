<?php

namespace App\Services\Gemp;

/**
 * The result of Gemp's POST /deck/statsJson, as stored on a deck (decision 0019).
 * Gemp's problems are English sentences, sometimes several to an entry separated
 * by newlines; they are split into one problem per line here.
 */
class DeckValidation
{
    /**
     * @param string[] $problems
     * @param string[]|null $problemsWithErrata null when the format has no PC errata
     */
    public function __construct(
        public readonly string $formatCode,
        public readonly string $formatName,
        public readonly bool $valid,
        public readonly array $problems,
        public readonly ?bool $validWithErrata,
        public readonly ?array $problemsWithErrata,
        public readonly int $freePeoplesCount,
        public readonly int $shadowCount,
    ) {
    }

    public static function fromGemp(array $json): self
    {
        return new self(
            (string) ($json['formatCode'] ?? ''),
            (string) ($json['formatName'] ?? ''),
            (bool) ($json['valid'] ?? false),
            self::splitProblems($json['problems'] ?? []),
            isset($json['validWithErrata']) ? (bool) $json['validWithErrata'] : null,
            isset($json['problemsWithErrata']) ? self::splitProblems($json['problemsWithErrata']) : null,
            (int) ($json['freePeoplesCount'] ?? 0),
            (int) ($json['shadowCount'] ?? 0),
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['format_code'], $data['format_name'], $data['valid'], $data['problems'],
            $data['valid_with_errata'], $data['problems_with_errata'],
            $data['free_peoples_count'], $data['shadow_count'],
        );
    }

    /**
     * Valid in the format as Red Book shows it: for a PC errata format Gemp also
     * validates with the errata applied, and a deck that is legal once its cards
     * are converted counts (decision 0021: decks keep raw blueprint IDs).
     */
    public function isValid(): bool
    {
        return $this->valid || $this->validWithErrata === true;
    }

    /** The problems that matter: with errata applied when the format has errata */
    public function getRelevantProblems(): array
    {
        return $this->problemsWithErrata ?? $this->problems;
    }

    public function toArray(): array
    {
        return [
            'format_code' => $this->formatCode,
            'format_name' => $this->formatName,
            'valid' => $this->valid,
            'problems' => $this->problems,
            'valid_with_errata' => $this->validWithErrata,
            'problems_with_errata' => $this->problemsWithErrata,
            'free_peoples_count' => $this->freePeoplesCount,
            'shadow_count' => $this->shadowCount,
        ];
    }

    /** @return string[] */
    private static function splitProblems(array $problems): array
    {
        $lines = [];
        foreach ($problems as $problem) {
            foreach (preg_split('/\R/', (string) $problem) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }
}
