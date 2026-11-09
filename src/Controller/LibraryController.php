<?php

namespace App\Controller;

use App\Entity\Card;
use App\Entity\Culture;
use App\Entity\Library;
use App\Entity\LibraryEntry;
use App\Entity\PublishedDeck;
use App\Entity\User;
use App\Repository\FormatRepository;
use App\Repository\LibraryRepository;
use App\Services\LibraryManager;
use App\Services\PublishedDeckManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Libraries (Ketura, 2026-11-08): collections of published decklists, each with the curator's
 * commentary. My Libraries and the public list; New Library; the editor, laid out as the
 * deckbuilder; the library as others see it; and Add to Library from every list of decks.
 */
class LibraryController extends AbstractController
{
    private const UUID = '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}';
    private const TOKEN = 'library';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly LibraryManager $libraries,
    ) {
    }

    private function repo(): LibraryRepository
    {
        return $this->managerRegistry->getRepository(Library::class);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('You must be logged in.');
        }
        return $user;
    }

    private function findLibrary(string $uuid): Library
    {
        return $this->repo()->findOneBy(['uuid' => $uuid]) ?? throw new NotFoundHttpException("This library doesn't exist.");
    }

    /** A library the user may change */
    private function editable(string $uuid): Library
    {
        $library = $this->findLibrary($uuid);
        $user = $this->getUser();
        if (!$this->libraries->canEdit($user instanceof User ? $user : null, $library)) {
            throw new AccessDeniedHttpException("You can't change this library.");
        }
        return $library;
    }

    private function requireToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('token'))) {
            throw new BadRequestHttpException('The page has expired; reload it and try again.');
        }
    }

    /* ---- the lists ---- */

    /** My Libraries: the user's libraries, searched as My Decks is, and what can be done to them */
    #[Route(path: '/my/libraries', name: 'my_libraries', methods: ['GET'])]
    public function myListAction(Request $request, PublishedDeckManager $pager, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        return $this->listResponse($request, $pager, $user, [
            'pagetitle' => $translator->trans('nav.mylibraries'),
            'list_route' => 'my_libraries',
            'mine' => true,
        ]);
    }

    /** The public libraries, with the same search */
    #[Route(path: '/libraries', name: 'libraries_list', methods: ['GET'])]
    public function publicListAction(Request $request, PublishedDeckManager $pager, TranslatorInterface $translator): Response
    {
        $response = $this->listResponse($request, $pager, null, [
            'pagetitle' => $translator->trans('library.list.title'),
            'list_route' => 'libraries_list',
            'mine' => false,
        ]);
        $response->setPublic();
        $response->setMaxAge(60);
        return $response;
    }

    private function listResponse(Request $request, PublishedDeckManager $pager, ?User $owner, array $params): Response
    {
        $filters = $this->libraries->getFilters($request, $owner !== null);
        $limit = $filters['limit'] ?? PublishedDeckManager::DEFAULT_LIMIT;
        $page = max(1, (int) $request->query->get('page', 1));
        [$rows, $total] = $this->repo()->browse($filters, $owner, $page, $limit);
        $pager->setLimit($limit);
        $pager->setPage($page);
        $pager->setMaxCount($total);
        $params += [
            'rows' => $rows,
            'total' => $total,
            'filters' => $filters,
            'pager' => $pager->getPager(9),
            'owner' => $owner,
        ];
        if ($request->isXmlHttpRequest()) {
            $response = new JsonResponse([
                'top' => $this->renderBlockView('Library/list.html.twig', 'browse_top', $params),
                'fields' => $this->renderBlockView('Library/list.html.twig', 'browse_fields', $params),
                'results' => $this->renderBlockView('Library/list.html.twig', 'browse_results', $params),
                'side' => $this->renderBlockView('Library/list.html.twig', 'browse_side', $params),
            ]);
        } else {
            $response = $this->render('Library/list.html.twig', $params);
        }
        if ($owner !== null) {
            $response->setPrivate();
        }
        $response->setVary(['X-Requested-With']);
        return $response;
    }

    /** The Tags drawer's list: the user's libraries' tags first, then the public ones' */
    #[Route(path: '/libraries/tags', name: 'library_tags', methods: ['GET'])]
    public function tagsAction(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $mine = $user instanceof User && $request->query->get('mine') ? $this->repo()->countTags($user) : [];
        $public = $this->repo()->countTags(null);
        $list = array_map(fn ($tag) => ['tag' => (string) $tag, 'count' => $mine[$tag] ?? 0, 'site' => $public[$tag] ?? 0],
            array_unique(array_merge(array_keys($mine), array_keys($public))));
        usort($list, fn ($a, $b) => [$b['count'], $b['site'], $a['tag']] <=> [$a['count'], $a['site'], $b['tag']]);
        return new JsonResponse($list);
    }

    /** Libraries out, from My Libraries */
    #[Route(path: '/my/library/delete', name: 'library_delete', methods: ['POST'])]
    public function deleteAction(Request $request, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        $this->requireToken($request);
        $em = $this->managerRegistry->getManager();
        $count = 0;
        foreach ((array) ($request->request->all()['uuids'] ?? []) as $uuid) {
            $library = $this->repo()->findOneBy(['uuid' => (string) $uuid]);
            // only the owner deletes a library, a Librarian or not
            if ($library && $library->getUser()->getId() === $user->getId()) {
                $em->remove($library);
                $count++;
            }
        }
        $em->flush();
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['deleted' => $count]);
        }
        $this->addFlash('notice', $translator->trans('library.deleted', ['%count%' => $count]));
        return $this->redirectToRoute('my_libraries');
    }

    /* ---- New Library ---- */

    #[Route(path: '/library/new', name: 'library_new', methods: ['GET', 'POST'])]
    public function newAction(Request $request, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
                throw new BadRequestHttpException('The form has expired; reload the page and try again.');
            }
            $library = $this->libraries->create($user, (string) $request->request->get('name'), (string) $request->request->get('description'));
            // decklists picked on the way in (Add to Library's + Add New Library with a selection)
            $ids = array_filter(array_map('intval', explode(',', (string) $request->request->get('decklists'))));
            if ($ids) {
                $this->libraries->add($library, $this->managerRegistry->getRepository(PublishedDeck::class)->findBy(['id' => $ids]));
            }
            $this->managerRegistry->getManager()->flush();
            return $this->redirectToRoute('library_edit', ['library_uuid' => $library->getUuid()]);
        }
        return $this->render('Library/new.html.twig', [
            'pagetitle' => $translator->trans('library.new.title'),
            'decklists' => (string) $request->query->get('decklists'),
        ]);
    }

    /* ---- the editor ---- */

    /**
     * The library editor, laid out as the deckbuilder: the library on the left, each decklist
     * with its commentary beside it; the Build tab (the decklists search) and the Notes tab
     * on the right
     */
    #[Route(path: '/my/library/edit/{library_uuid}', name: 'library_edit', methods: ['GET'], requirements: ['library_uuid' => self::UUID])]
    public function editAction(Request $request, string $library_uuid, FormatRepository $formats, PublishedDeckManager $decklistManager, TranslatorInterface $translator): Response
    {
        $library = $this->editable($library_uuid);
        $names = array_map(fn ($f) => $f->getName(), $formats->findAllByCode());
        return $this->render('Library/edit.html.twig', $this->searchParams($request, $library, $decklistManager, $formats) + [
            'pagetitle' => $translator->trans('library.edit.title', ['%name%' => $library->getName()]),
            'library' => $library,
            'entries' => array_map(fn (LibraryEntry $entry) => $this->entryData($entry, $names), $library->getEntries()->toArray()),
            'is_owner' => $library->getUser()->getId() === $this->getUser()?->getId(),
        ]);
    }

    /** A decklist as the editor and the drawer show it */
    private function decklistData(PublishedDeck $decklist, array $formatNames): array
    {
        $grace = $decklist->canWithdraw() ? $decklist->getGraceEnds() : null;
        $newer = $this->libraries->newerVersion($decklist);
        $data = [
            'id' => $decklist->getId(),
            'name' => $decklist->getName(),
            'author' => $decklist->getUser()->getDisplayName(),
            'url' => $this->generateUrl('decklist_detail', ['decklist_id' => $decklist->getId(), 'decklist_name' => $decklist->getNameCanonical()]),
            'version' => 'v' . $decklist->getVersionLabel(),
            'format' => $formatNames[$decklist->getTargetFormat()] ?? $decklist->getTargetFormat(),
            'kind' => $decklist->getKind(),
            'fp' => $decklist->getNbFreePeoples(),
            'shadow' => $decklist->getNbShadow(),
            'date' => ($decklist->getDatePublished() ?? $decklist->getDateCreation())->format('Y-m-d'),
            'withdrawn' => $decklist->isWithdrawn(),
            'grace_until' => $grace?->format('Y-m-d H:i T'),
            'newer' => $newer ? ['version' => 'v' . $newer->getVersionLabel(), 'url' => $this->generateUrl('decklist_detail', ['decklist_id' => $newer->getId(), 'decklist_name' => $newer->getNameCanonical()])] : null,
            'thumb' => $this->renderView('Decklist/thumbnail.html.twig', ['decklist' => $decklist]),
        ];
        // the flags, one template for the editor and the view
        $data['flags'] = trim($this->renderView('Library/entry-flags.html.twig', ['d' => $data]));
        return $data;
    }

    private function entryData(LibraryEntry $entry, array $formatNames): array
    {
        return ['decklist' => $this->decklistData($entry->getDecklist(), $formatNames), 'annotation' => (string) $entry->getAnnotationMd()];
    }

    /** The editor's Save */
    #[Route(path: '/my/library/save/{library_uuid}', name: 'library_save', methods: ['POST'], requirements: ['library_uuid' => self::UUID])]
    public function saveAction(Request $request, string $library_uuid): JsonResponse
    {
        $library = $this->editable($library_uuid);
        $this->requireToken($request);
        $data = json_decode((string) $request->request->get('library'), true);
        if (!is_array($data)) {
            throw new BadRequestHttpException('Nothing to save.');
        }
        $this->libraries->save($library, $data);
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse(['success' => true, 'name' => $library->getName(), 'count' => $library->getEntries()->count()]);
    }

    /** Private, hidden or public; public the first time gives it its public number */
    #[Route(path: '/my/library/visibility/{library_uuid}', name: 'library_visibility', methods: ['POST'], requirements: ['library_uuid' => self::UUID])]
    public function visibilityAction(Request $request, string $library_uuid): JsonResponse
    {
        $library = $this->editable($library_uuid);
        $this->requireToken($request);
        // a Librarian looks after a public library; making it private again is the owner's call
        if ($library->getUser()->getId() !== $this->getUser()?->getId()) {
            throw new AccessDeniedHttpException('Only its owner can change who sees this library.');
        }
        $this->libraries->setVisibility($library, (string) $request->request->get('visibility'));
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse([
            'visibility' => $library->getVisibility(),
            'public_url' => $library->getPublicId() ? $this->generateUrl('library_view', ['public_id' => $library->getPublicId(), 'library_name' => $library->getNameCanonical()], 0) : null,
        ]);
    }

    /**
     * The Build tab's search: the decklists page's filters and list, each decklist with an
     * Add button. Answered as the parts ui.decklist_search swaps in.
     */
    #[Route(path: '/my/library/search/{library_uuid}', name: 'library_search', methods: ['GET'], requirements: ['library_uuid' => self::UUID])]
    public function searchAction(Request $request, string $library_uuid, PublishedDeckManager $decklistManager, FormatRepository $formats): JsonResponse
    {
        $library = $this->editable($library_uuid);
        $params = $this->searchParams($request, $library, $decklistManager, $formats);
        $response = new JsonResponse([
            'top' => $this->renderBlockView('Library/build.html.twig', 'browse_top', $params),
            'fields' => $this->renderBlockView('Library/build.html.twig', 'browse_fields', $params),
            'results' => $this->renderBlockView('Library/build.html.twig', 'browse_results', $params),
        ]);
        $response->setPrivate();
        return $response;
    }

    /** What the Build tab shows; the editor renders it first with the page */
    private function searchParams(Request $request, Library $library, PublishedDeckManager $decklistManager, FormatRepository $formats): array
    {
        $filters = $decklistManager->getFilters();
        unset($filters['mine'], $filters['favorites']);
        $decklistManager->setLimit($filters['limit'] ?? PublishedDeckManager::DEFAULT_LIMIT);
        $decklistManager->setPage(max(1, (int) $request->query->get('page', 1)));
        $decklists = iterator_to_array($decklistManager->browse($filters, $this->getUser()), false);
        $allFormats = $formats->findAllByCode();
        $names = array_map(fn ($f) => $f->getName(), $allFormats);
        $usedFormats = [];
        foreach ($decklistManager->countFormats() as $code => $count) {
            $usedFormats[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'count' => $count];
        }
        return [
            'library' => $library,
            'decklists' => $decklists,
            'entry_data' => array_combine(array_map(fn ($d) => $d->getId(), $decklists), array_map(fn ($d) => $this->decklistData($d, $names), $decklists)) ?: [],
            'total' => $decklistManager->getMaxCount(),
            'filters' => $filters,
            'formats' => $usedFormats,
            'format_names' => $names,
            'cultures' => $this->managerRegistry->getRepository(Culture::class)->findBy([], ['name' => 'ASC']),
            'cards' => empty($filters['cards']) ? [] : $this->managerRegistry->getRepository(Card::class)->findBy(['code' => $filters['cards']]),
            'pager' => $decklistManager->getPager(9),
        ];
    }

    /* ---- the library as others see it ---- */

    /** By its uuid: the owner's preview, and the address of a library shared by link */
    #[Route(path: '/my/library/view/{library_uuid}', name: 'library_view_private', methods: ['GET'], requirements: ['library_uuid' => self::UUID])]
    public function viewPrivateAction(string $library_uuid, FormatRepository $formats): Response
    {
        $library = $this->findLibrary($library_uuid);
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        if (!$this->libraries->canView($user, $library)) {
            throw new AccessDeniedHttpException("This library is private.");
        }
        $response = $this->renderView_($library, $formats, true);
        $response->setPrivate();
        return $response;
    }

    /** A public library at its public number */
    #[Route(path: '/library/view/{public_id}/{library_name}', name: 'library_view', methods: ['GET'], defaults: ['library_name' => null], requirements: ['public_id' => '\d+'])]
    public function viewAction(int $public_id, FormatRepository $formats): Response
    {
        $library = $this->repo()->findOneBy(['publicId' => $public_id]) ?? throw new NotFoundHttpException("This library doesn't exist.");
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        // made private again: gone, except for whoever may change it
        if (!$library->isPublic() && !$this->libraries->canEdit($user, $library)) {
            throw new NotFoundHttpException("This library isn't public.");
        }
        return $this->renderView_($library, $formats, false);
    }

    private function renderView_(Library $library, FormatRepository $formats, bool $private): Response
    {
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        $canEdit = $this->libraries->canEdit($user, $library);
        $names = array_map(fn ($f) => $f->getName(), $formats->findAllByCode());
        // a decklist taken down shows only to whoever may change the library (Ketura, 2026-11-08)
        $entries = array_values(array_filter($library->getEntries()->toArray(), fn (LibraryEntry $e) => $canEdit || $e->isAvailable()));
        return $this->render('Library/view.html.twig', [
            'pagetitle' => $library->getName(),
            'library' => $library,
            'entries' => $entries,
            'entry_data' => array_map(fn (LibraryEntry $e) => $this->decklistData($e->getDecklist(), $names), $entries),
            'can_edit' => $canEdit,
            'private_view' => $private,
        ]);
    }

    /** One decklist of a library, opened in its view: the deck, rendered when it's opened */
    #[Route(path: '/library/entry/{entry_id}/deck', name: 'library_entry_deck', methods: ['GET'], requirements: ['entry_id' => '\d+'])]
    public function entryDeckAction(int $entry_id): Response
    {
        $entry = $this->managerRegistry->getRepository(LibraryEntry::class)->find($entry_id) ?? throw new NotFoundHttpException();
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        $library = $entry->getLibrary();
        if (!$this->libraries->canView($user, $library) || (!$entry->isAvailable() && !$this->libraries->canEdit($user, $library))) {
            throw new NotFoundHttpException();
        }
        // the deck as the reader shows it: app.deck takes it, app.deck_editor draws it
        $response = JsonResponse::fromJsonString((string) json_encode($entry->getDecklist()));
        $library->isPublic() ? $response->setPublic() : $response->setPrivate();
        $response->setMaxAge(60);
        return $response;
    }

    /* ---- Add to Library, from every list of decks ---- */

    /**
     * The drawer's list: the user's libraries, most recently changed first, narrowed by name,
     * each saying which of the decklists being added it has already
     */
    #[Route(path: '/my/libraries/picker', name: 'library_picker', methods: ['GET'])]
    public function pickerAction(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['login' => $this->generateUrl('fos_user_security_login')], 401);
        }
        $ids = array_filter(array_map('intval', (array) ($request->query->all()['decklists'] ?? [])));
        $rows = [];
        foreach ($this->repo()->picker($user, (string) $request->query->get('q')) as $library) {
            $have = 0;
            foreach ($library->getEntries() as $entry) {
                $have += in_array($entry->getDecklist()->getId(), $ids, true) ? 1 : 0;
            }
            $rows[] = [
                'uuid' => (string) $library->getUuid(),
                'name' => $library->getName(),
                'count' => $library->getEntries()->count(),
                'visibility' => $library->getVisibility(),
                'has' => $have,
                'edit' => $this->generateUrl('library_edit', ['library_uuid' => $library->getUuid()]),
            ];
        }
        $response = new JsonResponse(['libraries' => $rows, 'token' => $this->container->get('security.csrf.token_manager')->getToken(self::TOKEN)->getValue(), 'new' => $this->generateUrl('library_new')]);
        $response->setPrivate();
        return $response;
    }

    /** Decklists into a library, from the drawer; or into a new one, named in the drawer */
    #[Route(path: '/my/library/add', name: 'library_add', methods: ['POST'])]
    public function addAction(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $user = $this->requireUser();
        $this->requireToken($request);
        $name = trim((string) $request->request->get('name'));
        $library = $name !== '' ? $this->libraries->create($user, $name) : $this->editable((string) $request->request->get('library'));
        $ids = array_filter(array_map('intval', (array) ($request->request->all()['decklists'] ?? [])));
        $decklists = $ids ? $this->managerRegistry->getRepository(PublishedDeck::class)->findBy(['id' => $ids]) : [];
        $added = $this->libraries->add($library, $decklists);
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse([
            'added' => $added,
            'name' => $library->getName(),
            'edit' => $this->generateUrl('library_edit', ['library_uuid' => $library->getUuid()]),
            'message' => $translator->trans($added ? 'library.add.added' : 'library.add.nothing', ['%count%' => $added, '%name%' => $library->getName()]),
        ]);
    }
}
