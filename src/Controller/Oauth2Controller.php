<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\DeckInterface;
use App\Entity\PublishedDeck;
use App\Entity\Faction;
use App\Entity\Tournament;
use App\Entity\UserInterface;
use App\Services\PublishedDeckFactory;
use App\Services\DeckManager;
use Exception;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use OpenApi\Attributes as OA;
use App\Entity\Deck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @package App\Controller
 */
class Oauth2Controller extends AbstractController
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }
    /**
     * @return Response
     */
    #[Route(path: '/api/oauth2/user', name: 'api_oauth2_user', methods: ['GET'])]
    public function userAction()
    {
        $response = new Response();
        $response->headers->add(['Access-Control-Allow-Origin' => '*']);

        /** @var UserInterface $user */
        $user = $this->getUser();
        $data = [
            'id'         => $user->getId(),
            'username'   => $user->getUserIdentifier(),
            'email'      => $user->getEmail(),
            'reputation' => $user->getReputation(),
        ];
        $content = json_encode([
            'data' => [$data],
        ]);

        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);

        return $response;
    }

    /**
     * All the Decks
     *
     * Get the description of all the Decks of the authenticated user
     *
     * @param Request $request
     * @return Response
     */
    #[Route(path: '/api/oauth2/decks', name: 'api_oauth2_decks', methods: ['GET'])]
    #[OA\Tag(name: 'Protected')]
    #[OA\Response(response: 200, description: 'Returned when successful')]
    public function listDecksAction(Request $request)
    {
        $response = new Response();
        $response->headers->add(['Access-Control-Allow-Origin' => '*']);

        /* @var $decks DeckInterface[] */
        $decks = $this->managerRegistry->getRepository(Deck::class)->findBy(['user' => $this->getUser(), 'dateDeleted' => null]);

        if (! empty($decks)) {
            $dateUpdates = array_map(function (DeckInterface $deck) {
                return $deck->getDateUpdate();
            }, $decks);

            $response->setLastModified(max($dateUpdates));
            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        $content = json_encode($decks);

        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);

        return $response;
    }


    /**
     * Get one deck by its UUID
     *
     * Get one deck by its UUID.
     *
     * @param Request $request
     * @param string  $uuid
     * @return Response
     */
    #[Route(path: '/api/oauth2/deck/load/{uuid}', name: 'api_oauth2_load_deck_by_uuid', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}'])]
    #[OA\Tag(name: 'Protected')]
    #[OA\Response(response: 200, description: 'Returned when successful')]
    public function loadDeckByUuidAction(Request $request, string $uuid): Response
    {
        /* @var DeckInterface $deck */
        $deck = $this->managerRegistry->getRepository(Deck::class)->findOneBy(['uuid' => $uuid, 'dateDeleted' => null]);
        if (!$deck) {
            throw $this->createNotFoundException("Deck not found.");
        }

        $is_owner = $this->getUser() && ($this->getUser()->getId() === $deck->getUser()->getId());
        if (!$deck->getUser()->getIsShareDecks() && !$is_owner) {
            throw $this->createAccessDeniedException("Access denied to this object.");
        }

        $response = new Response();
        $response->headers->add(['Access-Control-Allow-Origin' => '*']);
        $response->setLastModified($deck->getDateUpdate());
        if ($response->isNotModified($request)) {
            return $response;
        }

        $content = json_encode($deck);

        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);

        return $response;
    }

    /**
     * Load One Deck
     *
     * Get the description of one Deck of the authenticated user
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    #[Route(path: '/api/oauth2/deck/load/{id}', name: 'api_oauth2_load_deck', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Tag(name: 'Protected')]
    #[OA\Response(response: 200, description: 'Returned when successful')]
    public function loadDeckAction(Request $request, $id)
    {
        $response = new Response();
        $response->headers->add(['Access-Control-Allow-Origin' => '*']);

        /* @var DeckInterface $deck */
        $deck = $this->managerRegistry->getRepository(Deck::class)->findOneBy(['id' => $id, 'dateDeleted' => null]);

        if ($deck->getUser()->getId() !== $this->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied to this object.");
        }

        $response->setLastModified($deck->getDateUpdate());
        if ($response->isNotModified($request)) {
            return $response;
        }

        $content = json_encode($deck);

        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($content);

        return $response;
    }


    /**
     * Save One Deck
     *
     * Save one Deck of the authenticated user. The parameters are the same as in the response to the load method,
     * but only a few are writable.
     * So you can parse the result from the load, change a few values,
     * then send the object as the param of an ajax request.
     * If successful, id of Deck is in the msg.
     *
     * @param int $id
     * @param Request $request
     * @param DeckManager $deckManager
     */
    #[Route(path: '/api/oauth2/deck/save/{id}', name: 'api_oauth2_save_deck', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[OA\Tag(name: 'Protected')]
    #[OA\RequestBody(content: new OA\MediaType(mediaType: 'application/x-www-form-urlencoded', schema: new OA\Schema(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string', description: 'Name of the Deck'), new OA\Property(property: 'decklist_id', type: 'integer', description: 'Identifier of the published deck from which the Deck is copied'), new OA\Property(property: 'description_md', type: 'string', description: 'Description of the published deck in Markdown'), new OA\Property(property: 'target_format', type: 'string', description: 'Gemp format code'), new OA\Property(property: 'effective_date', type: 'string', description: 'YYYY-MM-DD'), new OA\Property(property: 'tags', type: 'string', description: 'Space-separated list of tags'), new OA\Property(property: 'slots', type: 'string', description: 'Content as a JSON object of blueprint ID => quantity; the Ring-bearer as "rb:" + blueprint ID')])))]
    #[OA\Response(response: 200, description: 'Returned when successful')]
    public function saveDeckAction($id, Request $request, DeckManager $deckManager)
    {
        if (!$id) {
            $deck = new Deck();
            $deck->setUuid(Uuid::uuid4());
            $deck->setOrigin(['type' => 'api']);
            $this->managerRegistry->getManager()->persist($deck);
        } else {
            $deck = $this->managerRegistry->getRepository(Deck::class)->findOneBy(['id' => $id, 'dateDeleted' => null]);
            if ($deck->getUser()->getId() !== $this->getUser()->getId()) {
                throw $this->createAccessDeniedException("Access denied to this object.");
            }
        }

        $slots = (array) json_decode((string) $request->get('slots'), true);
        if (!count($slots)) {
            return new JsonResponse([
                'success' => false,
                'msg'     => "Slots missing",
            ]);
        }
        foreach ($slots as $key => $qty) {
            if (!is_string($key) || !is_integer($qty)) {
                return new JsonResponse([
                    'success' => false,
                    'msg'     => "Slots invalid",
                ]);
            }
        }

        $name = trim(strip_tags((string) $request->get('name')));
        if (!$name) {
            return new JsonResponse([
                'success' => false,
                'msg'     => "Name missing",
            ]);
        }

        $deckManager->save($this->getUser(), $deck, [
            'name' => $name,
            'decklist_id' => (int) $request->get('decklist_id') ?: null,
            'description' => trim((string) $request->get('description_md', $request->get('description', ''))),
            'tags' => strip_tags((string) $request->get('tags')),
            'content' => $slots,
            'target_format' => $request->get('target_format') ?: null,
            'effective_date' => $request->get('effective_date') ?: null,
        // an existing deck's save is a revision, as from the deckbuilder (Ketura, 2026-11-08)
        ], $id ? $deck : null);

        $this->managerRegistry->getManager()->flush();

        return new JsonResponse([
            'success' => true,
            'msg'     => $deck->getId(),
        ]);
    }

    /**
     * Publish One Deck
     *
     * Try to publish one Deck of the authenticated user
     * If publication is successful, update the version of the deck and return the id of the decklist
     *
     * @param int $id
     * @param Request $request
     * @param PublishedDeckFactory $decklistFactory
     * @return Response $response
     */
    #[Route(path: '/api/oauth2/deck/publish/{id}', name: 'api_oauth2_publish_deck', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[OA\Tag(name: 'Protected')]
    #[OA\RequestBody(content: new OA\MediaType(mediaType: 'application/x-www-form-urlencoded', schema: new OA\Schema(properties: [new OA\Property(property: 'description_md', type: 'string', description: 'Description of the published deck in Markdown'), new OA\Property(property: 'tournament_id', type: 'integer', description: 'Identifier of the Tournament type of the published deck'), new OA\Property(property: 'precedent_id', type: 'integer', description: 'Identifier of the Predecessor of the published deck')])))]
    #[OA\Response(response: 200, description: 'Returned when successful')]
    public function publishDeckAction($id, Request $request, PublishedDeckFactory $decklistFactory)
    {
        /* @var DeckInterface $deck */
        $deck = $this->managerRegistry->getRepository(Deck::class)->findOneBy(['id' => $id, 'dateDeleted' => null]);
        if ($this->getUser()->getId() !== $deck->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied to this object.");
        }

        $name = filter_var(
            $request->request->get('name'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );
        $descriptionMd = trim($request->request->get('description_md'));

        $tournament_id = intval(
            filter_var($request->request->get('tournament_id'), FILTER_SANITIZE_NUMBER_INT)
        );
        /* @var TournamentInterface $tournament */
        $tournament = $this->managerRegistry->getManager()->getRepository(Tournament::class)->find($tournament_id);

        $precedent_id = trim($request->request->get('precedent'));
        if (!preg_match('/^\d+$/', $precedent_id)) {
            // route decklist_detail hard-coded
            if (preg_match('/view\/(\d+)/', $precedent_id, $matches)) {
                $precedent_id = $matches[1];
            } else {
                $precedent_id = null;
            }
        }
        $precedent = $precedent_id
            ? $this->managerRegistry->getRepository(PublishedDeck::class)->find($precedent_id)
            : null;

        try {
            $decklist = $decklistFactory->createDecklistFromDeck($deck, $name, $descriptionMd);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'msg'     => $e->getMessage(),
            ]);
        }

        $decklist->setTournament($tournament);
        $decklist->setPrecedent($precedent);
        $this->managerRegistry->getManager()->persist($decklist);
        $this->managerRegistry->getManager()->flush();

        return new JsonResponse([
            'success' => true,
            'msg'     => $decklist->getId(),
        ]);
    }
}
