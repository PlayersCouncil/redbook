<?php

namespace App\Services;

use App\Entity\Library;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckSource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Seeded libraries (Ketura, 2026-11-09): the PC's official groupings (the starter decks, an
 * event's decks, a set's preconstructed decks) made from a manifest rather than by hand. Each
 * becomes an official, public library owned by the manifest's owner account (the PC's own),
 * which Librarians may then look after as any official library. Re-importing a manifest
 * updates each library in place, keyed by (source, id): its name, texts, tags and decks follow
 * the manifest. docs/seed-libraries.md has the format.
 */
class SeedLibraryImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryManager $libraries,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{created: int, updated: int, decks: int, missing: string[]}
     */
    public function import(array $manifest, bool $dryRun = false): array
    {
        $source = trim((string) ($manifest['source'] ?? ''));
        if ($source === '' || strlen($source) > 30) {
            throw new InvalidArgumentException('The manifest needs a "source" (up to 30 characters).');
        }
        $ownerName = trim((string) ($manifest['owner'] ?? ''));
        $owner = $ownerName !== '' ? $this->em->getRepository(User::class)->findOneBy(['username' => $ownerName]) : null;
        if (!$owner instanceof User) {
            throw new InvalidArgumentException(sprintf('The owner account "%s" doesn\'t exist; make it first (the PC\'s own account).', $ownerName));
        }
        if (!is_array($manifest['libraries'] ?? null)) {
            throw new InvalidArgumentException('The manifest needs a "libraries" list.');
        }

        $report = ['created' => 0, 'updated' => 0, 'decks' => 0, 'missing' => []];
        foreach ($manifest['libraries'] as $n => $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || strlen($id) > 100) {
                throw new InvalidArgumentException(sprintf('Library %d needs an "id" (up to 100 characters).', $n + 1));
            }
            $entries = [];
            foreach ((array) ($row['decks'] ?? []) as $deck) {
                $decklist = $this->decklist($deck);
                if ($decklist === null) {
                    $report['missing'][] = $id . ': ' . json_encode($deck['decklist'] ?? $deck['seed'] ?? $deck);
                    continue;
                }
                $entries[] = ['decklist' => $decklist->getId(), 'annotation' => (string) ($deck['annotation'] ?? '')];
            }
            $report['decks'] += count($entries);
            if ($dryRun) {
                continue;
            }

            $library = $this->em->getRepository(Library::class)->findOneBy(['seedSource' => $source, 'seedId' => $id]);
            if ($library === null) {
                $library = $this->libraries->create($owner, (string) ($row['name'] ?? $id));
                $library->setSeed($source, $id);
                $report['created']++;
            } else {
                $library->setUser($owner);
                $report['updated']++;
            }
            $library->setOfficial(true);
            // the credit, when someone other than the PC curated it (a champion's own library)
            $curatorName = trim((string) ($row['curator'] ?? ''));
            $curator = $curatorName !== '' ? $this->em->getRepository(User::class)->findOneBy(['username' => $curatorName]) : null;
            if ($curatorName !== '' && $curator === null) {
                $report['missing'][] = $id . ': curator ' . $curatorName;
            }
            $library->setCurator($curator instanceof User ? $curator : null);
            $this->libraries->save($library, [
                'name' => (string) ($row['name'] ?? $id),
                'description' => (string) ($row['description'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'tags' => implode(' ', (array) ($row['tags'] ?? [])),
                'entries' => $entries,
            ]);
            $this->libraries->setVisibility($library, Library::PUBLIC);
            $this->em->flush();
        }
        return $report;
    }

    /** A manifest's deck: {"decklist": id}, or {"seed": {"source": ..., "id": ...}} for a seeded one */
    private function decklist(mixed $deck): ?PublishedDeck
    {
        if (!is_array($deck)) {
            return null;
        }
        if (isset($deck['decklist'])) {
            $decklist = $this->em->getRepository(PublishedDeck::class)->find((int) $deck['decklist']);
        } elseif (is_array($deck['seed'] ?? null)) {
            $from = $this->em->getRepository(PublishedDeckSource::class)->findOneBy(['source' => (string) ($deck['seed']['source'] ?? ''), 'sourceId' => (string) ($deck['seed']['id'] ?? '')]);
            $decklist = $from?->getDecklist();
        } else {
            return null;
        }
        return $decklist instanceof PublishedDeck && !$decklist->isWithdrawn() ? $decklist : null;
    }
}
