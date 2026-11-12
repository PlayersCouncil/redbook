<?php

namespace App\Services;

use App\Entity\Dataset;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * The format timeline (Ketura, 2026-11-11): every set, card, list, rotation, rules change,
 * errata and ruling that changed what a deck could be, the official formats' spans, Gemp's
 * best fit for each era and the micro-formats. Made by decipher-map's ru_formats.py as
 * format-timeline.json (_planning/timeline/format-timeline-data.md describes it); imported
 * whole, from wherever it is kept, and shown by the Formats page's Timeline tab.
 */
class FormatTimeline
{
    public const FILE = 'format-timeline.json';
    public const SCHEMA = 'format-timeline/';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Imports a format-timeline.json, in place of the one there was
     * @return array{schema: string, generatedAt: ?string, events: int, formats: int, microformats: int}
     */
    public function import(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("$path not found");
        }
        $json = (string) file_get_contents($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $schema = is_array($data) ? (string) ($data['schema'] ?? '') : '';
        if (!str_starts_with($schema, self::SCHEMA)) {
            throw new RuntimeException("$path isn't a format timeline (its schema is '$schema')");
        }
        foreach (['range', 'eventKinds', 'columns', 'events', 'formats', 'gemp', 'microformats', 'primary', 'sets'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException("$path has no '$key'");
            }
        }
        $dataset = $this->em->find(Dataset::class, Dataset::FORMAT_TIMELINE);
        if ($dataset === null) {
            $dataset = new Dataset(Dataset::FORMAT_TIMELINE);
            $this->em->persist($dataset);
        }
        // kept as it came, but without the whitespace of a pretty-printed file
        $dataset->replace(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $schema, $data['generatedAt'] ?? null);
        $this->em->flush();
        return [
            'schema' => $schema,
            'generatedAt' => $data['generatedAt'] ?? null,
            'events' => count($data['events']),
            'formats' => count($data['formats']),
            'microformats' => count($data['microformats']),
        ];
    }

    /** The timeline as imported, or null before one has been */
    public function get(): ?Dataset
    {
        return $this->em->find(Dataset::class, Dataset::FORMAT_TIMELINE);
    }
}
