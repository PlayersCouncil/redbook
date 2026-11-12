<?php

namespace App\Controller;

use App\Entity\Pack;
use App\Repository\FormatRepository;
use App\Services\CardsData;
use App\Services\FormatDefinitions;
use App\Services\FormatTimeline;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Formats page (Ketura, 2026-11-11; it replaced the list of phase3/04): two tabs, Formats
 * (Gemp's Help > Format Definitions, every hall format with its rules and card lists) and
 * Timeline (the rules, rulings and releases over the years; to come). A format's own address
 * goes to its place on the page; one that isn't in Gemp's hall keeps a page of its own.
 */
class FormatsController extends AbstractController
{
    public function __construct(
        private readonly FormatRepository $formats,
        private readonly EntityManagerInterface $em,
        private readonly FormatDefinitions $definitions,
    ) {
    }

    #[Route(path: '/formats', name: 'formats', methods: ['GET'])]
    public function listAction(int $cacheExpiration, TranslatorInterface $translator): Response
    {
        return $this->render('Formats/index.html.twig', [
            'pagetitle' => $translator->trans('nav.formats'),
            'pagedescription' => $translator->trans('formats.description'),
            'formats' => $this->definitions->all(),
            'tab' => 'formats',
        ], $this->cached($cacheExpiration));
    }

    #[Route(path: '/formats/timeline', name: 'formats_timeline', methods: ['GET'], priority: 1)]
    public function timelineAction(int $cacheExpiration, TranslatorInterface $translator, FormatTimeline $timeline): Response
    {
        $dataset = $timeline->get();
        return $this->render('Formats/timeline.html.twig', [
            'pagetitle' => $translator->trans('formats.tabs.timeline'),
            'pagedescription' => $translator->trans('formats.timeline.description'),
            'tab' => 'timeline',
            // the data's own address changes with each import, so it can be kept for long
            'data_url' => $dataset ? $this->generateUrl('formats_timeline_data', ['v' => substr(md5((string) $dataset->getGeneratedAt() . $dataset->getDateImport()->format('c')), 0, 10)]) : null,
            'generated' => $dataset?->getGeneratedAt(),
        ], $this->cached($cacheExpiration));
    }

    /** The timeline's data, as imported (FormatTimeline) */
    #[Route(path: '/formats/timeline.json', name: 'formats_timeline_data', methods: ['GET'], priority: 2)]
    public function timelineDataAction(FormatTimeline $timeline): Response
    {
        $dataset = $timeline->get() ?? throw new NotFoundHttpException('No format timeline has been imported.');
        $response = new Response($dataset->getData(), Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->setPublic();
        $response->setMaxAge(86400);
        return $response;
    }

    #[Route(path: '/formats/{code}', name: 'format', methods: ['GET'], requirements: ['code' => '[\w-]+'])]
    public function viewAction(string $code, int $cacheExpiration, CardsData $cardsData): Response
    {
        $format = $this->formats->findOneByCode($code);
        if ($format === null) {
            throw new NotFoundHttpException("No format $code");
        }
        // one on the Formats page: its place there
        if ($format->isHall() && !$format->isRetired() && is_array($format->getRaw()['hall'] ?? null)) {
            return $this->redirect($this->generateUrl('formats') . '#' . FormatDefinitions::anchor($format->getCode()), Response::HTTP_MOVED_PERMANENTLY);
        }

        $packs = $this->em->getRepository(Pack::class)->findBy(['code' => $format->getSets()], ['position' => 'ASC']);
        $lists = [];
        foreach (FormatDefinitions::LISTS as $name) {
            $codes = $format->getList($name);
            if (!$codes) {
                continue;
            }
            // Gemp lists alternate printings (promos, foils) by their own blueprint ID; Red Book
            // has only the card they reprint, shown with the printing's ID
            $cards = $this->definitions->resolveCards($codes);
            $rows = [];
            foreach ($codes as $id) {
                $card = $cards[$id] ?? null;
                if ($card === null) {
                    $lists[$name . '_unknown'][] = $id;
                    continue;
                }
                $rows[] = ['card' => $card, 'as' => $card->getCode() === $id ? null : $id];
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
        ], $this->cached($cacheExpiration));
    }

    private function cached(int $cacheExpiration): Response
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);
        return $response;
    }
}
