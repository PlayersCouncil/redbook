<?php

namespace App\Services;

use App\Entity\Deck;
use App\Entity\PublishedDeck;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a deck came to be, for the first line of its history (Ketura, 2026-11-08): "New deck
 * created", "Cloned from <deck>", "Imported from <file>". Decks made before it was recorded
 * are told from what they hold: an archive deck, a clone, a copy of a decklist.
 */
class DeckOrigin
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $router,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The line as HTML, with a link to what it came from where the viewer can see it */
    public function describe(Deck $deck, ?User $viewer = null): string
    {
        $origin = $deck->getOrigin() ?? $this->guess($deck);
        switch ($origin['type'] ?? 'new') {
            case 'import':
                return !empty($origin['source'])
                    ? $this->say('import_file', ['%source%' => '<strong>' . $this->e($origin['source']) . '</strong>'])
                    : $this->say('import_text');
            case 'clone':
                $source = !empty($origin['uuid']) ? $this->em->getRepository(Deck::class)->findOneBy(['uuid' => $origin['uuid']]) : null;
                $name = $this->e($source?->getName() ?? $origin['name'] ?? '?');
                $visible = $source !== null && !$source->isDeleted()
                    && ($source->isShared() || $source->getUser()->getId() === $viewer?->getId());
                return $this->say('clone', ['%deck%' => $visible
                    ? '<a href="' . $this->e($this->router->generate('deck_view', ['deck_uuid' => $source->getUuid()])) . '">' . $name . '</a>'
                    : '<strong>' . $name . '</strong>']);
            case 'decklist':
            case 'recreate':
                $decklist = !empty($origin['id']) ? $this->em->getRepository(PublishedDeck::class)->find($origin['id']) : null;
                $label = $this->e(($decklist?->getName() ?? $origin['name'] ?? '?') . ' v' . ($decklist?->getVersionLabel() ?? $origin['version'] ?? ''));
                $link = $decklist !== null && !$decklist->isWithdrawn()
                    ? '<a href="' . $this->e($this->router->generate('decklist_detail', ['decklist_id' => $decklist->getId(), 'decklist_name' => $decklist->getNameCanonical()])) . '">' . $label . '</a>'
                    : '<strong>' . $label . '</strong>';
                return $this->say($origin['type'], ['%decklist%' => $link]);
            case 'archive':
                return $this->say('archive');
            case 'api':
                return $this->say('api');
            default:
                return $this->say('new');
        }
    }

    /** For decks made before their origin was recorded */
    private function guess(Deck $deck): array
    {
        if ($deck->getUser()?->isHistoric()) {
            return ['type' => 'archive'];
        }
        if ($deck->getParentDeck() instanceof Deck) {
            return ['type' => 'clone', 'uuid' => (string) $deck->getParentDeck()->getUuid(), 'name' => $deck->getParentDeck()->getName()];
        }
        $decklist = $deck->getParent();
        if ($decklist instanceof PublishedDeck) {
            return ['type' => 'decklist', 'id' => $decklist->getId()];
        }
        return ['type' => 'new'];
    }

    private function say(string $key, array $params = []): string
    {
        // the parameters are HTML already; the text around them is escaped
        $text = $this->translator->trans('deckbuilder.origin.' . $key);
        $text = htmlspecialchars($text, ENT_QUOTES);
        return strtr($text, $params);
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
