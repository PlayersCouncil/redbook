<?php

namespace App\Services;

use App\Entity\Card;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckSlot;
use App\Entity\PublishedDeckPlay;
use App\Entity\Card as CardEntity;
use App\Entity\CommonDeckInterface;
use App\Entity\Culture;
use App\Entity\PublishedDeckCulture;
use Doctrine\ORM\QueryBuilder;
use App\Entity\UserInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * The job of this class is to find and return decklists
 * @author alsciende
 */
class PublishedDeckManager
{
    protected $page = 1;
    protected $start = 0;
    protected $limit = 30;
    protected $maxcount = 0;

    protected EntityManagerInterface $doctrine;

    protected RequestStack $request_stack;

    protected RouterInterface $router;

    protected LoggerInterface $logger;

    /**
     * @param EntityManagerInterface $doctrine
     * @param RequestStack $request_stack
     * @param RouterInterface $router
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityManagerInterface $doctrine,
        RequestStack $request_stack,
        RouterInterface $router,
        LoggerInterface $logger
    ) {
        $this->doctrine = $doctrine;
        $this->request_stack = $request_stack;
        $this->router = $router;
        $this->logger = $logger;
    }

    /** The page's sorts (Ketura, 2026-11-05): the first is the default */
    public const SORTS = ['new', 'old', 'rated', 'name', 'comments'];

    /** Which decks a list shows by what they hold: whole decks, and fragments of either side */
    public const KINDS = ['whole', CardEntity::SIDE_FREE_PEOPLES, CardEntity::SIDE_SHADOW];

    /**
     * The decklists page's search and filters, from the query string (Ketura, 2026-11-05: one
     * page, filters on top, results below; the old tabs are presets of these). Anything left
     * out is not filtered on.
     *   name, author, event     text in the deck's name, its author, an event it was played at
     *   cards[]=<code>          has each of these cards
     *   format=<gemp code>      target format
     *   culture[]=<code>        has cards of each culture
     *   from=YYYY, to=YYYY      effective date (the year the deck was played)
     *   kind[]=whole|free_peoples|shadow  whole decks and fragments; all three when left out
     *   legal=1                 only decks Gemp accepts as they are
     *   played=1                only decks played at an event
     *   mine=1, favorites=1     the user's own decks, or their favourites
     *   sort=new|old|rated|name|comments
     * The old search's parameters still work: sort=date|popularity|likes|reputation, half=.
     */
    public function getFilters(): array
    {
        $query = $this->request_stack->getCurrentRequest()?->query;
        if ($query === null) {
            return [];
        }
        $all = $query->all();
        $text = fn (string $key) => mb_substr(trim(strip_tags((string) (is_array($all[$key] ?? null) ? '' : ($all[$key] ?? '')))), 0, 100);
        $codes = fn (string $key) => array_values(array_unique(array_filter(array_map(
            fn ($c) => preg_replace('/[^\w-]/', '', (string) $c),
            (array) ($all[$key] ?? [])
        ))));

        $kind = array_values(array_intersect(self::KINDS, $codes('kind')));
        $half = $query->get('half');
        if (!$kind && in_array($half, [CardEntity::SIDE_FREE_PEOPLES, CardEntity::SIDE_SHADOW], true)) {
            $kind = [$half];
        }
        if (count($kind) === count(self::KINDS)) {
            $kind = [];
        }
        $sort = (string) $query->get('sort');
        $sort = ['date' => 'new', 'popularity' => 'rated', 'likes' => 'rated', 'reputation' => 'rated'][$sort] ?? $sort;

        return array_filter([
            'name' => $text('name'),
            'author' => $text('author'),
            'event' => $text('event'),
            'cards' => $codes('cards'),
            'format' => preg_replace('/[^\w-]/', '', (string) $query->get('format')),
            'culture' => $codes('culture'),
            'from' => (int) $query->get('from') ?: null,
            'to' => (int) $query->get('to') ?: null,
            'kind' => $kind,
            'legal' => (bool) $query->get('legal'),
            'played' => (bool) $query->get('played'),
            'mine' => (bool) $query->get('mine'),
            'favorites' => (bool) $query->get('favorites'),
            'sort' => in_array($sort, self::SORTS, true) && $sort !== self::SORTS[0] ? $sort : null,
        ]);
    }

    /**
     * The decklists page: the filters above, for the given user (mine and favorites need one;
     * without, they find nothing).
     */
    public function browse(array $filters, ?UserInterface $user): Paginator|ArrayCollection
    {
        if ((!empty($filters['mine']) || !empty($filters['favorites'])) && $user === null) {
            return $this->getEmptyList();
        }
        $qb = $this->doctrine->createQueryBuilder()->select('d')->from(PublishedDeck::class, 'd');

        if (!empty($filters['name'])) {
            $qb->andWhere('d.name LIKE :name')->setParameter('name', '%' . addcslashes($filters['name'], '%_') . '%');
        }
        if (!empty($filters['author'])) {
            // a registered author, or the source's author or poster of an archived deck
            $qb->join('d.user', 'au')->leftJoin('d.source', 'asrc')
                ->andWhere('au.username LIKE :author OR au.displayName LIKE :author OR asrc.legacyAuthor LIKE :author')
                ->setParameter('author', '%' . addcslashes($filters['author'], '%_') . '%');
        }
        if (!empty($filters['event'])) {
            $qb->andWhere('EXISTS (SELECT ep FROM ' . PublishedDeckPlay::class . ' ep WHERE ep.decklist = d AND ep.eventName LIKE :event)')
                ->setParameter('event', '%' . addcslashes($filters['event'], '%_') . '%');
        }
        if (!empty($filters['played'])) {
            $qb->andWhere('EXISTS (SELECT pp FROM ' . PublishedDeckPlay::class . ' pp WHERE pp.decklist = d)');
        }
        // a card in any printing: one with the same name and subtitle
        foreach ($filters['cards'] ?? [] as $i => $code) {
            $qb->andWhere("EXISTS (SELECT s$i FROM " . PublishedDeckSlot::class . " s$i JOIN s$i.card c$i, " . CardEntity::class . " k$i"
                . " WHERE s$i.decklist = d AND k$i.code = :card$i AND c$i.name = k$i.name"
                . " AND (c$i.subtitle = k$i.subtitle OR (c$i.subtitle IS NULL AND k$i.subtitle IS NULL)))")
                ->setParameter("card$i", $code);
        }
        if (!empty($filters['format'])) {
            $qb->andWhere('d.targetFormat = :format')->setParameter('format', $filters['format']);
        }
        foreach ($filters['culture'] ?? [] as $i => $code) {
            $qb->andWhere("EXISTS (SELECT dc$i FROM " . PublishedDeckCulture::class . " dc$i JOIN dc$i.culture cu$i WHERE dc$i.decklist = d AND cu$i.code = :culture$i)")
                ->setParameter("culture$i", $code);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('d.effectiveDate >= :from')->setParameter('from', $filters['from'] . '-01-01');
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('d.effectiveDate <= :to')->setParameter('to', $filters['to'] . '-12-31');
        }
        if (!empty($filters['kind'])) {
            $kinds = [
                'whole' => '(d.nbFreePeoples > 0 AND d.nbShadow > 0)',
                CardEntity::SIDE_FREE_PEOPLES => '(d.nbFreePeoples > 0 AND d.nbShadow = 0)',
                CardEntity::SIDE_SHADOW => '(d.nbFreePeoples = 0 AND d.nbShadow > 0)',
            ];
            $qb->andWhere(implode(' OR ', array_map(fn ($k) => $kinds[$k], $filters['kind'])));
        }
        if (!empty($filters['legal'])) {
            $qb->andWhere('d.completeness = :complete')->setParameter('complete', CommonDeckInterface::COMPLETENESS_COMPLETE);
        }
        if (!empty($filters['mine'])) {
            $qb->andWhere('d.user = :me')->setParameter('me', $user);
        }
        if (!empty($filters['favorites'])) {
            $qb->andWhere(':fan MEMBER OF d.favorites')->setParameter('fan', $user);
        }

        switch ($filters['sort'] ?? self::SORTS[0]) {
            case 'old':
                $qb->orderBy('d.dateCreation', 'ASC');
                break;
            case 'rated':
                $qb->addSelect(PublishedDeck::SCORE_DQL . ' AS HIDDEN score')->orderBy('score', 'DESC')->addOrderBy('d.dateCreation', 'DESC');
                break;
            case 'name':
                $qb->orderBy('d.name', 'ASC');
                break;
            case 'comments':
                $qb->orderBy('d.nbComments', 'DESC')->addOrderBy('d.dateCreation', 'DESC');
                break;
            default:
                $qb->orderBy('d.dateCreation', 'DESC');
        }
        $qb->addOrderBy('d.id', 'DESC');
        $qb->setFirstResult($this->start)->setMaxResults($this->limit);

        $paginator = $this->getPaginator($qb->getQuery());
        $this->preload(iterator_to_array($paginator));
        return $paginator;
    }

    /** Loads what a row shows (cultures, author, plays) for a page of decks in three queries */
    private function preload(array $decklists): void
    {
        if (!$decklists) {
            return;
        }
        foreach (['d.cultures c LEFT JOIN c.culture cu' => 'c, cu', 'd.user u' => 'u', 'd.plays p' => 'p'] as $join => $select) {
            $this->doctrine->createQuery('SELECT d, ' . $select . ' FROM ' . PublishedDeck::class . ' d LEFT JOIN ' . $join . ' WHERE d IN (:decks)')
                ->setParameter('decks', $decklists)
                ->getResult();
        }
    }

    /** The formats published decks target, with how many: [code => count], most used first */
    public function countFormats(): array
    {
        $rows = $this->doctrine->createQuery('SELECT d.targetFormat AS code, COUNT(d.id) AS n FROM ' . PublishedDeck::class
            . " d WHERE d.targetFormat IS NOT NULL AND d.targetFormat <> '' GROUP BY d.targetFormat ORDER BY n DESC")
            ->getArrayResult();
        return array_column($rows, 'n', 'code');
    }

    /** The home page's lists: whole decks Gemp accepts (decision 0023) */
    private function applyHomeFilters(QueryBuilder $qb): void
    {
        $qb->andWhere('d.half IS NULL');
        $qb->andWhere('d.completeness = :complete')->setParameter('complete', CommonDeckInterface::COMPLETENESS_COMPLETE);
    }

    public function setLimit($limit)
    {
        $this->limit = $limit;
    }

    public function setPage($page)
    {
        $this->page = max($page, 1);
        $this->start = ($this->page - 1) * $this->limit;
    }

    public function getMaxCount()
    {
        return $this->maxcount;
    }

    /**
     * creates the basic query builder and initializes it
     */
    private function getQueryBuilder()
    {
        $qb = $this->doctrine->createQueryBuilder();
        $qb->select('d');
        $qb->from(PublishedDeck::class, 'd');
        $this->applyHomeFilters($qb);
        $qb->setFirstResult($this->start);
        $qb->setMaxResults($this->limit);
        $qb->distinct();
        return $qb;
    }

    /**
     * creates the paginator around the query
     * @param Query $query
     */
    private function getPaginator(Query $query)
    {
        $paginator = new Paginator($query, $fetchJoinCollection = false);
        $this->maxcount = $paginator->count();
        return $paginator;
    }

    public function getEmptyList()
    {
        $this->maxcount = 0;
        return new ArrayCollection([]);
    }

    public function findDecklistsByPopularity()
    {
        $qb = $this->getQueryBuilder();
        $qb->addSelect(
            '(1+' . PublishedDeck::SCORE_DQL . ')/(1+POWER(DATE_DIFF(CURRENT_TIMESTAMP(), d.dateCreation), 2)) AS HIDDEN popularity'
        );
        $qb->orderBy('popularity', 'DESC');
        return $this->getPaginator($qb->getQuery());
    }

    /**
     * @param bool $ignoreEmptyDescriptions
     * @return Paginator
     */
    public function findDecklistsByAge($ignoreEmptyDescriptions = false)
    {
        $qb = $this->getQueryBuilder();
        $qb->orderBy('d.dateCreation', 'DESC');
        return $this->getPaginator($qb->getQuery());
    }

    public function getNumberOfPages()
    {
        return intval(ceil($this->maxcount / $this->limit));
    }

    public function getAllPages()
    {
        $request = $this->request_stack->getCurrentRequest();
        $route = $request->get('_route');
        $route_params = $request->get('_route_params');
        $query = $request->query->all();

        $params = $query + $route_params;

        $number_of_pages = $this->getNumberOfPages();
        $pages = [];
        for ($page = 1; $page <= $number_of_pages; $page++) {
            $pages[] = array(
                "numero" => $page,
                "url" => $this->router->generate($route, ["page" => $page] + $params),
                "current" => $page == $this->page
            );
        }
        return $pages;
    }

    public function getClosePages()
    {
        $allPages = $this->getAllPages();
        $numero_courant = $this->page - 1;
        $pages = [];
        foreach ($allPages as $numero => $page) {
            if ($numero === 0 || $numero === count($allPages) - 1 || abs($numero - $numero_courant) <= 2) {
                $pages[] = $page;
            }
        }
        return $pages;
    }

    public function getPreviousUrl()
    {
        if ($this->page === 1) {
            return null;
        }

        $request = $this->request_stack->getCurrentRequest();
        $route = $request->get('_route');
        $routeParams = $request->get('_route_params');
        $params = $request->query->all();
        $previous_page = max(1, $this->page - 1);

        return $this->router->generate($route, ["page" => $previous_page] + $routeParams + $params);
    }

    public function getNextUrl()
    {
        if ($this->page >= $this->getNumberOfPages()) {
            return null;
        }

        $request = $this->request_stack->getCurrentRequest();
        $route = $request->get('_route');
        $routeParams = $request->get('_route_params');
        $params = $request->query->all();
        $next_page = min($this->getNumberOfPages(), $this->page + 1);

        return $this->router->generate($route, ["page" => $next_page] + $routeParams + $params);
    }
}
