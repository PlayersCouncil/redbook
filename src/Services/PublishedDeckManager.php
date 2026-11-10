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
    protected $limit = self::DEFAULT_LIMIT;
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
        LoggerInterface $logger,
        private readonly ?CardVersions $versions = null,
    ) {
        $this->doctrine = $doctrine;
        $this->request_stack = $request_stack;
        $this->router = $router;
        $this->logger = $logger;
    }

    /** The page's sorts (Ketura, 2026-11-05): the first is the default */
    public const SORTS = ['new', 'old', 'rated', 'name', 'comments'];

    /** The page sizes the decklists page offers (Ketura, 2026-11-06); the default is 50 */
    public const LIMITS = [10, 30, 50, 80, 100];
    /** The deck searches' page size unless one is picked (Ketura, 2026-11-07) */
    public const DEFAULT_LIMIT = 10;

    /** Tags left out of the lists unless asked for (Ketura, 2026-11-08) */
    public const DEFAULT_EXCLUDED_TAGS = ['Retired', 'Shireposting'];

    /**
     * The tags a list leaves out: the filter's own, or the default ones when the filter says
     * nothing. "xtags" in the filters is set only when it differs from the default; [''] is none.
     */
    public static function excludedTags(array $filters): array
    {
        return isset($filters['xtags']) ? array_values(array_filter($filters['xtags'], 'strlen')) : self::DEFAULT_EXCLUDED_TAGS;
    }

    /** Which decks a list shows by what they hold: whole decks, and fragments of either side */
    public const KINDS = ['whole', CardEntity::SIDE_FREE_PEOPLES, CardEntity::SIDE_SHADOW];

    /**
     * The decklists page's search and filters, from the query string (Ketura, 2026-11-05: one
     * page of filters and results). Anything left out is not filtered on.
     *   name, author, event     text in the deck's name, its author, an event it was played at
     *   cards[]=<code>          has each of these cards
     *   format=<gemp code>      target format
     *   culture[]=<code>        has cards of each culture
     *   from=, to=YYYY-MM-DD    submitted on or after, on or before (Ketura, 2026-11-06)
     *   kind[]=whole|free_peoples|shadow  whole decks and fragments; all three when left out
     *   legal=1                 only decks Gemp accepts as they are
     *   played=1                only decks played at an event
     *   mine=1, favorites=1     the user's own decks, or their favourites
     *   tags[]=<tag>            tagged with each of these
     *   sort=new|old|rated|name|comments
     *   limit=10|30|50|80|100   decks a page (not a filter: Clear All keeps it)
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

        // a day as the calendar picker sends it; anything else is left out
        $date = function (string $key) use ($query): ?string {
            $value = (string) $query->get($key);
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $day && $day->format('Y-m-d') === $value ? $value : null;
        };

        $tags = fn (string $key) => array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_substr(preg_replace('/\s+/u', '', strip_tags((string) $t)), 0, 60),
            (array) ($all[$key] ?? [])
        ))));
        $xtags = null;
        if (array_key_exists('xtags', $all)) {
            $chosen = $tags('xtags');
            $same = array_map('mb_strtolower', $chosen);
            sort($same);
            $default = array_map('mb_strtolower', self::DEFAULT_EXCLUDED_TAGS);
            sort($default);
            $xtags = $same === $default ? null : ($chosen ?: ['']);
        }

        $kind = array_values(array_intersect(self::KINDS, $codes('kind')));
        if (count($kind) === count(self::KINDS)) {
            $kind = [];
        }
        $sort = (string) $query->get('sort');

        return array_filter([
            'name' => $text('name'),
            'author' => $text('author'),
            'event' => $text('event'),
            'cards' => $codes('cards'),
            'format' => preg_replace('/[^\w-]/', '', (string) $query->get('format')),
            'culture' => $codes('culture'),
            'from' => $date('from'),
            'to' => $date('to'),
            'kind' => $kind,
            'legal' => (bool) $query->get('legal'),
            'played' => (bool) $query->get('played'),
            'mine' => (bool) $query->get('mine'),
            'favorites' => (bool) $query->get('favorites'),
            'tags' => $tags('tags'),
            // left out (Ketura, 2026-11-08): kept only when not the default; [''] for none
            'xtags' => $xtags,
            'sort' => in_array($sort, self::SORTS, true) && $sort !== self::SORTS[0] ? $sort : null,
            'limit' => in_array((int) $query->get('limit'), self::LIMITS, true) && (int) $query->get('limit') !== self::DEFAULT_LIMIT ? (int) $query->get('limit') : null,
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
        // withdrawn decklists are their author's alone (Ketura, 2026-11-07)
        $qb->andWhere('d.withdrawn = false');

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
        // a card in any printing: one with the same name and subtitle; or a deck that holds any
        // version of it (Ketura, 2026-11-08)
        foreach ($filters['cards'] ?? [] as $i => $code) {
            $members = $this->versions?->members($code) ?: [$code];
            $qb->andWhere("EXISTS (SELECT s$i FROM " . PublishedDeckSlot::class . " s$i JOIN s$i.card c$i, " . CardEntity::class . " k$i"
                . " WHERE s$i.decklist = d AND k$i.code = :card$i AND ((c$i.name = k$i.name"
                . " AND (c$i.subtitle = k$i.subtitle OR (c$i.subtitle IS NULL AND k$i.subtitle IS NULL)))"
                . " OR (s$i.generic = true AND c$i.code IN (:members$i))))")
                ->setParameter("card$i", $code)
                ->setParameter("members$i", $members);
        }
        // tags left out: Retired and Shireposting unless asked otherwise (Ketura, 2026-11-08)
        foreach (self::excludedTags($filters) as $i => $tag) {
            $qb->andWhere("(d.tags IS NULL OR CONCAT(' ', d.tags, ' ') NOT LIKE :xtag$i)")->setParameter("xtag$i", '% ' . addcslashes($tag, '%_') . ' %');
        }
        // the decklist's own tags, separated by spaces (Ketura, 2026-11-07)
        if (!empty($filters['tags'])) {
            foreach ($filters['tags'] as $i => $tag) {
                $qb->andWhere("CONCAT(' ', d.tags, ' ') LIKE :tag$i")->setParameter("tag$i", '% ' . addcslashes($tag, '%_') . ' %');
            }
        }
        if (!empty($filters['format'])) {
            $qb->andWhere('d.targetFormat = :format')->setParameter('format', $filters['format']);
        }
        foreach ($filters['culture'] ?? [] as $i => $code) {
            $qb->andWhere("EXISTS (SELECT dc$i FROM " . PublishedDeckCulture::class . " dc$i JOIN dc$i.culture cu$i WHERE dc$i.decklist = d AND cu$i.code = :culture$i)")
                ->setParameter("culture$i", $code);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('d.dateCreation >= :from')->setParameter('from', new \DateTimeImmutable($filters['from']));
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('d.dateCreation < :to')->setParameter('to', (new \DateTimeImmutable($filters['to']))->modify('+1 day'));
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

    /** Loads what a row shows (cultures, author, plays, thumbnail card, the parent deck's tags) for a page of decks */
    private function preload(array $decklists): void
    {
        if (!$decklists) {
            return;
        }
        foreach (['d.cultures c LEFT JOIN c.culture cu' => 'c, cu', 'd.user u' => 'u', 'd.plays p' => 'p', 'd.thumbnailCard tc' => 'tc', 'd.parent pa' => 'pa'] as $join => $select) {
            $this->doctrine->createQuery('SELECT d, ' . $select . ' FROM ' . PublishedDeck::class . ' d LEFT JOIN ' . $join . ' WHERE d IN (:decks)')
                ->setParameter('decks', $decklists)
                ->getResult();
        }
    }

    /** The formats published decks target, with how many: [code => count], most used first */
    public function countFormats(): array
    {
        $rows = $this->doctrine->createQuery('SELECT d.targetFormat AS code, COUNT(d.id) AS n FROM ' . PublishedDeck::class
            . " d WHERE d.withdrawn = false AND d.targetFormat IS NOT NULL AND d.targetFormat <> '' GROUP BY d.targetFormat ORDER BY n DESC")
            ->getArrayResult();
        return array_column($rows, 'n', 'code');
    }

    /** The home page's lists: whole decks Gemp accepts (decision 0023), or hasn't checked yet */
    private function applyHomeFilters(QueryBuilder $qb): void
    {
        $qb->andWhere('d.withdrawn = false');
        $qb->andWhere('d.half IS NULL');
        // whole decks, and those Gemp hasn't checked yet (Ketura, 2026-11-09: a fresh import is
        // all unchecked, which left the front page empty until the hourly job got through them)
        $qb->andWhere('d.completeness IN (:shown)')->setParameter('shown', [CommonDeckInterface::COMPLETENESS_COMPLETE, CommonDeckInterface::COMPLETENESS_UNVALIDATED]);
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

    /** The total of a list counted elsewhere, for its pager (My Decks, Ketura, 2026-11-07) */
    public function setMaxCount(int $count): void
    {
        $this->maxcount = $count;
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

    /**
     * Deck of the Day (Ketura, 2026-11-09): one of the listed decks, picked by the date, the
     * same all day; never a deck from the last week. Its date is the deck's own (an archive
     * deck's, when it was played or posted), so an old deck brought in this week can be picked,
     * a deck built this week can't.
     */
    public function findDeckOfTheDay(\DateTimeInterface $day): ?PublishedDeck
    {
        $qb = $this->doctrine->createQueryBuilder()->from(PublishedDeck::class, 'd');
        $this->applyHomeFilters($qb);
        $cutoff = \DateTimeImmutable::createFromInterface($day)->setTime(0, 0)->modify('-7 days');
        $qb->andWhere('d.effectiveDate < :cutoff')->setParameter('cutoff', $cutoff);
        $count = (int) (clone $qb)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        if ($count === 0) {
            return null;
        }
        $pick = crc32('deck-of-the-day:' . $day->format('Y-m-d')) % $count;
        return $qb->select('d')->orderBy('d.id', 'ASC')->setFirstResult($pick)->setMaxResults(1)->getQuery()->getOneOrNullResult();
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

    /**
     * The decklists page's pager (Ketura, 2026-11-05): first, previous, a window of pages
     * around the current one, next and last; the urls null where they'd go nowhere.
     */
    public function getPager(int $window = 9): array
    {
        $count = $this->getNumberOfPages();
        $current = min($this->page, max(1, $count));
        $start = max(1, min($current - intdiv($window, 2), $count - $window + 1));
        $end = min($count, $start + $window - 1);
        $url = fn (int $page) => $this->pageUrl($page);
        $pages = [];
        for ($page = $start; $page <= $end; $page++) {
            $pages[] = ['numero' => $page, 'url' => $url($page), 'current' => $page === $current];
        }
        return [
            'count' => $count,
            'current' => $current,
            'pages' => $pages,
            'first' => $current > 1 ? $url(1) : null,
            'prev' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $count ? $url($current + 1) : null,
            'last' => $current < $count ? $url($count) : null,
        ];
    }

    /** This list's address on another page; page 1 leaves the parameter out */
    private function pageUrl(int $page): string
    {
        $request = $this->request_stack->getCurrentRequest();
        $params = $request->query->all();
        unset($params['page']);
        if ($page > 1) {
            $params['page'] = $page;
        }
        return \App\Twig\ListUrlExtension::unindex($this->router->generate($request->get('_route'), $params + $request->get('_route_params', [])));
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
