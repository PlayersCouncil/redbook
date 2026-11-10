<?php

namespace App\Services;

use App\Entity\Library;
use App\Entity\LibraryRevision;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckSource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Seeded libraries (Ketura, 2026-11-09): the PC's official groupings (the starter decks, an
 * event's decks, a set's preconstructed decks) made from a manifest rather than by hand. Each
 * becomes an official, public library owned by an organization account (the PC's own, or the
 * manifest's owner),
 * which Librarians may then look after as any official library. Re-importing a manifest
 * updates each library in place, keyed by (source, id): its name, texts, tags and decks follow
 * the manifest. docs/seed-libraries.md has the format.
 */
class SeedLibraryImporter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryManager $libraries,
        private readonly OrganizationAccounts $organizations,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{created: int, updated: int, decks: int, missing: string[]}
     */
    public function import(array $manifest, bool $dryRun = false): array
    {
        // versioned like the seed deck batches, and named as they are (camelCase); the schema is
        // _planning/schema/seed-library.schema.json (Ketura, 2026-11-09)
        if (($manifest['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf('The manifest needs "schemaVersion": %d.', self::SCHEMA_VERSION));
        }
        $source = trim((string) ($manifest['source'] ?? ''));
        if ($source === '' || strlen($source) > 30) {
            throw new InvalidArgumentException('The manifest needs a "source" (up to 30 characters).');
        }
        // an organization's account (Ketura, 2026-11-09): the PC's unless the manifest names
        // another, made the first time it's needed
        $ownerName = trim((string) ($manifest['owner'] ?? ''));
        try {
            $owner = $ownerName === ''
                ? $this->organizations->official()
                : $this->organizations->get($ownerName, trim((string) ($manifest['ownerName'] ?? '')) ?: $ownerName);
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage());
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

            // the library's own organization, when the manifest names one for it (Ketura, 2026-11-09)
            $libraryOwner = $owner;
            $ownName = trim((string) ($row['owner'] ?? ''));
            if ($ownName !== '') {
                try {
                    $libraryOwner = $this->organizations->get($ownName, trim((string) ($row['ownerName'] ?? '')) ?: $ownName);
                } catch (\RuntimeException $e) {
                    throw new InvalidArgumentException($id . ': ' . $e->getMessage());
                }
            }
            $library = $this->em->getRepository(Library::class)->findOneBy(['seedSource' => $source, 'seedId' => $id]);
            if ($library === null) {
                $library = $this->libraries->create($libraryOwner, (string) ($row['name'] ?? $id));
                $library->setSeed($source, $id);
                $report['created']++;
            } else {
                // who it's filed under and its curator are kept on a re-import, unless the
                // manifest names them for it: an admin may have changed them on the site
                // (Library Info), and they aren't in its history to restore
                if ($ownName !== '') {
                    $library->setUser($libraryOwner);
                }
                $report['updated']++;
            }
            $library->setOfficial(true);
            // the credit, when someone other than the PC curated it (a champion's own library)
            if (array_key_exists('curator', $row)) {
                $curatorName = trim((string) ($row['curator'] ?? ''));
                $curator = $curatorName !== '' ? $this->em->getRepository(User::class)->findOneBy(['username' => $curatorName]) : null;
                if ($curatorName !== '' && $curator === null) {
                    $report['missing'][] = $id . ': curator ' . $curatorName;
                }
                $library->setCurator($curator instanceof User ? $curator : null);
            }
            // naive on purpose (Ketura, 2026-11-09): a re-import overwrites the library, as a new
            // revision, so a mistaken one is undone from its history
            $this->libraries->change($library, null, LibraryRevision::SEED, fn () => $this->libraries->save($library, [
                'name' => (string) ($row['name'] ?? $id),
                'description' => (string) ($row['description'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'tags' => implode(' ', (array) ($row['tags'] ?? [])),
                'entries' => $entries,
            ]));
            $this->libraries->setVisibility($library, Library::PUBLIC);
            $this->em->flush();
        }
        return $report;
    }

    /**
     * A manifest's deck: {"decklist": id}, or {"seed": {"source": ..., "sourceId": ...}} for a
     * seeded one, by the batch's source and the deck's sourceId as its batch gave them
     */
    private function decklist(mixed $deck): ?PublishedDeck
    {
        if (!is_array($deck)) {
            return null;
        }
        if (isset($deck['decklist'])) {
            $decklist = $this->em->getRepository(PublishedDeck::class)->find((int) $deck['decklist']);
        } elseif (is_array($deck['seed'] ?? null)) {
            $from = $this->em->getRepository(PublishedDeckSource::class)->findOneBy(['source' => (string) ($deck['seed']['source'] ?? ''), 'sourceId' => (string) ($deck['seed']['sourceId'] ?? '')]);
            $decklist = $from?->getDecklist();
        } else {
            return null;
        }
        return $decklist instanceof PublishedDeck && !$decklist->isWithdrawn() ? $decklist : null;
    }
}
