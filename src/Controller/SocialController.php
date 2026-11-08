<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use App\Classes\FormMode;
use App\Entity\Card;
use App\Entity\Comment;
use App\Entity\CommentInterface;
use App\Entity\Deck;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckInterface;
use App\Entity\Culture;
use App\Entity\PublishedDeckRevision;
use App\Repository\FormatRepository;
use App\Entity\User;
use App\Services\PublishedDeckFactory;
use App\Services\PublishedDeckManager;
use App\Services\Texts;
use DateTime;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class SocialController
 * @package App\Controller
 */
class SocialController extends AbstractController
{
    use LocaleAwareTemplating;

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    /**
     * Publishing a deck: the decklist form in Create mode (Ketura, 2026-11-06). The
     * decklist is made from the deck as it stands and kept only when the form is sent.
     * A deck published before defaults to the next version of that decklist's line
     * (Ketura, 2026-11-07); the author can pick another of their lines, or none.
     */
    #[Route(path: '/deck/publish/{deck_uuid}', name: 'deck_publish_form', methods: ['GET', 'POST'], requirements: ['deck_uuid' => '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}'])]
    public function publishAction(
        Request $request,
        TranslatorInterface $translator,
        PublishedDeckFactory $decklistFactory,
        FormatRepository $formats,
        Texts $texts,
        string $deck_uuid
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException($translator->trans('login_required'));
        }

        $em = $this->managerRegistry->getManager();
        $deck = $em->getRepository(Deck::class)->findOneBy(['uuid' => $deck_uuid]);
        if (!$deck || $deck->getUser()->getId() != $user->getId()) {
            throw $this->createAccessDeniedException($translator->trans('decklist.publish.errors.unauthorized'));
        }

        $decklist = $decklistFactory->createDecklistFromDeck($deck, $deck->getName(), $deck->getDescriptionMd());
        $repo = $em->getRepository(PublishedDeck::class);

        if ($request->isMethod('POST')) {
            // the line it continues: one of the author's own decklists, or a new line
            $previous = $repo->find((int) $request->request->get('previous_version'));
            if ($previous && $previous->getUser()->getId() !== $user->getId()) {
                $previous = null;
            }
            $decklistFactory->continueLine($decklist, $previous);
            $this->applyDecklistForm($decklist, $request, FormMode::Create, $texts);
            $em->persist($decklist);
            $em->flush();

            return $this->redirectToRoute('decklist_detail', [
                'decklist_id' => $decklist->getId(),
                'decklist_name' => $decklist->getNameCanonical(),
            ]);
        }

        // the same cards already published: one warning, linking the user's own copy if
        // there is one (it was a warning per copy, a wall of them for a common deck)
        $content = json_encode($deck->getSlots()->getContent());
        $same = array_filter(
            $repo->findBy(['signature' => md5($content), 'withdrawn' => false], ['dateCreation' => 'DESC']),
            fn (PublishedDeck $published) => json_encode($published->getSlots()->getContent()) == $content,
        );
        // the user's own copy first, then an archive account's, which they may be able to claim
        $own = array_filter($same, fn (PublishedDeck $published) => $published->getUser()->getId() === $user->getId());
        $archived = array_filter($same, fn (PublishedDeck $published) => $published->getUser()->isHistoric());
        $published = reset($own) ?: (reset($archived) ?: reset($same));
        $warning = null;
        if ($published) {
            $url = $this->generateUrl('decklist_detail', [
                'decklist_id' => $published->getId(),
                'decklist_name' => $published->getNameCanonical(),
            ]);
            $warning = $translator->trans('decklist.publish.warnings.published', ['%url%' => $url]);
            if (!$own && $published->getUser()->isHistoric()) {
                $warning .= ' ' . $translator->trans('decklist.publish.warnings.claim', [
                    '%url%' => $this->generateUrl('historic_claim_form', ['user_id' => $published->getUser()->getId()]),
                ]);
            }
        }

        // what a new version can follow: the latest of each of the author's lines
        $lines = [];
        foreach ($repo->findLatestOfLines($user) as $latest) {
            $lines[] = ['decklist' => $latest, 'next' => $latest->getVersion() + 1];
        }

        return $this->renderDecklistForm(FormMode::Create, $decklist, $formats, $translator, [
            'action' => $this->generateUrl('deck_publish_form', ['deck_uuid' => $deck->getUuid()]),
            'cancel' => $this->generateUrl('deck_edit', ['deck_uuid' => $deck->getUuid()]),
            'lines' => $lines,
            'previous' => $decklistFactory->defaultPrevious($deck),
            // shown under the title (Ketura, 2026-11-07)
            'duplicate_warning' => $warning,
        ]);
    }

    /**
     * Changing a published decklist: the decklist form in Edit mode (Ketura,
     * 2026-11-06). The author, or a super admin. The commentary can always change; the
     * cards, half, format and date only while it's withdrawn (Ketura, 2026-11-07).
     */
    #[Route(path: '/decklist/edit/{decklist_id}', name: 'decklist_edit', methods: ['GET', 'POST'], requirements: ['decklist_id' => '\d+'])]
    public function editAction(
        Request $request,
        TranslatorInterface $translator,
        FormatRepository $formats,
        Texts $texts,
        int $decklist_id
    ): Response {
        $decklist = $this->editableDecklist($decklist_id);
        $em = $this->managerRegistry->getManager();

        if ($request->isMethod('POST')) {
            $this->applyDecklistForm($decklist, $request, FormMode::Edit, $texts);
            $decklist->setDateUpdate(new DateTime());
            $em->flush();

            return $this->redirectToRoute('decklist_detail', [
                'decklist_id' => $decklist->getId(),
                'decklist_name' => $decklist->getNameCanonical(),
            ]);
        }

        // the version before this one, for "version 2 of …"
        $previous = null;
        foreach ($em->getRepository(PublishedDeck::class)->findVersions($decklist) as $version) {
            if ($version->getVersion() < $decklist->getVersion()) {
                $previous = $version;
                break;
            }
        }
        $deck = $decklist->getParent();

        return $this->renderDecklistForm(FormMode::Edit, $decklist, $formats, $translator, [
            'action' => $this->generateUrl('decklist_edit', ['decklist_id' => $decklist->getId()]),
            'cancel' => $this->generateUrl('decklist_detail', [
                'decklist_id' => $decklist->getId(),
                'decklist_name' => $decklist->getNameCanonical(),
            ]),
            'previous' => $previous,
            // the deck a withdrawn decklist takes its cards from again, if it's still the author's
            'source_deck' => $deck instanceof Deck && $deck->getUser()->getId() === $decklist->getUser()->getId() ? $deck : null,
        ]);
    }

    /**
     * Undo publishing (Ketura, 2026-11-07): within the grace period the author takes the
     * decklist down to fix it. It disappears for everyone else and keeps its number, to
     * come back at the same address when it's published again.
     */
    #[Route(path: '/decklist/withdraw/{decklist_id}', name: 'decklist_withdraw', methods: ['POST'], requirements: ['decklist_id' => '\d+'])]
    public function withdrawAction(Request $request, TranslatorInterface $translator, int $decklist_id): Response
    {
        $decklist = $this->editableDecklist($decklist_id, true);
        $this->requireStateToken($request);
        if (!$decklist->canWithdraw()) {
            throw new BadRequestHttpException($translator->trans('decklist.state.errors.locked'));
        }
        $decklist->setWithdrawn(true);
        $this->managerRegistry->getManager()->flush();

        $this->addFlash('notice', $translator->trans('decklist.state.withdrawn'));
        $edit = $this->generateUrl('decklist_edit', ['decklist_id' => $decklist->getId()]);
        return $request->isXmlHttpRequest() ? new JsonResponse(['success' => true, 'url' => $edit]) : $this->redirect($edit);
    }

    /** Publishing a withdrawn decklist again: public at its old address, with a fresh grace period */
    #[Route(path: '/decklist/republish/{decklist_id}', name: 'decklist_republish', methods: ['POST'], requirements: ['decklist_id' => '\d+'])]
    public function republishAction(Request $request, TranslatorInterface $translator, int $decklist_id): Response
    {
        $decklist = $this->editableDecklist($decklist_id, true);
        $this->requireStateToken($request);
        if (!$decklist->isWithdrawn()) {
            throw new BadRequestHttpException('This decklist is already public.');
        }
        $decklist->setWithdrawn(false);
        $decklist->setDatePublished(new DateTime());
        $decklist->setDateUpdate(new DateTime());
        $this->managerRegistry->getManager()->flush();

        $this->addFlash('notice', $translator->trans('decklist.state.republished'));
        return $this->redirectToRoute('decklist_detail', [
            'decklist_id' => $decklist->getId(),
            'decklist_name' => $decklist->getNameCanonical(),
        ]);
    }

    /**
     * A withdrawn decklist takes the cards of the deck it was published from again
     * (Ketura, 2026-11-07): fix the deck in the deckbuilder, bring the fix over, publish again.
     */
    #[Route(path: '/decklist/replace-cards/{decklist_id}', name: 'decklist_replace_cards', methods: ['POST'], requirements: ['decklist_id' => '\d+'])]
    public function replaceCardsAction(Request $request, TranslatorInterface $translator, PublishedDeckFactory $decklistFactory, int $decklist_id): Response
    {
        $decklist = $this->editableDecklist($decklist_id, true);
        $this->requireStateToken($request);
        $deck = $decklist->getParent();
        if (!$decklist->isWithdrawn() || !$deck instanceof Deck || $deck->getUser()->getId() !== $decklist->getUser()->getId()) {
            throw new BadRequestHttpException($translator->trans('decklist.state.errors.no_deck'));
        }
        $decklistFactory->replaceCardsFromDeck($decklist, $deck);
        $this->managerRegistry->getManager()->flush();

        $this->addFlash('notice', $translator->trans('decklist.state.replaced'));
        return $this->redirectToRoute('decklist_edit', ['decklist_id' => $decklist->getId()]);
    }

    /**
     * Public decklists for the derived-from picker (Ketura, 2026-11-07): by author and
     * name, or one by id or address.
     */
    #[Route(path: '/decklist/lookup', name: 'decklist_lookup', methods: ['GET'])]
    public function lookupAction(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $repo = $this->managerRegistry->getRepository(PublishedDeck::class);
        $except = $request->query->getInt('except') ?: null;
        $id = trim((string) $request->query->get('id'));
        if ($id !== '') {
            $id = preg_match('/^\d+$/', $id) ? (int) $id : (preg_match('#/decklist/view/(\d+)#', $id, $m) ? (int) $m[1] : 0);
            $found = $id && $id !== $except ? $repo->find($id) : null;
            $decklists = $found && !$found->isWithdrawn() ? [$found] : [];
        } else {
            $author = trim((string) $request->query->get('author'));
            $name = trim((string) $request->query->get('name'));
            $decklists = ($author === '' && $name === '') ? [] : $repo->lookup($author, $name, $except);
        }
        return new JsonResponse(array_map(fn (PublishedDeck $d) => $this->decklistCard($d), $decklists));
    }

    /** What the picker shows of a decklist: enough to tell it's the right one */
    private function decklistCard(PublishedDeck $decklist): array
    {
        return [
            'id' => $decklist->getId(),
            'name' => $decklist->getName(),
            'version' => $decklist->getVersion(),
            'author' => $decklist->getUser()->getDisplayName(),
            'date' => $decklist->getDateCreation()->format('Y-m-d'),
            'url' => $this->generateUrl('decklist_detail', [
                'decklist_id' => $decklist->getId(),
                'decklist_name' => $decklist->getNameCanonical(),
            ]),
            'tags' => array_values(array_filter(explode(' ', (string) $decklist->getTags()))),
        ];
    }

    /**
     * A decklist the user may edit: their own, or any for a super admin. $authorOnly for
     * withdrawing and publishing again, which are the author's alone.
     */
    private function editableDecklist(int $decklist_id, bool $authorOnly = false): PublishedDeck
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException("Anonymous access denied");
        }
        $decklist = $this->managerRegistry->getRepository(PublishedDeck::class)->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException("Published deck not found");
        }
        $isAuthor = $user->getId() === $decklist->getUser()->getId();
        if (!$isAuthor && ($authorOnly || !$this->isGranted('ROLE_SUPER_ADMIN'))) {
            throw $this->createAccessDeniedException("Access denied");
        }
        return $decklist;
    }

    private function requireStateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('decklist_state', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('The form has expired; reload the page and try again.');
        }
    }

    /**
     * The decklist form, for either mode: it posts back to the address that showed it.
     * Laid out as the deckbuilder is (Ketura, 2026-11-07): the deck on the left as it will
     * be published, its format, legality and half with it; the form on the right.
     */
    private function renderDecklistForm(
        FormMode $mode,
        PublishedDeck $decklist,
        FormatRepository $formats,
        TranslatorInterface $translator,
        array $params,
    ): Response {
        return $this->render('Decklist/decklist_form.html.twig', $params + [
            'mode' => $mode->value,
            'pagetitle' => $translator->trans('decklist.form.title.' . $mode->value),
            'decklist' => $decklist,
            'format' => $decklist->getTargetFormat() ? $formats->findOneByCode($decklist->getTargetFormat()) : null,
            // the release date: set when publishing, then only while withdrawn
            'content_editable' => $mode === FormMode::Create || $decklist->isContentEditable(),
            'previous' => null,
            'lines' => [],
            'source_deck' => null,
            'duplicate_warning' => null,
        ]);
    }

    /**
     * What the decklist form sends, onto the decklist; the mode and state decide what may
     * change. The notes fields are the deckbuilder's Notes tab, shared (Ketura, 2026-11-07).
     */
    private function applyDecklistForm(PublishedDeck $decklist, Request $request, FormMode $mode, Texts $texts): void
    {
        if (!$this->isCsrfTokenValid('decklist_form', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('The form has expired; reload the page and try again.');
        }
        $form = $request->request;
        $em = $this->managerRegistry->getManager();

        // an empty name keeps the one it had: the deck's when publishing
        $name = mb_substr(trim(strip_tags((string) $form->get('name'))), 0, 60) ?: ($decklist->getName() ?: 'Untitled');
        $descriptionMd = trim((string) $form->get('description_'));
        // what the commentary said before is kept (Ketura, 2026-11-07)
        if ($mode === FormMode::Edit && ($name !== $decklist->getName() || $descriptionMd !== trim((string) $decklist->getDescriptionMd()))) {
            $em->persist(new PublishedDeckRevision($decklist));
        }
        $decklist->setName($name);
        $decklist->setNameCanonical($texts->slugify($name) . '-' . $decklist->getVersion());
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($texts->markdown($descriptionMd));

        // the rest of the notes: tags, the bid, starting notes and card annotations
        $tags = array_unique(array_filter(preg_split('/\s+/', strip_tags((string) $form->get('tags_')))));
        $decklist->setTags(mb_substr(implode(' ', $tags), 0, 4000) ?: null);
        $decklist->setStartingBid(mb_substr(trim(strip_tags((string) $form->get('starting_bid_'))), 0, 30) ?: null);
        $decklist->setStartingNotes(trim((string) $form->get('starting_notes_')) ?: null);
        // the starting fellowship: companions the decklist holds (Ketura, 2026-11-07)
        $starting = json_decode((string) $form->get('starting_'), true);
        if (is_array($starting)) {
            $companions = [];
            foreach ($decklist->getSlots() as $slot) {
                if ($slot->getCard()->getType()?->getCode() === 'companion') {
                    $companions[$slot->getCard()->getCode()] = true;
                }
            }
            $decklist->setStarting(array_values(array_filter($starting, fn ($code) => is_string($code) && isset($companions[$code]))));
        }
        $annotations = json_decode((string) $form->get('annotations_'), true);
        if (is_array($annotations)) {
            $rows = [];
            foreach ($annotations as $row) {
                if (is_array($row) && is_string($row['code'] ?? null) && preg_match('/^\d+_\d+$/', $row['code'])) {
                    $rows[] = ['code' => $row['code'], 'text' => mb_substr((string) ($row['text'] ?? ''), 0, 2000)];
                }
            }
            $decklist->setAnnotations($rows ?: null);
        }

        // derived from: a public decklist, by id or address, never the decklist itself
        $precedent = trim((string) $form->get('precedent'));
        if (!preg_match('/^\d+$/', $precedent)) {
            $precedent = preg_match('#/decklist/view/(\d+)#', $precedent, $matches) ? $matches[1] : null;
        }
        $precedent = ($precedent && (int) $precedent !== $decklist->getId())
            ? $em->getRepository(PublishedDeck::class)->find($precedent)
            : null;
        $decklist->setPrecedent($precedent && !$precedent->isWithdrawn() ? $precedent : null);

        $decklist->setThumbnailCard($this->thumbnailFrom($request, $decklist));
        if ($request->request->has('thumbnail_crop')) {
            $decklist->setThumbnailCrop($this->thumbnailCropFrom($request, $decklist));
        }

        if ($mode === FormMode::Create || $decklist->isContentEditable()) {
            $releaseDate = $this->dateFrom($form->get('effective_date'));
            if ($releaseDate) {
                $decklist->setEffectiveDate($releaseDate);
            }
        }
    }

    /**
     * Deleting a decklist is for moderators (Ketura, 2026-11-07): once published it
     * stays, and its author withdraws it within the grace period instead.
     */
    #[Route(path: '/decklist/delete/{decklist_id}', name: 'decklist_delete', methods: ['POST'], requirements: ['decklist_id' => '\d+'])]
    public function deleteAction($decklist_id, Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $em = $this->managerRegistry->getManager();

        /* @var PublishedDeckInterface $decklist */
        $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id)
            ?? throw $this->createNotFoundException("Published deck not found");

        $precedent = $decklist->getPrecedent();
        foreach ($decklist->getChildren() as $children_deck) {
            $children_deck->setParent($precedent);
        }
        foreach ($decklist->getSuccessors() as $successor_decklist) {
            $successor_decklist->setPrecedent($precedent);
        }

        $em->remove($decklist);
        $em->flush();

        return $this->redirectToRoute('decklists_list');
    }

    /**
     * The decklists page (Ketura, 2026-11-05): the search and filters, and the decks they find;
     * see PublishedDeckManager::getFilters.
     */
    #[Route(path: '/decklists', name: 'decklists_list', methods: ['GET'])]
    public function listAction(
        Request $request,
        TranslatorInterface $translator,
        PublishedDeckManager $decklistManager,
        FormatRepository $formats,
    ) {
        $filters = $decklistManager->getFilters();
        $user = $this->getUser();

        // the page's own requests as the filters change get the parts it swaps in, as JSON
        // (Ketura, 2026-11-05); the same address answers both, so caches keep them apart
        $response = $request->isXmlHttpRequest() ? new JsonResponse() : new Response();
        if (!empty($filters['mine']) || !empty($filters['favorites'])) {
            $response->setPrivate();
        } else {
            $response->setPublic();
            $response->setMaxAge(60);
        }
        $response->setVary(['X-Requested-With']);

        $decklistManager->setLimit($filters['limit'] ?? 50);
        $decklistManager->setPage(max(1, (int) $request->query->get('page', 1)));
        $decklists = $decklistManager->browse($filters, $user);

        $allFormats = $formats->findAllByCode();
        $usedFormats = [];
        foreach ($decklistManager->countFormats() as $code => $count) {
            $usedFormats[] = ['code' => $code, 'name' => isset($allFormats[$code]) ? $allFormats[$code]->getName() : $code, 'count' => $count];
        }
        $cards = empty($filters['cards']) ? []
            : $this->managerRegistry->getRepository(Card::class)->findBy(['code' => $filters['cards']]);

        $params = [
            'pagetitle' => $translator->trans('decklist.list.title'),
            'pagedescription' => 'Browse thousands of Lord of the Rings TCG decks, from the Decipher era to today.',
            'decklists' => $decklists,
            'total' => $decklistManager->getMaxCount(),
            'filters' => $filters,
            'formats' => $usedFormats,
            'format_names' => array_map(fn ($f) => $f->getName(), $allFormats),
            'cultures' => $this->managerRegistry->getRepository(Culture::class)->findBy([], ['name' => 'ASC']),
            'cards' => $cards,
            'pager' => $decklistManager->getPager(9),
        ];

        if ($response instanceof JsonResponse) {
            return $response->setData([
                'top' => $this->renderBlockView('Decklist/decklists.html.twig', 'browse_top', $params),
                'fields' => $this->renderBlockView('Decklist/decklists.html.twig', 'browse_fields', $params),
                'results' => $this->renderBlockView('Decklist/decklists.html.twig', 'browse_results', $params),
            ]);
        }
        return $this->render('Decklist/decklists.html.twig', $params, $response);
    }

    /**
     * Displays the content of a decklist along with comments, siblings, similar, etc.
     *
     *
     * @param TranslatorInterface $translator
     * @param int $cacheExpiration
     * @param string $decklist_id
     * @return Response
     */
    #[Route(path: '/decklist/view/{decklist_id}/{decklist_name}', name: 'decklist_detail', methods: ['GET'], defaults: ['decklist_name' => null], requirements: ['decklist_id' => '\d+'])]
    public function viewAction(TranslatorInterface $translator, int $cacheExpiration, FormatRepository $formats, \App\Services\ReaderNotes $readerNotes, $decklist_id)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $decklistRepo = $this->managerRegistry->getManager()->getRepository(PublishedDeck::class);

        $decklist = $decklistRepo->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException($translator->trans('decklist.view.errors.notfound'));
        }

        // a withdrawn decklist (Ketura, 2026-11-07): its author sees it, everyone else
        // learns it was withdrawn; never cached, since who's asking decides
        if ($decklist->isWithdrawn()) {
            $response->setPrivate();
            $response->setMaxAge(0);
            $user = $this->getUser();
            if (!$user || ($user->getId() !== $decklist->getUser()->getId() && !$this->isGranted('ROLE_SUPER_ADMIN'))) {
                $response->setStatusCode(Response::HTTP_GONE);
                return $this->render('Decklist/withdrawn.html.twig', [
                    'pagetitle' => $translator->trans('decklist.state.gone_title'),
                ], $response);
            }
        }

        $duplicate = $decklistRepo->findDuplicate($decklist);
        if (
            !$duplicate
            || $duplicate->getDateCreation() >= $decklist->getDateCreation()
            || $duplicate->getId() === $decklist->getId()
        ) {
            $duplicate = null;
        }

        $commenters = array_map(
            function (CommentInterface $comment) {
                return $comment->getUser()->getUserIdentifier();
            },
            $decklist->getComments()->getValues()
        );

        $versions = $decklistRepo->findVersions($decklist);
        $precedent = $decklist->getPrecedent();

        return $this->render(
            'Decklist/decklist.html.twig',
            array(
                'pagetitle' => $decklist->getName(),
                'decklist' => $decklist,
                'duplicate' => $duplicate,
                'commenters' => $commenters,
                'versions' => $versions,
                'newer' => $decklistRepo->findNewerVersion($decklist),
                // what it came from and led to, leaving out withdrawn decklists
                'precedent' => $precedent && !$precedent->isWithdrawn() ? $precedent : null,
                'successors' => array_filter($decklist->getSuccessors()->toArray(), fn (PublishedDeck $d) => !$d->isWithdrawn()),
                'format' => $formats->findOneByCode($decklist->getTargetFormat()),
                'reader' => $readerNotes->build($decklist),
            ),
            $response
        );
    }

    /**
     * Adds a decklist to a user's list of favorites.
     *
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    #[Route(path: '/user/favorite', name: 'decklist_favorite', methods: ['POST'])]
    public function favoriteAction(Request $request)
    {
        /* @var $em EntityManager */
        $em = $this->managerRegistry->getManager();

        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);

        /* @var PublishedDeckInterface $decklist */
        $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id);
        // a withdrawn decklist takes no comments or likes (Ketura, 2026-11-07)
        if (!$decklist || $decklist->isWithdrawn()) {
            throw new NotFoundHttpException('Wrong id');
        }

        $author = $decklist->getUser();

        $dbh = $this->managerRegistry->getConnection();
        $is_favorite = $dbh->executeQuery(
            "SELECT
                count(*)
                from published_deck d
                join favorite f on f.published_deck_id=d.id
                where f.user_id=?
                and d.id=?",
            array(
                $user->getId(),
                $decklist_id,
            )
        )
            ->fetchOne();

        if ($is_favorite) {
            $decklist->setNbfavorites($decklist->getNbFavorites() - 1);
            $user->removeFavorite($decklist);
            if ($author->getId() != $user->getId()) {
                $author->setReputation($author->getReputation() - 5);
            }
        } else {
            $decklist->setNbfavorites($decklist->getNbFavorites() + 1);
            $user->addFavorite($decklist);
            $decklist->setDateUpdate(new DateTime());
            if ($author->getId() != $user->getId()) {
                $author->setReputation($author->getReputation() + 5);
            }
        }
        $this->managerRegistry->getManager()->flush();

        return new Response($decklist->getNbFavorites());
    }

    /**
     * Records a user's comment.
     *
     *
     * @param Request $request
     * @param Texts $texts
     * @param MailerInterface $mailer
     * @param string $emailSenderAddress
     * @return RedirectResponse
     */
    #[Route(path: '/user/comment', name: 'decklist_comment', methods: ['POST'])]
    public function commentAction(
        Request $request,
        Texts $texts,
        MailerInterface $mailer,
        string $emailSenderAddress
    ) {
        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        $decklist = $this->managerRegistry
            ->getRepository(PublishedDeck::class)
            ->find($decklist_id);

        $comment_text = trim($request->get('comment'));
        if ($decklist && !$decklist->isWithdrawn() && !empty($comment_text)) {
            $comment_text = preg_replace(
                '%(?<!\()\b(?:(?:https?|ftp)://)(?:((?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)'
                . '(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))'
                . '(?::\d+)?)(?:[^\s]*)?%iu',
                '[$1]($0)',
                $comment_text
            );

            $mentioned_usernames = [];
            $matches = [];
            if (preg_match_all('/`@([\w_]+)`/', $comment_text, $matches, PREG_PATTERN_ORDER)) {
                $mentioned_usernames = array_unique($matches[1]);
            }

            $comment_html = $texts->markdown($comment_text);

            $now = new DateTime();

            $comment = new Comment();
            $comment->setText($comment_html);
            $comment->setDateCreation($now);
            $comment->setUser($user);
            $comment->setDecklist($decklist);
            $comment->setIsHidden(false);

            $this->managerRegistry
                ->getManager()
                ->persist($comment);
            $decklist->setDateUpdate($now);
            $decklist->setNbcomments($decklist->getNbcomments() + 1);

            $this->managerRegistry
                ->getManager()
                ->flush();

            // send emails
            $spool = [];
            if ($decklist->getUser()->getIsNotifAuthor()) {
                if (!isset($spool[$decklist->getUser()->getEmail()])) {
                    $spool[$decklist->getUser()->getEmail()] = 'Emails/newcomment_author.html.twig';
                }
            }
            foreach ($decklist->getComments() as $comment) {
                /* @var CommentInterface $comment */
                $commenter = $comment->getUser();
                if ($commenter && $commenter->getIsNotifCommenter()) {
                    if (!isset($spool[$commenter->getEmail()])) {
                        $spool[$commenter->getEmail()] = 'Emails/newcomment_commenter.html.twig';
                    }
                }
            }
            foreach ($mentioned_usernames as $mentioned_username) {
                /* @var UserInterface $mentioned_user */
                $mentioned_user = $this->managerRegistry
                    ->getRepository(User::class)
                    ->findOneBy(array('username' => $mentioned_username));
                if ($mentioned_user && $mentioned_user->getIsNotifMention()) {
                    if (!isset($spool[$mentioned_user->getEmail()])) {
                        $spool[$mentioned_user->getEmail()] = 'Emails/newcomment_mentionned.html.twig';
                    }
                }
            }
            unset($spool[$user->getEmail()]);

            $email_data = array(
                'username' => $user->getUserIdentifier(),
                'decklist_name' => $decklist->getName(),
                'url' => $this->generateUrl(
                    'decklist_detail',
                    array(
                            'decklist_id' => $decklist->getId(),
                            'decklist_name' => $decklist->getNameCanonical(),
                        ),
                    UrlGeneratorInterface::ABSOLUTE_URL
                ) . '#' . $comment->getId(),
                'comment' => $comment_html,
                'profile' => $this->generateUrl('user_profile_edit', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            foreach ($spool as $email => $view) {
                $message = (new Email())
                    ->subject("[thronesdb] New comment")
                    ->from(new Address($emailSenderAddress, $user->getUserIdentifier()))
                    ->to($email)
                    ->html($this->renderView($view, $email_data));
                $mailer->send($message);
            }
        }

        return $this->redirectToRoute('decklist_detail', array(
            'decklist_id' => $decklist_id,
            'decklist_name' => $decklist->getNameCanonical(),
        ));
    }

    /**
     * Hides a comment, or if $hidden is false, unhide a comment.
     *
     *
     * @param string $comment_id
     * @param string $hidden
     * @return Response
     * @throws ORMException
     * @throws OptimisticLockException
     */
    #[Route(path: '/user/hidecomment/{comment_id}/{hidden}', name: 'decklist_comment_hide', methods: ['POST'])]
    public function hidecommentAction($comment_id, $hidden)
    {
        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        /* @var $em EntityManager */
        $em = $this->managerRegistry->getManager();

        $comment = $em->getRepository(Comment::class)->find($comment_id);
        if (!$comment) {
            throw new BadRequestHttpException('Unable to find comment');
        }

        if ($comment->getDecklist()->getUser()->getId() !== $user->getId()) {
            return new Response(json_encode("You don't have permission to edit this comment."));
        }

        $comment->setIsHidden((bool)$hidden);
        $em->flush();

        return new Response(json_encode(true));
    }

    /**
     * Records a user's vote.
     *
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    #[Route(path: '/user/like', name: 'decklist_like', methods: ['POST'])]
    public function voteAction(Request $request)
    {
        /* @var EntityManager $em */
        $em = $this->managerRegistry->getManager();

        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);

        $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id);
        if (!$decklist || $decklist->isWithdrawn()) {
            throw new NotFoundHttpException('Wrong id');
        }

        if ($decklist->getUser()->getId() != $user->getId()) {
            $query = $em->getRepository(PublishedDeck::class)
                ->createQueryBuilder('d')
                ->innerJoin('d.votes', 'u')
                ->where('d.id = :decklist_id')
                ->andWhere('u.id = :user_id')
                ->setParameter('decklist_id', $decklist_id)
                ->setParameter('user_id', $user->getId())
                ->getQuery();

            $result = $query->getResult();
            if (empty($result)) {
                $user->addVote($decklist);
                $author = $decklist->getUser();
                $author->setReputation($author->getReputation() + 1);
                $decklist->setDateUpdate(new DateTime());
                $decklist->setNbVotes($decklist->getNbVotes() + 1);
                $this->managerRegistry->getManager()->flush();
            } else {
                $user->removeVote($decklist);
                $author = $decklist->getUser();
                $author->setReputation($author->getReputation() - 1);
                // a fire goes with the like it was added to (Ketura, 2026-11-04)
                if ($decklist->getFires()->contains($user)) {
                    $decklist->getFires()->removeElement($user);
                    $decklist->setNbFires($decklist->getNbFires() - 1);
                    $author->setReputation($author->getReputation() - PublishedDeck::FIRE_WEIGHT);
                }
                $decklist->setDateUpdate(new DateTime());
                $decklist->setNbVotes($decklist->getNbVotes() - 1);
                $this->managerRegistry->getManager()->flush();
            }
        }

        return new Response($decklist->getNbVotes());
    }

    /**
     * Sets a decklist on fire, or puts the fire out (Ketura, 2026-11-04): a super-upvote,
     * open to someone who has liked the deck and isn't its author. It counts as
     * PublishedDeck::FIRE_WEIGHT more likes in the popularity sorts, and in the author's
     * reputation. Answers {fires, on}.
     */
    #[Route(path: '/user/fire', name: 'decklist_fire', methods: ['POST'])]
    public function fireAction(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to set a deck on fire.');
        }
        $em = $this->managerRegistry->getManager();
        /** @var PublishedDeck|null $decklist */
        $decklist = $em->getRepository(PublishedDeck::class)->find((int) $request->get('id'));
        if (!$decklist || $decklist->isWithdrawn()) {
            throw new NotFoundHttpException('Wrong id');
        }
        $author = $decklist->getUser();
        $on = $decklist->getFires()->contains($user);
        if ($author->getId() === $user->getId() || (!$on && !$decklist->getVotes()->contains($user))) {
            return new JsonResponse(['fires' => $decklist->getNbFires(), 'on' => $on], Response::HTTP_FORBIDDEN);
        }
        if ($on) {
            $decklist->getFires()->removeElement($user);
            $decklist->setNbFires($decklist->getNbFires() - 1);
            $author->setReputation($author->getReputation() - PublishedDeck::FIRE_WEIGHT);
        } else {
            $decklist->getFires()->add($user);
            $decklist->setNbFires($decklist->getNbFires() + 1);
            $author->setReputation($author->getReputation() + PublishedDeck::FIRE_WEIGHT);
        }
        $decklist->setDateUpdate(new DateTime());
        $em->flush();
        return new JsonResponse(['fires' => $decklist->getNbFires(), 'on' => !$on]);
    }

    /**
     * (Unused) returns an ordered list of decklists similar to the one given.
     * @todo This is not wired up, enable or remove this. [ST 2020/07/28]
     */
    public function findSimilarDecklists($decklist_id, $number)
    {
        $dbh = $this->managerRegistry->getConnection();

        $list = $dbh->executeQuery(
            "SELECT
                l.id,
                (
                    SELECT COUNT(s.id)
                    FROM published_deck_slot s
                    WHERE (
                        s.published_deck_id=l.id
                        AND s.card_id NOT IN (
                            SELECT t.card_id
                            FROM published_deck_slot t
                            WHERE t.published_deck_id=?
                        )
                    )
                    OR
                    (
                        s.published_deck_id=?
                        AND s.card_id NOT IN (
                            SELECT t.card_id
                            FROM published_deck_slot t
                            WHERE t.published_deck_id=l.id
                        )
                    )
                ) difference
                 FROM published_deck l
                WHERE l.id!=?
                ORDER BY difference ASC
                LIMIT 0,$number",
            array(
                $decklist_id,
                $decklist_id,
                $decklist_id,
            )
        )->fetchAllAssociative();

        $arr = [];
        foreach ($list as $item) {
            $dbh = $this->managerRegistry->getConnection();
            $rows = $dbh->executeQuery(
                "SELECT
                    d.id,
                    d.name,
                    d.name_canonical,
                    d.nb_votes,
                    d.nb_favorites,
                    d.nb_comments
                    from published_deck d
                    where d.id=?
                    ",
                array(
                    $item["id"],
                )
            )->fetchAllAssociative();

            $decklist = $rows[0];
            $arr[] = $decklist;
        }

        return $arr;
    }

    /**
     *
     * @param Request $request
     * @param int $cacheExpiration
     * @param Texts $texts
     * @param int $decklist_id
     * @return Response
     */
    #[Route(path: '/decklist/export/text/{decklist_id}', name: 'decklist_download', methods: ['GET'], requirements: ['decklist_id' => '\d+'])]
    public function downloadAction(Request $request, int $cacheExpiration, Texts $texts, $decklist_id)
    {
        /* @var EntityManager $em */
        $em = $this->managerRegistry->getManager();

        /* @var PublishedDeckInterface $decklist */
        $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id);
        if (!$decklist || ($decklist->isWithdrawn() && $decklist->getUser()->getId() !== $this->getUser()?->getId())) {
            throw new NotFoundHttpException("Unable to find decklist.");
        }

        if ($request->query->get('format') === 'gemp') {
            $content = $decklist->getSlots()->toGempString() . "\r\n";
        } else {
            $content = str_replace("\n", "\r\n", $this->renderView('Export/default.txt.twig', ['deck' => $decklist->getTextExport()]));
        }
        $response = new Response($content);
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            // the account first, then the deck (Ketura, 2026-11-04): "ketura_my-deck.txt"
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $texts->slugify($decklist->getUser()->getDisplayName()) . '_' . $texts->slugify($decklist->getName()) . '.txt')
        );
        return $response;
    }

    /** The thumbnail card the form chose: one of the deck's cards, sites included (Ketura, 2026-11-07); none for the cultures */
    private function thumbnailFrom(Request $request, PublishedDeck $decklist): ?Card
    {
        $code = (string) $request->request->get('thumbnail');
        foreach ($decklist->getSlots() as $slot) {
            if ($slot->getCard()->getCode() === $code) {
                return $slot->getCard();
            }
        }
        return null;
    }

    /**
     * The thumbnail framing the crop editor set (Ketura, 2026-11-07): for one of the deck's
     * cards, the picture at least as wide as the tile and covering it; anything else clears it
     */
    private function thumbnailCropFrom(Request $request, PublishedDeck $decklist): ?array
    {
        $crop = json_decode((string) $request->request->get('thumbnail_crop'), true);
        if (!is_array($crop) || !is_string($crop['code'] ?? null)) {
            return null;
        }
        $found = false;
        foreach ($decklist->getSlots() as $slot) {
            $found = $found || $slot->getCard()->getCode() === $crop['code'];
        }
        $w = $crop['w'] ?? null;
        $l = $crop['l'] ?? null;
        $t = $crop['t'] ?? null;
        if (!$found || !is_numeric($w) || !is_numeric($l) || !is_numeric($t)) {
            return null;
        }
        [$w, $l, $t] = [round((float) $w, 2), round((float) $l, 2), round((float) $t, 2)];
        // the editor's smallest box is an eighth of the picture, and the box stays on it
        if ($w < 100 || $w > 2000 || $l > 0 || $l < 100 - $w || $t > 0 || $t < -2 * $w) {
            return null;
        }
        return ['code' => $crop['code'], 'w' => $w, 'l' => $l, 't' => $t];
    }

    private function dateFrom(mixed $value): ?DateTime
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        return DateTime::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /**
     * @param $page
     * @param Request $request
     * @return Response
     * @throws \Doctrine\DBAL\Exception
     *
     * @todo This is not wired up, enable or remove this. [ST 2020/07/28]
     */
    public function usercommentsAction($page, Request $request)
    {
        $response = new Response();
        $response->setPrivate();

        /* @var UserInterface $user */
        $user = $this->getUser();

        $limit = 100;
        if ($page < 1) {
            $page = 1;
        }
        $start = ($page - 1) * $limit;

        /* @var $dbh Connection */
        $dbh = $this->managerRegistry->getConnection();

        $comments = $dbh->executeQuery(
            "SELECT SQL_CALC_FOUND_ROWS
                c.id,
                c.text,
                c.date_creation,
                d.id decklist_id,
                d.name decklist_name,
                d.name_canonical decklist_name_canonical
                from comment c
                join published_deck d on c.published_deck_id=d.id
                where c.user_id=?
                order by date_creation desc
                limit $start, $limit",
            array(
                $user->getId(),
            )
        )
            ->fetchAllAssociative();

        $maxcount = $dbh->executeQuery("SELECT FOUND_ROWS()")->fetchOne();

        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page

        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);

        $route = $request->get('_route');

        $pages = [];
        for ($page = 1; $page <= $nbpages; $page++) {
            $pages[] = array(
                "numero" => $page,
                "url" => $this->generateUrl(
                    $route,
                    array(
                        "page" => $page,
                    )
                ),
                "current" => $page == $currpage,
            );
        }

        return $this->render(
            'Default/usercomments.html.twig',
            array(
                'user' => $user,
                'comments' => $comments,
                'url' => $request
                    ->getRequestUri(),
                'route' => $route,
                'pages' => $pages,
                'prevurl' => $currpage == 1 ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $prevpage,
                    )
                ),
                'nexturl' => $currpage == $nbpages ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $nextpage,
                    )
                ),
            ),
            $response
        );
    }

    /**
     * @param Request $request
     * @param int $cacheExpiration
     * @param int $page
     * @return Response
     * @throws \Doctrine\DBAL\Exception
     *
     * @todo This is not wired up, enable or remove this. [ST 2020/07/28]
     */
    public function commentsAction(Request $request, int $cacheExpiration, $page)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $limit = 100;
        if ($page < 1) {
            $page = 1;
        }
        $start = ($page - 1) * $limit;

        /* @var $dbh Connection */
        $dbh = $this->managerRegistry->getConnection();

        $comments = $dbh->executeQuery(
            "SELECT SQL_CALC_FOUND_ROWS
                c.id,
                c.text,
                c.date_creation,
                d.id decklist_id,
                d.name decklist_name,
                d.name_canonical decklist_name_canonical,
                u.id user_id,
                u.username author
                from comment c
                join published_deck d on c.published_deck_id=d.id
                join user u on c.user_id=u.id
                order by date_creation desc
                limit $start, $limit",
            []
        )->fetchAllAssociative();

        $maxcount = $dbh->executeQuery("SELECT FOUND_ROWS()")->fetchOne();

        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page
        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);

        $route = $request->get('_route');

        $pages = [];
        for ($page = 1; $page <= $nbpages; $page++) {
            $pages[] = array(
                "numero" => $page,
                "url" => $this->generateUrl(
                    $route,
                    array(
                        "page" => $page,
                    )
                ),
                "current" => $page == $currpage,
            );
        }

        return $this->render(
            'Default/allcomments.html.twig',
            array(
                'comments' => $comments,
                'url' => $request
                    ->getRequestUri(),
                'route' => $route,
                'pages' => $pages,
                'prevurl' => $currpage == 1 ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $prevpage,
                    )
                ),
                'nexturl' => $currpage == $nbpages ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $nextpage,
                    )
                ),
            ),
            $response
        );
    }

}
