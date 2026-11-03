<?php

namespace App\Controller;

use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckSource;
use App\Repository\FormatRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The historians' review queue (Ketura, 2026-11-02): archive decks waiting to be
 * checked against their source on one side, the ones already checked (and by whom)
 * on the other, both narrowed by the same filters. A deck opens in the deckbuilder
 * in a new tab, where Save & verify settles it.
 */
#[IsGranted('ROLE_HISTORIAN')]
class HistorianController extends AbstractController
{
    public const PER_PAGE = 40;

    /** The filters, as query parameters */
    private const FILTERS = ['q', 'author', 'source', 'event', 'format', 'from', 'to', 'attention', 'flag', 'reviewer'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormatRepository $formats,
    ) {
    }

    #[Route(path: '/historian/review', name: 'historian_review', methods: ['GET'])]
    public function reviewAction(Request $request, TranslatorInterface $translator): Response
    {
        $filters = [];
        foreach (self::FILTERS as $name) {
            $filters[$name] = trim((string) $request->query->get($name, ''));
        }

        $unverified = $this->page(PublishedDeck::REVIEW_UNVERIFIED, $filters, max(1, $request->query->getInt('upage', 1)));
        $verified = $this->page(PublishedDeck::REVIEW_VERIFIED, $filters, max(1, $request->query->getInt('vpage', 1)));

        return $this->render('Historian/review.html.twig', [
            'pagetitle' => $translator->trans('historian.queue.title'),
            'filters' => $filters,
            'unverified' => $unverified,
            'verified' => $verified,
            'sources' => $this->em->createQuery('SELECT DISTINCT s.source FROM ' . PublishedDeckSource::class . ' s ORDER BY s.source')->getSingleColumnResult(),
            'formats' => $this->formats->findBy([], ['position' => 'ASC']),
            'flag_codes' => ['submitted', 'parse', 'unresolved', 'no_format', 'format_guessed', 'problems'],
        ]);
    }

    /** @return array{items: PublishedDeck[], total: int, page: int, pages: int} */
    private function page(string $status, array $filters, int $page): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('d', 'u', 's', 'p', 'r')
            ->from(PublishedDeck::class, 'd')
            ->join('d.user', 'u')
            ->leftJoin('d.source', 's')
            ->leftJoin('d.parent', 'p')
            ->leftJoin('d.reviewedBy', 'r')
            ->where('d.reviewStatus = :status')
            ->setParameter('status', $status);
        $this->applyFilters($qb, $filters, $status);
        if ($status === PublishedDeck::REVIEW_VERIFIED) {
            $qb->orderBy('d.reviewedAt', 'DESC');
        } else {
            // the oldest decks first, as the archive runs
            $qb->orderBy('d.effectiveDate', 'ASC')->addOrderBy('d.id', 'ASC');
        }
        $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE);

        $paginator = new Paginator($qb->getQuery(), false);
        $total = count($paginator);
        return [
            'items' => iterator_to_array($paginator),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    private function applyFilters(QueryBuilder $qb, array $f, string $status): void
    {
        $like = fn (string $value) => '%' . addcslashes($value, '%_\\') . '%';
        if ($f['q'] !== '') {
            $qb->andWhere('d.name LIKE :q')->setParameter('q', $like($f['q']));
        }
        if ($f['author'] !== '') {
            // the historic account's name, or the name the source gave
            $qb->andWhere('(u.displayName LIKE :author OR u.username LIKE :author OR s.legacyAuthor LIKE :author)')
                ->setParameter('author', $like($f['author']));
        }
        if ($f['source'] !== '') {
            $qb->andWhere('s.source = :source')->setParameter('source', $f['source']);
        }
        if ($f['event'] !== '') {
            $qb->andWhere('EXISTS (SELECT pl.id FROM App\Entity\PublishedDeckPlay pl WHERE pl.decklist = d AND pl.eventName LIKE :event)')
                ->setParameter('event', $like($f['event']));
        }
        if ($f['format'] === '-') {
            $qb->andWhere("d.targetFormat = ''");
        } elseif ($f['format'] !== '') {
            $qb->andWhere('d.targetFormat = :format')->setParameter('format', $f['format']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $name => $op) {
            $date = $f[$name] !== '' ? DateTime::createFromFormat('!Y-m-d', $f[$name]) : false;
            if ($date) {
                $qb->andWhere("d.effectiveDate $op :$name")->setParameter($name, $date);
            }
        }
        if ($f['attention'] === 'yes') {
            $qb->andWhere('d.reviewFlags IS NOT NULL');
        } elseif ($f['attention'] === 'no') {
            $qb->andWhere('d.reviewFlags IS NULL');
        }
        if ($f['flag'] !== '' && preg_match('/^[a-z_]+$/', $f['flag'])) {
            $qb->andWhere('d.reviewFlags LIKE :flag')->setParameter('flag', '%"code":"' . $f['flag'] . '"%');
        }
        if ($f['reviewer'] !== '' && $status === PublishedDeck::REVIEW_VERIFIED) {
            $qb->andWhere('(r.displayName LIKE :reviewer OR r.username LIKE :reviewer)')->setParameter('reviewer', $like($f['reviewer']));
        }
    }
}
