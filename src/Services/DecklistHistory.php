<?php

namespace App\Services;

use App\Classes\DeckContent;
use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\DeckRevision;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckRevision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A decklist's History tab (Ketura, 2026-11-08): its line's versions, newest first, each with
 * the cards it changed from the version before; under each, the edits to its details
 * (2.0.1, 2.0.2), and a historian's corrections of an archive deck. The private revisions
 * between versions stay private.
 */
class DecklistHistory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Diff $diff,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @param PublishedDeck[] $versions the line, newest first (PublishedDeckRepository::findVersions)
     * @return list<array{decklist: PublishedDeck, current: bool, first: bool, added: list<array>, removed: list<array>, edits: list<array>}>
     */
    public function build(PublishedDeck $decklist, array $versions): array
    {
        $versions = array_values(array_filter($versions, fn (PublishedDeck $d) => !$d->isWithdrawn() || $d->getId() === $decklist->getId()));
        usort($versions, fn (PublishedDeck $a, PublishedDeck $b) => $b->getVersion() <=> $a->getVersion());

        $contents = [];
        $codes = [];
        foreach ($versions as $version) {
            $contents[$version->getId()] = $version->getSlots()->getContent();
            foreach (array_keys($contents[$version->getId()]) as $key) {
                $codes[DeckContent::parseKey((string) $key)[0]] = true;
            }
        }
        $cards = [];
        foreach ($this->em->getRepository(Card::class)->findBy(['code' => array_keys($codes)]) as $card) {
            $cards[$card->getCode()] = $card;
        }

        $rows = [];
        foreach ($versions as $i => $version) {
            $previous = $versions[$i + 1] ?? null;
            $added = $removed = [];
            if ($previous !== null) {
                [$listings] = $this->diff->diffContents([$contents[$version->getId()], $contents[$previous->getId()]]);
                $added = $this->cardRows($listings[0], $cards);
                $removed = $this->cardRows($listings[1], $cards);
            }
            $rows[] = [
                'decklist' => $version,
                'current' => $version->getId() === $decklist->getId(),
                'first' => $previous === null,
                'added' => $added,
                'removed' => $removed,
                'edits' => $this->edits($version),
            ];
        }
        return $rows;
    }

    /** @return list<array{key: string, label: string, href: string, qty: int, ring_bearer: bool}> */
    private function cardRows(array $listing, array $cards): array
    {
        $rows = [];
        foreach ($listing as $key => $qty) {
            [$code, $isRingBearer] = DeckContent::parseKey((string) $key);
            $card = $cards[$code] ?? null;
            $rows[] = [
                'code' => $code,
                'label' => $card?->getFullName() ?? $code,
                'href' => $this->router->generate('cards_zoom', ['card_code' => $code]),
                'qty' => (int) $qty,
                'ring_bearer' => $isRingBearer,
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        return $rows;
    }

    /**
     * Its detail edits, newest first, and for an archive deck the historians' corrections
     * that reached it (they keep its number)
     *
     * @return list<array{version: ?string, date: \DateTimeInterface, parts: string[], editor: ?string, correction: bool}>
     */
    private function edits(PublishedDeck $decklist): array
    {
        $author = $decklist->getUser()?->getId();
        $edits = [];
        foreach ($this->em->getRepository(PublishedDeckRevision::class)->findBy(['decklist' => $decklist]) as $edit) {
            $editor = $edit->getEditor();
            $edits[] = [
                'version' => $edit->getPatch() ? $decklist->getVersionLabel() . '.' . $edit->getPatch() : null,
                'date' => $edit->getDateReplaced(),
                // edits recorded before they were numbered changed the name or the commentary
                'parts' => $edit->getChanged() ?? ['description'],
                'editor' => $editor && $editor->getId() !== $author ? $editor->getDisplayName() : null,
                'correction' => false,
            ];
        }
        $deck = $decklist->getParent();
        if ($decklist->isArchived() && $deck instanceof Deck) {
            foreach ($this->em->getRepository(DeckRevision::class)->findBy(['deck' => $deck, 'isSaved' => true]) as $change) {
                $summary = $change->getEditSummary();
                if ($change->getEditor() === null || empty($summary['synced'])
                    || $change->getDateCreation() < $decklist->getDateCreation()) {
                    continue;
                }
                $edits[] = [
                    'version' => null,
                    'date' => $change->getDateCreation(),
                    'parts' => $summary['changed'] ?? [],
                    'editor' => $change->getEditor()->getDisplayName(),
                    'correction' => true,
                ];
            }
        }
        usort($edits, fn ($a, $b) => $b['date'] <=> $a['date']);
        return $edits;
    }
}
