<?php

namespace App\Services;

use App\Controller\SearchController;
use App\Entity\Card;
use App\Entity\CardInterface;
use App\Entity\Cycle;
use App\Entity\CycleInterface;
use App\Entity\Faction;
use App\Entity\Pack;
use App\Entity\Review;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CardsData
{
    protected ManagerRegistry $doctrine;

    protected RequestStack $request_stack;

    protected RouterInterface $router;

    protected TranslatorInterface $translator;

    /**
     * @param ManagerRegistry $doctrine
     * @param RequestStack $request_stack
     * @param RouterInterface $router
     * @param TranslatorInterface $translator
     */
    public function __construct(
        ManagerRegistry $doctrine,
        RequestStack $request_stack,
        RouterInterface $router,
        TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->request_stack = $request_stack;
        $this->router = $router;
        $this->translator = $translator;
    }

    /**
     * Searches for and replaces symbol tokens with markup in a given text.
     * @param string $text
     * @return string
     */
    /**
     * Culture symbols in game text ([GONDOR], [uruk-hai]) become spans the stylesheet can show as
     * icons (Phase 6); until then they read as the culture's name.
     */
    public function replaceSymbols($text)
    {
        if ($text === null || $text === '') {
            return $text;
        }
        return preg_replace_callback('/\[([A-Za-z][A-Za-z -]{1,20})\]/', function ($m) {
            $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($m[1])), '_');
            if ($code === 'wraith') {
                $code = 'ringwraith'; // the culture's code; card text writes [WRAITH]
            }
            return '<span class="culture-symbol culture-' . $code . '" title="' . htmlspecialchars($m[1]) . '">'
                . htmlspecialchars($m[1]) . '</span>';
        }, $text);
    }

    /**
     * Was GoT keyword tooltips; LOTR keyword help comes later. Returns the text unchanged.
     */
    public function addAbbrTags($text)
    {
        return $text;
    }

    public function splitInParagraphs($text)
    {
        if (empty($text)) {
            return '';
        }
        return implode(array_map(function ($l) {
            return "<p>$l</p>";
        }, preg_split('/[\r\n]+/', $text)));
    }

    public function allsetsdata()
    {
        /** @var CycleInterface[] $list_cycles */
        $list_cycles = $this->doctrine->getRepository(Cycle::class)->findBy([], ['position' => 'ASC']);
        $lines = [];

        foreach ($list_cycles as $cycle) {
            $packs = $cycle->getPacks();

            foreach ($packs as $pack) {
                if ($pack->getKind() === 'pc-errata') {
                    continue; // errata blueprints are shown on their original card, not as sets
                }
                $label = $pack->getName();
                $lines[] = array(
                    "code" => $pack->getCode(),
                    "label" => $label,
                    "available" => $pack->getDateRelease() ? true : false,
                    "url" => $this->router->generate(
                        'cards_list',
                        array('pack_code' => $pack->getCode()),
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                );
            }
        }
        return $lines;
    }

    public function allsetsdatathreaded()
    {
        /* @var CycleInterface[] $list_cycles */
        $list_cycles = $this->doctrine->getRepository(Cycle::class)->findBy(
            [],
            array("position" => "ASC")
        );
        $cycles = [];

        foreach ($list_cycles as $cycle) {
            $list_packs = $cycle->getPacks();
            $packs = [];

            /* @var PackInterface $pack */
            foreach ($list_packs as $pack) {
                if ($pack->getKind() === 'pc-errata') {
                    continue;
                }
                $label = $pack->getName();

                $packs[] = [
                    "code" => $pack->getCode(),
                    "label" => $label,
                    "available" => $pack->getDateRelease() ? true : false,
                    "url" => $this->router->generate(
                        'cards_list',
                        array('pack_code' => $pack->getCode()),
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ];
            }

            if (!$packs) {
                continue;
            } elseif (count($packs) === 1) {
                $cycles[] = $packs[0];
            } else {
                $cycles[] = [
                    "code" => $cycle->getCode(),
                    "label" => $cycle->getName(),
                    "packs" => $packs,
                    "url" => $this->router->generate(
                        'cards_cycle',
                        array('cycle_code' => $cycle->getCode()),
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ];
            }
        }

        return $cycles;
    }

    public function getPrimaryFactions()
    {
        return $this->doctrine->getRepository(Faction::class)->findPrimaries();
    }

    /**
     * @param $conditions
     * @param $sortorder
     * @param bool $forceempty
     * @return array
     * @throws Exception
     */
    public function getSearchRows($conditions, $sortorder, $forceempty = false)
    {
        $i = 0;

        // construction de la requete sql
        $repo = $this->doctrine->getRepository(Card::class);
        $qb = $repo->createQueryBuilder('c')
                ->select('c', 'p', 'y', 't', 'f')
                ->leftJoin('c.pack', 'p')
                ->leftJoin('p.cycle', 'y')
                ->leftJoin('c.type', 't')
                // The search letter is the join alias for "code" searches: f is culture, t type, r rarity
                ->leftJoin('c.culture', 'f')
                ->leftJoin('c.rarity', 'r');
        $qb2 = null;
        $qb3 = null;

        foreach ($conditions as $condition) {
            $searchCode = array_shift($condition);
            $searchName = SearchController::$searchKeys[$searchCode];
            $searchType = SearchController::$searchTypes[$searchCode];
            $operator = array_shift($condition);

            switch ($searchType) {
                case 'boolean':
                    switch ($searchCode) {
                        default:
                            if (($operator == ':' && $condition[0]) || ($operator == '!' && !$condition[0])) {
                                $qb->andWhere("(c.$searchName = 1)");
                            } else {
                                $qb->andWhere("(c.$searchName = 0)");
                            }
                            $i++;
                            break;
                    }
                    break;
                case 'integer':
                    switch ($searchCode) {
                        case 'c': // cycle
                            $or = [];
                            foreach ($condition as $arg) {
                                switch ($operator) {
                                    case ':':
                                            $or[] = "(y.position = ?$i)";
                                        break;
                                    case '!':
                                            $or[] = "(y.position != ?$i)";
                                        break;
                                }
                                $qb->setParameter($i++, $arg);
                            }
                            $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            break;
                        default:
                            $or = [];
                            foreach ($condition as $arg) {
                                switch ($operator) {
                                    case ':':
                                            $or[] = "(c.$searchName = ?$i)";
                                        break;
                                    case '!':
                                            $or[] = "(c.$searchName != ?$i)";
                                        break;
                                    case '<':
                                            $or[] = "(c.$searchName < ?$i)";
                                        break;
                                    case '>':
                                            $or[] = "(c.$searchName > ?$i)";
                                        break;
                                }
                                $qb->setParameter($i++, $arg);
                            }
                            $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            break;
                    }
                    break;
                case 'code':
                    switch ($searchCode) {
                        case 'e':
                            $or = [];
                            foreach ($condition as $arg) {
                                switch ($operator) {
                                    case ':':
                                            $or[] = "(p.code = ?$i)";
                                        break;
                                    case '!':
                                            $or[] = "(p.code != ?$i)";
                                        break;
                                    case '<':
                                        if (!isset($qb2)) {
                                            $qb2 = $this->doctrine
                                                ->getRepository(Pack::class)
                                                ->createQueryBuilder('p2');
                                            $or[] = $qb->expr()->lt(
                                                'p.dateRelease',
                                                '('
                                                . $qb2->select('p2.dateRelease')
                                                    ->where("p2.code = ?$i")
                                                    ->getDql()
                                                . ')'
                                            );
                                        }
                                        break;
                                    case '>':
                                        if (!isset($qb3)) {
                                            $qb3 = $this->doctrine
                                                ->getRepository(Pack::class)
                                                ->createQueryBuilder('p3');
                                            $or[] = $qb->expr()->gt(
                                                'p.dateRelease',
                                                '('
                                                . $qb3->select('p3.dateRelease')
                                                    ->where("p3.code = ?$i")
                                                    ->getDql()
                                                . ')'
                                            );
                                        }
                                        break;
                                }
                                $qb->setParameter($i++, $arg);
                            }
                            $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            break;
                        default: // type, culture or rarity
                            $or = [];
                            foreach ($condition as $arg) {
                                switch ($operator) {
                                    case ':':
                                            $or[] = "($searchCode.code = ?$i)";
                                        break;
                                    case '!':
                                            $or[] = "($searchCode.code != ?$i)";
                                        break;
                                }
                                $qb->setParameter($i++, $arg);
                            }
                                $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            break;
                    }
                    break;
                case 'string':
                    switch ($searchCode) {
                        case '': // name, blueprint ID or acronym
                            $or = [];
                            foreach ($condition as $arg) {
                                if (preg_match('/^\d+_\d+$/u', $arg)) {
                                    $or[] = "(c.code = ?$i)";
                                    $qb->setParameter($i++, $arg);
                                } elseif (preg_match('/^[A-Z]{2,}$/', $arg)) {
                                    $like = implode('% ', str_split($arg));
                                    $or[] = "(REPLACE(c.name, '-', ' ') like ?$i)";
                                    $qb->setParameter($i++, "$like%");
                                } else {
                                    $or[] = "(c.name like ?$i or c.subtitle like ?$i)";
                                    $qb->setParameter($i++, "%$arg%");
                                }
                            }
                            $qb->andWhere(implode(" or ", $or));
                            break;
                        case 'd': // side: fp / free_peoples / shadow / none
                            $or = [];
                            foreach ($condition as $arg) {
                                $side = match (strtolower($arg)) {
                                    'fp', 'free', 'freepeoples', 'free_peoples' => Card::SIDE_FREE_PEOPLES,
                                    'shadow', 's' => Card::SIDE_SHADOW,
                                    default => null,
                                };
                                if ($side === null) {
                                    $or[] = $operator == '!' ? "(c.side is not null)" : "(c.side is null)";
                                    continue;
                                }
                                $or[] = $operator == '!' ? "(c.side is null or c.side != ?$i)" : "(c.side = ?$i)";
                                $qb->setParameter($i++, $side);
                            }
                            $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            $i++;
                            break;
                        case 'i': // kind: card / errata
                            $or = [];
                            foreach ($condition as $arg) {
                                $or[] = $operator == '!' ? "(c.kind != ?$i)" : "(c.kind = ?$i)";
                                $qb->setParameter($i++, strtolower($arg));
                            }
                            $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            break;
                        default: // text, lore, subtypes, signet: contains
                            $or = [];
                            foreach ($condition as $arg) {
                                $or[] = $operator == '!'
                                    ? "(c.$searchName is null or c.$searchName not like ?$i)"
                                    : "(c.$searchName like ?$i)";
                                $qb->setParameter($i++, "%$arg%");
                            }
                            $qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
                            break;
                    }
            }
        }

        // One entry per card: errata blueprints only when asked for (i:errata)
        // (or looked up by blueprint ID, as the card page does)
        $asksForKind = false;
        foreach ($conditions as $condition) {
            if ($condition[0] === 'i'
                || ($condition[0] === '' && preg_grep('/^\d+_\d+$/', array_slice($condition, 2)))) {
                $asksForKind = true;
            }
        }
        if (!$asksForKind) {
            $qb->andWhere("c.kind = 'card'");
        }

        if (!$i && !$forceempty) {
            return [];
        }
        switch ($sortorder) {
            case 'set':
                $qb->orderBy('y.position')->addOrderBy('p.position')->addOrderBy('c.position');
                break;
            case 'culture':
                $qb->orderBy('f.name')->addOrderBy('t.name');
                break;
            case 'type':
                $qb->orderBy('t.name')->addOrderBy('f.name');
                break;
            case 'twilight':
            case 'cost':
                $qb->orderBy('t.name')->addOrderBy('c.twilight');
                break;
            case 'strength':
                $qb->orderBy('t.name')->addOrderBy('c.strength')->addOrderBy('c.vitality');
                break;
        }
        $qb->addOrderBy('c.name');
        $qb->addOrderBy('c.code');
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param CardInterface $card
     * @param bool $api
     * @param string|null $version
     * @return array
     */
    public function getCardInfo(CardInterface $card, bool $api, ?string $version = null)
    {
        $cardinfo = [];

        $metadata = $this->doctrine->getManager()->getClassMetadata(Card::class);
        $fieldNames = $metadata->getFieldNames();
        $associationMappings = $metadata->getAssociationMappings();

        foreach ($associationMappings as $fieldName => $associationMapping) {
            if ($associationMapping['isOwningSide']) {
                $getter = str_replace(
                    ' ',
                    '',
                    ucwords(str_replace('_', ' ', "get_$fieldName"))
                );
                $associationEntity = $card->$getter();
                if (!$associationEntity) {
                    continue;
                }

                $cardinfo[$fieldName . '_code'] = $associationEntity->getCode();
                $cardinfo[$fieldName . '_name'] = $associationEntity->getName();
            }
        }

        foreach ($fieldNames as $fieldName) {
            $getter = str_replace(
                ' ',
                '',
                ucwords(str_replace('_', ' ', "get_$fieldName"))
            );
            $value = $card->$getter();
            switch ($metadata->getTypeOfField($fieldName)) {
                case 'datetime':
                case 'date':
                    continue 2;
                    break;
                case 'boolean':
                    $value = (bool) $value;
                    break;
            }
            $fieldName = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $fieldName)), '_');
            $cardinfo[$fieldName] = $value;
        }

        $cardinfo['url'] = $this->router->generate(
            'cards_zoom',
            array('card_code' => $card->getCode()),
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $cardinfo['label'] = $card->getFullName();

        if ($api) {
            unset($cardinfo['id'], $cardinfo['translations']);
        } else {
            $cardinfo['text'] = $this->replaceSymbols($cardinfo['text']);
            $cardinfo['text'] = $this->addAbbrTags($cardinfo['text']);
            $cardinfo['text'] = $this->splitInParagraphs($cardinfo['text']);

            $cardinfo['flavor'] = $this->replaceSymbols($cardinfo['flavor']);
        }


        $cardinfo['work_in_progress'] = $card->getPack()->getWorkInProgress();
        $cardinfo['dateUpdate'] = $card->getDateUpdate()->format('c');
        return $cardinfo;
    }

    public function syntax($query)
    {
        // renvoie une liste de conditions (array)
        // chaque condition est un tableau à n>1 éléments
        // le premier est le type de condition (0 ou 1 caractère)
        // les suivants sont les arguments, en OR

        $query = preg_replace('/\s+/u', ' ', trim($query));

        $list = [];
        $cond = null;
        // l'automate a 3 états :
        // 1:recherche de type
        // 2:recherche d'argument principal
        // 3:recherche d'argument supplémentaire
        // 4:erreur de parsing, on recherche la prochaine condition
        // s'il tombe sur un argument alors qu'il est en recherche de type, alors le type est vide
        $etat = 1;
        while ($query != "") {
            if ($etat == 1) {
                if (isset($cond) && $etat != 4 && count($cond) > 2) {
                    $list[] = $cond;
                }
                // on commence par rechercher un type de condition
                $match = [];
                if (preg_match('/^(\p{L})([:<>!])(.*)/u', $query, $match)) { // jeton "condition:"
                    $cond = array(mb_strtolower($match[1]), $match[2]);
                    $query = $match[3];
                } else {
                    $cond = array("", ":");
                }
                $etat = 2;
            } else {
                if (
                    preg_match('/^"([^"]*)"(.*)/u', $query, $match)
                    // jeton "texte libre entre guillements"
                    || preg_match('/^([\p{L}\p{N}\-\&_]+)(.*)/u', $query, $match)
                    // jeton "texte autorisé sans guillements"
                ) {
                    if (($etat == 2 && count($cond) == 2) || $etat == 3) {
                        $cond[] = $match[1];
                        $query = $match[2];
                        $etat = 2;
                    } else {
                        // erreur
                        $query = $match[2];
                        $etat = 4;
                    }
                } elseif (preg_match('/^\|(.*)/u', $query, $match)) { // jeton "|"
                    if (($cond[1] == ':' || $cond[1] == '!') && (($etat == 2 && count($cond) > 2) || $etat == 3)) {
                        $query = $match[1];
                        $etat = 3;
                    } else {
                        // erreur
                        $query = $match[1];
                        $etat = 4;
                    }
                } elseif (preg_match('/^ (.*)/u', $query, $match)) { // jeton " "
                    $query = $match[1];
                    $etat = 1;
                } else {
                    // erreur
                    $query = substr($query, 1);
                    $etat = 4;
                }
            }
        }
        if (isset($cond) && $etat != 4 && count($cond) > 2) {
            $list[] = $cond;
        }
        return $list;
    }

    public function validateConditions($conditions)
    {
        // suppression des conditions invalides
        $numeric = array('<', '>');

        foreach ($conditions as $i => $l) {
            $searchCode = $l[0];
            $searchOp = $l[1];

            if (in_array($searchOp, $numeric) && SearchController::$searchTypes[$searchCode] !== 'integer') {
                // operator is numeric but searched property is not
                unset($conditions[$i]);
            }
        }

        return array_values($conditions);
    }

    public function buildQueryFromConditions($conditions)
    {
        // reconstruction de la bonne chaine de recherche pour affichage
        return implode(" ", array_map(
            function ($l) {
                            return ($l[0] ? $l[0] . $l[1] : "")
                            . implode("|", array_map(
                                function ($s) {
                                                return preg_match("/^[\p{L}\p{N}\-\&]+$/u", $s) ? $s : "\"$s\"";
                                },
                                array_slice($l, 2)
                            ));
            },
            $conditions
        ));
    }

    public function getReviews($card)
    {
        $reviews = $this->doctrine->getRepository(Review::class)->findBy(
            array('card' => $card),
            array('nbVotes' => 'DESC')
        );

        $response = $reviews;

        return $response;
    }

    public function getDistinctTraits()
    {
        /**
         * @var $em EntityManager
         */
        $em = $this->doctrine->getManager();
        $qb = $em->createQueryBuilder();
        $qb->from(Card::class, 'c');
        $qb->select('c.traits');
        $qb->distinct();
        $result = $qb->getQuery()->getResult();

        $traits = [];
        foreach ($result as $card) {
            $subs = explode('.', $card["traits"]);
            foreach ($subs as $sub) {
                $traits[trim($sub)] = 1;
            }
        }
    }
}
