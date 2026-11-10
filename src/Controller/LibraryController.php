<?php

namespace App\Controller;

use App\Entity\Card;
use App\Entity\Culture;
use App\Entity\Library;
use App\Entity\LibraryComment;
use App\Entity\LibraryEntry;
use App\Entity\LibraryRevision;
use App\Entity\PublishedDeck;
use App\Entity\User;
use App\Repository\FormatRepository;
use App\Repository\LibraryRepository;
use App\Services\LibraryManager;
use App\Services\OrganizationAccounts;
use App\Services\PublishedDeckManager;
use App\Services\Texts;
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

    /**
     * The public libraries' tabs (Ketura, 2026-11-09), each with the same search: the official
     * libraries tagged as sample decks, those tagged sealed, and the rest: everyone else's, and
     * an official one with neither tag (so it can always be found, and tagged). A tab: its
     * route, the libraries it holds (LibraryRepository's kind) and the tag they carry.
     */
    public const TABS = [
        'samples' => ['route' => 'libraries_samples', 'kind' => 'official', 'tag' => 'sample-decks'],
        'sealed' => ['route' => 'libraries_sealed', 'kind' => 'official', 'tag' => 'sealed'],
        'user' => ['route' => 'libraries_user', 'kind' => 'rest', 'tag' => null],
    ];

    /** The tags that put an official library on a tab of its own */
    public static function tabTags(): array
    {
        return array_values(array_filter(array_column(self::TABS, 'tag')));
    }
    /** The cookie that remembers the tab last looked at (ui.libraries.js sets it) */
    public const TAB_COOKIE = 'rb_libraries_tab';

    /**
     * Libraries in the top bar: the tab last looked at, the sample decks the first time, with
     * whatever search came along
     */
    #[Route(path: '/libraries', name: 'libraries_list', methods: ['GET'])]
    public function publicListAction(Request $request): Response
    {
        $tab = self::TABS[(string) $request->cookies->get(self::TAB_COOKIE)] ?? self::TABS['samples'];
        $response = $this->redirectToRoute($tab['route'], $request->query->all());
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
    }

    #[Route(path: '/libraries/samples', name: 'libraries_samples', methods: ['GET'])]
    public function samplesListAction(Request $request, PublishedDeckManager $pager, TranslatorInterface $translator): Response
    {
        return $this->publicList($request, $pager, $translator, 'samples');
    }

    #[Route(path: '/libraries/sealed', name: 'libraries_sealed', methods: ['GET'])]
    public function sealedListAction(Request $request, PublishedDeckManager $pager, TranslatorInterface $translator): Response
    {
        return $this->publicList($request, $pager, $translator, 'sealed');
    }

    #[Route(path: '/libraries/user', name: 'libraries_user', methods: ['GET'])]
    public function userListAction(Request $request, PublishedDeckManager $pager, TranslatorInterface $translator): Response
    {
        return $this->publicList($request, $pager, $translator, 'user');
    }

    /** Where a library's tag leads: the tab it's on, searching for that tag */
    public static function tabRouteFor(Library $library): string
    {
        if ($library->isOfficial()) {
            foreach (self::TABS as $tab) {
                if ($tab['tag'] !== null && in_array($tab['tag'], $library->getTagList(), true)) {
                    return $tab['route'];
                }
            }
        }
        return self::TABS['user']['route'];
    }

    private function publicList(Request $request, PublishedDeckManager $pager, TranslatorInterface $translator, string $tab): Response
    {
        $response = $this->listResponse($request, $pager, null, [
            'pagetitle' => $translator->trans('library.list.tab.' . $tab),
            'list_route' => self::TABS[$tab]['route'],
            'list_kind' => $tab,
            'list_tabs' => self::TABS,
            'mine' => false,
        ], self::TABS[$tab]['kind'], self::TABS[$tab]['tag']);
        $response->setPublic();
        $response->setMaxAge(60);
        return $response;
    }

    private function listResponse(Request $request, PublishedDeckManager $pager, ?User $owner, array $params, ?string $kind = null, ?string $tag = null): Response
    {
        $filters = $this->libraries->getFilters($request, $owner !== null);
        $query = $filters + ($kind ? ['kind' => $kind] : []) + ($tag ? ['tab_tag' => $tag] : []) + ['tab_tags' => self::tabTags()];
        $limit = $filters['limit'] ?? PublishedDeckManager::DEFAULT_LIMIT;
        $page = max(1, (int) $request->query->get('page', 1));
        [$rows, $total] = $this->repo()->browse($query, $owner, $page, $limit);
        $pager->setLimit($limit);
        $pager->setPage($page);
        $pager->setMaxCount($total);
        $params += [
            'rows' => $rows,
            'total' => $total,
            'list_kind' => $params['list_kind'] ?? null,
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
            $this->libraries->change($library, $user, LibraryRevision::CREATE, function () use ($library, $ids) {
                if ($ids) {
                    $this->libraries->add($library, $this->managerRegistry->getRepository(PublishedDeck::class)->findBy(['id' => $ids]));
                }
            });
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
            // the Library Info tab, an admin's on an official library (Ketura, 2026-11-09)
            'show_info' => $library->isOfficial() && $this->isGranted('ROLE_ADMIN'),
            'organizations' => $library->isOfficial() && $this->isGranted('ROLE_ADMIN')
                ? $this->managerRegistry->getRepository(User::class)->findBy(['organization' => true], ['username' => 'ASC']) : [],
        ] + $this->historyParams($library));
    }

    /**
     * The Library Info tab's form (Ketura, 2026-11-09): an official library filed under another
     * organization's account (Decipher's rather than the PC's), a new one made if asked, and
     * its curator credit
     */
    #[Route(path: '/my/library/info/{library_uuid}', name: 'library_info', methods: ['POST'], requirements: ['library_uuid' => self::UUID])]
    public function infoAction(Request $request, string $library_uuid, OrganizationAccounts $organizations, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $library = $this->editable($library_uuid);
        $this->requireToken($request);
        $back = $this->generateUrl('library_edit', ['library_uuid' => $library->getUuid()]) . '#tab-pane-info';
        if (!$library->isOfficial()) {
            throw new NotFoundHttpException('Only an official library is filed under an organization.');
        }
        $users = $this->managerRegistry->getRepository(User::class);
        try {
            $newUsername = trim((string) $request->request->get('new_username'));
            if ($newUsername !== '') {
                $owner = $organizations->get($newUsername, trim((string) $request->request->get('new_name')) ?: $newUsername);
            } else {
                $owner = $users->find((int) $request->request->get('owner'));
                if (!$owner instanceof User || (!$owner->isOrganization() && $owner->getId() !== $library->getUser()->getId())) {
                    throw new \RuntimeException($translator->trans('library.info.not_organization'));
                }
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirect($back);
        }
        $curatorName = trim((string) $request->request->get('curator'));
        $curator = $curatorName !== '' ? $users->findOneBy(['usernameCanonical' => mb_strtolower($curatorName)]) : null;
        if ($curatorName !== '' && !$curator instanceof User) {
            $this->addFlash('error', $translator->trans('library.info.no_curator', ['%name%' => $curatorName]));
            return $this->redirect($back);
        }
        $library->setUser($owner);
        $library->setCurator($curator);
        $this->managerRegistry->getManager()->flush();
        $this->addFlash('notice', $translator->trans('library.info.saved', ['%name%' => $owner->getDisplayName()]));
        return $this->redirect($back);
    }

    /**
     * The library's History tab (Ketura, 2026-11-09): its revisions, newest first, and the
     * decklists their changes name
     */
    private function historyParams(Library $library): array
    {
        $revisions = $this->libraries->revisions($library);
        $ids = [];
        foreach ($revisions as $revision) {
            foreach (['added', 'removed', 'annotated', 'missing'] as $list) {
                array_push($ids, ...($revision->getChanges()[$list] ?? []));
            }
        }
        $decklists = [];
        foreach ($ids ? $this->managerRegistry->getRepository(PublishedDeck::class)->findBy(['id' => array_unique($ids)]) : [] as $decklist) {
            $decklists[$decklist->getId()] = $decklist;
        }
        return ['revisions' => $revisions, 'revision_decklists' => $decklists];
    }

    /** Puts the library back as an earlier revision had it, as a new revision */
    #[Route(path: '/my/library/revert/{library_uuid}', name: 'library_revert', methods: ['POST'], requirements: ['library_uuid' => self::UUID])]
    public function revertAction(Request $request, string $library_uuid, TranslatorInterface $translator): Response
    {
        $library = $this->editable($library_uuid);
        $this->requireToken($request);
        $revision = $this->managerRegistry->getRepository(LibraryRevision::class)->find((int) $request->request->get('revision'));
        if (!$revision instanceof LibraryRevision || $revision->getLibrary()->getId() !== $library->getId()) {
            throw new NotFoundHttpException('No such revision of this library.');
        }
        $missing = $this->libraries->revert($library, $revision, $this->requireUser());
        $this->managerRegistry->getManager()->flush();
        $this->addFlash('notice', $translator->trans('library.history.reverted', ['%date%' => $revision->getDateCreation()->format('Y-m-d H:i')]));
        if ($missing) {
            $this->addFlash('error', $translator->trans('library.history.missing', ['%count%' => count($missing)]));
        }
        return $this->redirect($this->generateUrl('library_edit', ['library_uuid' => $library->getUuid()]) . '#tab-pane-history');
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
        $this->libraries->change($library, $this->requireUser(), LibraryRevision::EDIT, fn () => $this->libraries->save($library, $data));
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse([
            'success' => true,
            'name' => $library->getName(),
            'count' => $library->getEntries()->count(),
            // the History tab again, with this save in it
            'history' => $this->renderView('Library/history.html.twig', ['library' => $library] + $this->historyParams($library)),
        ]);
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
            'library_tab_route' => self::tabRouteFor($library),
            'entries' => $entries,
            'entry_data' => array_map(fn (LibraryEntry $e) => $this->decklistData($e->getDecklist(), $names), $entries),
            'can_edit' => $canEdit,
            'private_view' => $private,
            // what this viewer has done, for the Cheers, Favourite and Comment buttons
            'social' => [
                'liked' => $user !== null && $library->getVotes()->contains($user),
                'favorite' => $user !== null && $library->getFavorites()->contains($user),
                'commented' => $user !== null && $library->getComments()->exists(fn ($i, LibraryComment $c) => $c->getUser() === $user),
            ],
            // an admin's Make Official, or the official copy already made of it
            'official_copy' => $library->isOfficial() ? null : $this->libraries->officialCopyOf($library),
            'commenters' => array_values(array_unique(array_map(fn (LibraryComment $c) => $c->getUser()->getUsername(), $library->getComments()->toArray()))),
        ]);
    }

    /* ---- making a user's library official (Ketura, 2026-11-09: by copying it) ---- */

    /** An admin makes a public user library official: a copy owned by the PC's account */
    #[Route(path: '/library/promote', name: 'library_promote', methods: ['POST'])]
    public function promoteAction(Request $request, OrganizationAccounts $organizations, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->requireToken($request);
        $library = $this->findLibrary((string) $request->request->get('id'));
        if ($library->isOfficial() || !$library->isPublic()) {
            throw new BadRequestHttpException('Only a public user library can be made official.');
        }
        $existing = $this->libraries->officialCopyOf($library);
        if ($existing === null) {
            // the PC's organization account, made the first time (Ketura, 2026-11-09)
            try {
                $owner = $organizations->official();
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('library_view', ['public_id' => $library->getPublicId(), 'library_name' => $library->getNameCanonical()]);
            }
            $existing = $this->libraries->promote($library, $owner, $this->requireUser());
            $this->managerRegistry->getManager()->flush();
            $this->addFlash('notice', $translator->trans('library.promote.done'));
        }
        return $this->redirectToRoute('library_view', ['public_id' => $existing->getPublicId(), 'library_name' => $existing->getNameCanonical()]);
    }

    /** A Librarian copies the original's contents over an official copy again */
    #[Route(path: '/library/refresh', name: 'library_refresh', methods: ['POST'])]
    public function refreshAction(Request $request, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_LIBRARIAN');
        $this->requireToken($request);
        $library = $this->findLibrary((string) $request->request->get('id'));
        if (!$library->isOfficial() || !$this->libraries->refreshFromOriginal($library, $this->requireUser())) {
            throw new BadRequestHttpException("This library wasn't copied from another.");
        }
        $this->managerRegistry->getManager()->flush();
        $this->addFlash('notice', $translator->trans('library.promote.refreshed'));
        return $this->redirectToRoute('library_view', ['public_id' => $library->getPublicId(), 'library_name' => $library->getNameCanonical()]);
    }

    /* ---- likes, favourites and comments (Ketura, 2026-11-09) ---- */

    /** A library the logged-in user can see, from the posted uuid */
    private function socialTarget(Request $request): array
    {
        $user = $this->requireUser();
        $this->requireToken($request);
        $library = $this->findLibrary((string) $request->request->get('id'));
        if (!$this->libraries->canView($user, $library)) {
            throw new NotFoundHttpException("This library doesn't exist.");
        }
        return [$library, $user];
    }

    /** Like or unlike (Cheers); a curator can't like their own. Answers {count, on} */
    #[Route(path: '/library/like', name: 'library_like', methods: ['POST'])]
    public function likeAction(Request $request): JsonResponse
    {
        [$library, $user] = $this->socialTarget($request);
        if ($library->getUser() === $user) {
            throw new AccessDeniedHttpException("You can't like your own library.");
        }
        $on = $library->toggleVote($user);
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse(['count' => $library->getNbVotes(), 'on' => $on]);
    }

    /** Favourite or unfavourite. Answers {count, on} */
    #[Route(path: '/library/favorite', name: 'library_favorite', methods: ['POST'])]
    public function favoriteAction(Request $request): JsonResponse
    {
        [$library, $user] = $this->socialTarget($request);
        $on = $library->toggleFavorite($user);
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse(['count' => $library->getNbFavorites(), 'on' => $on]);
    }

    /** A comment; answers the comment as the list shows it, to add to the page */
    #[Route(path: '/library/comment', name: 'library_comment', methods: ['POST'])]
    public function commentAction(Request $request, Texts $texts): Response
    {
        [$library, $user] = $this->socialTarget($request);
        $text = trim((string) $request->request->get('comment'));
        if ($text === '') {
            throw new BadRequestHttpException('The comment is empty.');
        }
        $comment = new LibraryComment($user, $texts->comment($text));
        $library->addComment($comment);
        $this->managerRegistry->getManager()->flush();
        return $this->render('Social/comment.html.twig', ['comment' => $comment]);
    }

    /** The curator hides a comment for everyone, or shows it again */
    #[Route(path: '/library/comment/hide/{comment_id}/{hidden}', name: 'library_comment_hide', methods: ['POST'], requirements: ['comment_id' => '\d+', 'hidden' => '[01]'])]
    public function hideCommentAction(int $comment_id, int $hidden): JsonResponse
    {
        $user = $this->requireUser();
        $comment = $this->managerRegistry->getRepository(LibraryComment::class)->find($comment_id) ?? throw new NotFoundHttpException();
        if ($comment->getLibrary()->getUser() !== $user) {
            return new JsonResponse("You can't hide comments on this library.");
        }
        $comment->setIsHidden((bool) $hidden);
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse(true);
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
        $added = $this->libraries->change($library, $user, $name !== '' ? LibraryRevision::CREATE : LibraryRevision::ADD, fn () => $this->libraries->add($library, $decklists));
        $this->managerRegistry->getManager()->flush();
        return new JsonResponse([
            'added' => $added,
            'name' => $library->getName(),
            'edit' => $this->generateUrl('library_edit', ['library_uuid' => $library->getUuid()]),
            'message' => $translator->trans($added ? 'library.add.added' : 'library.add.nothing', ['%count%' => $added, '%name%' => $library->getName()]),
        ]);
    }
}
