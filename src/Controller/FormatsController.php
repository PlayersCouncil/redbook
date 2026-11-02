<?php

namespace App\Controller;

use App\Entity\Card;
use App\Entity\Pack;
use App\Repository\FormatRepository;
use App\Services\CardsData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gemp's formats as Red Book copies them (phase3/04). Replaces ThronesDB's
 * restricted-lists page.
 */
class FormatsController extends AbstractController
{
    public function __construct(
        private readonly FormatRepository $formats,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(path: '/formats', name: 'formats', methods: ['GET'])]
    public function listAction(int $cacheExpiration, TranslatorInterface $translator): Response
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        return $this->render('Formats/index.html.twig', [
            'pagetitle' => $translator->trans('nav.formats'),
            'pagedescription' => $translator->trans('formats.description'),
            'formats' => $this->formats->findSelectable(),
        ], $response);
    }

    #[Route(path: '/formats/{code}', name: 'format', methods: ['GET'], requirements: ['code' => '[\w-]+'])]
    public function viewAction(string $code, int $cacheExpiration, CardsData $cardsData): Response
    {
        $format = $this->formats->findOneByCode($code);
        if ($format === null) {
            throw new NotFoundHttpException("No format $code");
        }
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $packs = $this->em->getRepository(Pack::class)->findBy(['code' => $format->getSets()], ['position' => 'ASC']);

        $lists = [];
        $alternates = null;
        foreach (['banned', 'restricted', 'limit2', 'limit3', 'valid'] as $name) {
            $codes = $format->getList($name);
            if (!$codes) {
                continue;
            }
            $cards = $this->em->getRepository(Card::class)->findBy(['code' => $codes]);
            $found = array_map(fn (Card $card) => $card->getCode(), $cards);
            // Gemp lists alternate printings (promos, foils) by their own blueprint ID;
            // Red Book only has the base card, so show it with the printing's ID
            $printings = [];
            foreach (array_diff($codes, $found) as $code) {
                $alternates ??= $this->alternatesMap();
                $base = $alternates[$code] ?? null;
                if ($base !== null) {
                    $printings[$code] = $base;
                } else {
                    $lists[$name . '_unknown'][] = $code;
                }
            }
            $rows = [];
            foreach ($cards as $card) {
                $rows[] = ['card' => $card, 'as' => null];
            }
            foreach ($printings as $code => $card) {
                $rows[] = ['card' => $card, 'as' => $code];
            }
            usort($rows, fn ($a, $b) => [$a['card']->getPack()->getPosition(), $a['card']->getPosition(), $a['as']]
                <=> [$b['card']->getPack()->getPosition(), $b['card']->getPosition(), $b['as']]);
            $lists[$name] = array_map(fn ($row) => $cardsData->getCardInfo($row['card'], false) + ['as' => $row['as']], $rows);
        }

        return $this->render('Formats/format.html.twig', [
            'pagetitle' => $format->getName(),
            'format' => $format,
            'packs' => $packs,
            'lists' => $lists,
        ], $response);
    }

    /** @return array<string, Card> alternate printing blueprint ID => base card */
    private function alternatesMap(): array
    {
        $map = [];
        $cards = $this->em->createQuery("SELECT c FROM App\\Entity\\Card c WHERE c.alternates IS NOT NULL AND c.alternates <> '[]'")->getResult();
        foreach ($cards as $card) {
            foreach ($card->getAlternates() ?? [] as $code) {
                $map[$code] = $card;
            }
        }
        return $map;
    }
}
