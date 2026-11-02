<?php

namespace App\Controller;

use App\Classes\DeckContent;
use App\Entity\BannerAlert;
use App\Entity\Deck;
use App\Entity\Deckchange;
use App\Entity\DeckInterface;
use App\Entity\Decklist;
use App\Entity\Format;
use App\Entity\Tournament;
use App\Entity\UserInterface;
use App\Repository\FormatRepository;
use App\Services\DeckImportService;
use App\Services\DeckManager;
use App\Services\DeckValidator;
use App\Services\Diff;
use App\Services\FormatGuesser;
use App\Services\Gemp\GempClient;
use App\Services\Gemp\GempRejectedException;
use App\Services\Gemp\GempUnavailableException;
use App\Services\Texts;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The deckbuilder and the user's own decks (phase3/03).
 */
class BuilderController extends AbstractController
{
    private const UUID = '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly FormatRepository $formats,
    ) {
    }

    #[Route(path: '/deck/new', name: 'deck_buildform', methods: ['GET'])]
    public function buildformAction(TranslatorInterface $translator, DeckManager $deckManager): Response
    {
        return $this->render('Builder/initbuild.html.twig', [
            'pagetitle' => $translator->trans('decks.form.new'),
            'formats' => $this->formats->findSelectable(),
            'default_format' => $deckManager->getDefaultFormat(),
        ]);
    }

    #[Route(path: '/deck/build', name: 'deck_initbuild', methods: ['POST'])]
    public function initbuildAction(Request $request, TranslatorInterface $translator, DeckManager $deckManager): Response
    {
        $user = $this->requireUser();
        if (count($user->getDecks()) >= $user->getMaxNbDecks()) {
            $this->addFlash('error', $translator->trans('decks.save.outOfSlots'));
            return $this->redirectToRoute('decks_list');
        }
        $format = $this->selectableFormat((string) $request->request->get('target_format'));
        if ($format === null) {
            $this->addFlash('error', $translator->trans('decks.build.errors.noformat'));
            return $this->redirectToRoute('deck_buildform');
        }
        $name = trim(strip_tags((string) $request->request->get('name')))
            ?: $translator->trans('decks.build.newname_format', ['%format%' => $format->getName()]);

        $deck = $deckManager->create($user, $name, $format->getCode());
        $this->managerRegistry->getManager()->flush();

        return $this->redirectToRoute('deck_edit', ['deck_uuid' => $deck->getUuid()]);
    }

    #[Route(path: '/deck/import', name: 'deck_import', methods: ['GET'])]
    public function importAction(TranslatorInterface $translator, DeckManager $deckManager): Response
    {
        return $this->render('Builder/directimport.html.twig', [
            'pagetitle' => $translator->trans('decks.import.title'),
            'formats' => $this->formats->findSelectable(),
            'default_format' => $deckManager->getDefaultFormat(),
        ]);
    }

    /**
     * Imports pasted text or an uploaded text file: Gemp deck strings or card lists,
     * several decks separated by "===" lines (DeckImportService).
     */
    #[Route(path: '/deck/fileimport', name: 'deck_fileimport', methods: ['POST'])]
    public function fileimportAction(
        Request $request,
        DeckImportService $deckImportService,
        DeckManager $deckManager,
        TranslatorInterface $translator,
        FormatGuesser $guesser,
    ): Response {
        $user = $this->requireUser();
        $text = (string) $request->request->get('content');
        $uploadedFile = $request->files->get('upfile');
        if ($uploadedFile !== null) {
            $mime = (string) $uploadedFile->getMimeType();
            if (!str_starts_with($mime, 'text') && $mime !== 'application/octet-stream') {
                throw new BadRequestHttpException('Unsupported file format');
            }
            $text = (string) file_get_contents($uploadedFile->getPathname());
        }
        if (trim($text) === '') {
            $this->addFlash('error', $translator->trans('decks.import.error.empty'));
            return $this->redirectToRoute('deck_import');
        }
        $format = $this->selectableFormat((string) $request->request->get('target_format'));
        $parsed = $deckImportService->parseTextImport($text);

        if (count($user->getDecks()) + count($parsed['decks']) > $user->getMaxNbDecks()) {
            $this->addFlash('error', $translator->trans('decks.save.outOfSlots'));
            return $this->redirectToRoute('decks_list');
        }

        $last = null;
        foreach ($parsed['decks'] as $data) {
            $code = $format?->getCode()
                ?? $guesser->guessContent($data['content'])?->getCode()
                ?? (string) $deckManager->getDefaultFormat();
            $last = $deckManager->create($user, $data['name'], $code, $data['content']);
            foreach ($data['warnings'] as $warning) {
                $this->addFlash('warning', $data['name'] . ': ' . $warning);
            }
        }
        $this->managerRegistry->getManager()->flush();

        foreach (array_unique($parsed['errors']) as $error) {
            $this->addFlash('error', $error);
        }
        if (count($parsed['decks'])) {
            $this->addFlash('notice', $translator->trans('decks.import.imported', ['%count%' => count($parsed['decks'])]));
        }
        // a single deck opens in the deckbuilder
        if (count($parsed['decks']) === 1 && $last !== null) {
            return $this->redirectToRoute('deck_edit', ['deck_uuid' => $last->getUuid()]);
        }
        return $this->redirectToRoute('decks_list');
    }

    /** Text export: format=text (card list, the default) or format=gemp (Gemp deck string) */
    #[Route(path: '/deck/export/{deck_uuid}', name: 'deck_download', methods: ['GET'], requirements: ['deck_uuid' => self::UUID])]
    public function downloadAction(Request $request, Texts $texts, string $deck_uuid): Response
    {
        $deck = $this->findViewableDeck($deck_uuid);
        $content = $request->query->get('format') === 'gemp'
            ? $deck->getSlots()->toGempString() . "\r\n"
            : str_replace("\n", "\r\n", $this->renderView('Export/default.txt.twig', ['deck' => $deck->getTextExport()]));

        return $this->textFile($content, $texts->slugify($deck->getName()) . '.txt');
    }

    #[Route(path: '/deck/clone/{deck_uuid}', name: 'deck_clone', methods: ['GET'], requirements: ['deck_uuid' => self::UUID])]
    public function cloneAction(string $deck_uuid, DeckManager $deckManager, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        $deck = $this->findViewableDeck($deck_uuid);
        if (count($user->getDecks()) >= $user->getMaxNbDecks()) {
            $this->addFlash('error', $translator->trans('decks.save.outOfSlots'));
            return $this->redirectToRoute('decks_list');
        }
        $deckManager->copy($user, $deck, $deck->getName() . ' (clone)');
        $this->managerRegistry->getManager()->flush();
        return $this->redirectToRoute('decks_list');
    }

    #[Route(path: '/deck/save', name: 'deck_save', methods: ['POST'])]
    public function saveAction(Request $request, DeckManager $deckManager, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        $em = $this->managerRegistry->getManager();

        $id = (int) $request->get('id');
        $deck = null;
        $sourceDeck = null;
        if ($id) {
            $deck = $em->getRepository(Deck::class)->find($id);
            if (!$deck || $user->getId() != $deck->getUser()->getId()) {
                throw new AccessDeniedHttpException("You don't have access to this deck.");
            }
            $sourceDeck = $deck;
        }

        if ($request->get('cancel_edits')) {
            if ($deck) {
                $deckManager->revert($deck);
            }
            return $this->redirectToRoute('decks_list');
        }

        if ($request->get('copy') || !$id) {
            if (count($user->getDecks()) >= $user->getMaxNbDecks()) {
                $this->addFlash('error', $translator->trans('decks.save.outOfSlots'));
                return $this->redirectToRoute('decks_list');
            }
            $parentDeck = $id ? $deck : null;
            $deck = new Deck();
            $deck->setUuid(Uuid::uuid4());
            $parentId = (int) $request->get('parent_deck_id');
            if ($parentId) {
                $parentDeck = $em->getRepository(Deck::class)->find($parentId);
            }
            if ($parentDeck) {
                $deck->setParentDeck($parentDeck);
            }
            $sourceDeck = null;
        }

        $content = json_decode((string) $request->get('content'), true);
        if (!is_array($content)) {
            throw new BadRequestHttpException('Deck content missing');
        }
        $format = $this->selectableFormat((string) $request->get('target_format'));

        $deckManager->save($user, $deck, [
            'name' => strip_tags((string) $request->get('name')),
            'description' => trim((string) $request->get('description')),
            'tags' => strip_tags((string) $request->get('tags')),
            'content' => $content,
            'target_format' => $format?->getCode() ?? ($deck->getTargetFormat() ?: null),
            'effective_date' => $request->get('effective_date') ?: null,
            'decklist_id' => (int) $request->get('decklist_id') ?: null,
            'shared' => $request->request->has('shared') ? (bool) $request->get('shared') : null,
        ], $sourceDeck);
        $em->flush();

        return $this->redirectToRoute('deck_view', ['deck_uuid' => $deck->getUuid()]);
    }

    /**
     * The deckbuilder's live checklist (decision 0019): the content as the browser
     * holds it, validated by Gemp. Debounced on the browser side.
     */
    #[Route(path: '/deck/validate', name: 'deck_validate', methods: ['POST'])]
    public function validateAction(Request $request, DeckValidator $validator): JsonResponse
    {
        $this->requireUser();
        $content = json_decode((string) $request->get('content'), true);
        $format = (string) $request->get('target_format');
        if (!is_array($content) || $format === '') {
            throw new BadRequestHttpException('content and target_format are required');
        }
        $validation = $validator->validateContent($content, $format);
        return new JsonResponse([
            'available' => $validation !== null,
            'validation' => $validation?->toArray(),
            'valid' => $validation?->isValid(),
        ]);
    }

    /**
     * "Convert to PC": swaps each card for its PC errata in the format, through
     * Gemp (decision 0021). Returns the new content; the deckbuilder applies it.
     */
    #[Route(path: '/deck/convert', name: 'deck_convert', methods: ['POST'])]
    public function convertAction(Request $request, DeckValidator $validator, GempClient $gemp, DeckManager $deckManager): JsonResponse
    {
        $this->requireUser();
        $content = json_decode((string) $request->get('content'), true);
        $format = (string) $request->get('target_format');
        if (!is_array($content) || $format === '') {
            throw new BadRequestHttpException('content and target_format are required');
        }
        [$triples] = $validator->resolve($deckManager->normalizeContent($content));
        try {
            $converted = $gemp->convertToErrata($format, DeckContent::toGempString($triples));
        } catch (GempUnavailableException) {
            return new JsonResponse(['success' => false, 'error' => 'gemp_unavailable'], 503);
        } catch (GempRejectedException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
        return new JsonResponse([
            'success' => true,
            'content' => DeckContent::fromGempString((string) $converted['deckContents']),
        ]);
    }

    /**
     * "Suggest a format": FormatGuesser's ranked formats for the content as the
     * browser holds it, each checked by Gemp (the top few) so the reasons can be
     * compared with Gemp's verdict.
     */
    #[Route(path: '/deck/suggest-format', name: 'deck_suggest_format', methods: ['POST'])]
    public function suggestFormatAction(Request $request, FormatGuesser $guesser, DeckValidator $validator, DeckManager $deckManager): JsonResponse
    {
        $this->requireUser();
        $content = json_decode((string) $request->get('content'), true);
        if (!is_array($content)) {
            throw new BadRequestHttpException('content is required');
        }
        $content = $deckManager->normalizeContent($content);
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->get('effective_date')) ?: null;
        $guess = $guesser->guess($guesser->slotsFromContent($content), $date);
        $suggestions = [];
        foreach (array_slice($guess['candidates'], 0, 5) as $i => $candidate) {
            $format = $candidate['format'];
            $row = ['code' => $format->getCode(), 'name' => $format->getName(), 'reasons' => $candidate['reasons'], 'gemp' => null];
            if ($i < 3) {
                $validation = $validator->validateContent($content, $format->getCode());
                $row['gemp'] = $validation === null ? null : ['valid' => $validation->isValid(), 'problems' => count($validation->getRelevantProblems())];
            }
            $suggestions[] = $row;
        }
        return new JsonResponse(['path' => $guess['path'], 'sets' => $guess['sets'], 'suggestions' => $suggestions]);
    }

    #[Route(path: '/deck/delete', name: 'deck_delete', methods: ['POST'])]
    public function deleteAction(Request $request): Response
    {
        $user = $this->requireUser();
        $em = $this->managerRegistry->getManager();
        $deck = $em->getRepository(Deck::class)->find((int) $request->get('deck_id'));
        if (!$deck) {
            return $this->redirectToRoute('decks_list');
        }
        if ($user->getId() != $deck->getUser()->getId()) {
            throw new AccessDeniedHttpException("You don't have access to this deck.");
        }
        $this->removeDeck($deck);
        $em->flush();
        $this->addFlash('notice', 'Deck deleted.');
        return $this->redirectToRoute('decks_list');
    }

    #[Route(path: '/deck/download_list', name: 'deck_download_list', methods: ['POST'])]
    public function downloadListAction(Request $request): Response
    {
        $user = $this->requireUser();
        $decks = $this->managerRegistry->getRepository(Deck::class)->findBy(['id' => explode('-', (string) $request->get('ids'))]);
        $exports = [];
        foreach ($decks as $deck) {
            if ($deck->getUser()->getId() === $user->getId()) {
                $exports[] = str_replace("\n", "\r\n", $this->renderView('Export/default.txt.twig', ['deck' => $deck->getTextExport()]));
            }
        }
        return $this->textFile(implode("\r\n===\r\n", $exports), 'decks.txt');
    }

    #[Route(path: '/deck/delete_list', name: 'deck_delete_list', methods: ['POST'])]
    public function deleteListAction(Request $request): Response
    {
        $user = $this->requireUser();
        $em = $this->managerRegistry->getManager();
        foreach (explode('-', (string) $request->get('ids')) as $id) {
            $deck = $em->getRepository(Deck::class)->find((int) $id);
            if ($deck && $user->getId() == $deck->getUser()->getId()) {
                $this->removeDeck($deck);
            }
        }
        $em->flush();
        $this->addFlash('notice', 'Decks deleted.');
        return $this->redirectToRoute('decks_list');
    }

    #[Route(path: '/deck/edit/{deck_uuid}', name: 'deck_edit', methods: ['GET'], requirements: ['deck_uuid' => self::UUID])]
    public function editAction(string $deck_uuid): Response
    {
        $user = $this->requireUser();
        $deck = $this->findDeck($deck_uuid);
        if ($user->getId() != $deck->getUser()->getId()) {
            throw new AccessDeniedHttpException('You are not allowed to edit this deck.');
        }
        return $this->render('Builder/deckedit.html.twig', [
            'pagetitle' => 'Deckbuilder',
            'deck' => $deck,
            'formats' => $this->formats->findSelectable(),
            'format' => $this->formats->findOneByCode($deck->getTargetFormat()),
        ]);
    }

    #[Route(path: '/deck/view/{deck_uuid}', name: 'deck_view', methods: ['GET'], requirements: ['deck_uuid' => self::UUID])]
    public function viewAction(string $deck_uuid): Response
    {
        $deck = $this->findViewableDeck($deck_uuid);
        return $this->render('Builder/deckview.html.twig', [
            'pagetitle' => $deck->getName(),
            'deck' => $deck,
            'is_owner' => $this->isOwner($deck),
            'format' => $this->formats->findOneByCode($deck->getTargetFormat()),
            'tournaments' => $this->managerRegistry->getRepository(Tournament::class)->findAll(),
        ]);
    }

    #[Route(path: '/deck/compare/{deck1_uuid}/{deck2_uuid}', name: 'decks_diff', methods: ['GET'], requirements: ['deck1_uuid' => self::UUID, 'deck2_uuid' => self::UUID])]
    public function compareAction(string $deck1_uuid, string $deck2_uuid, Diff $diff): Response
    {
        $deck1 = $this->findViewableDeck($deck1_uuid);
        $deck2 = $this->findViewableDeck($deck2_uuid);
        $parts = [];
        foreach (['adventure' => 'getSites', 'draw' => 'getDrawDeck'] as $part => $getter) {
            $parts[$part] = $diff->getSlotsDiff([$deck1->getSlots()->$getter(), $deck2->getSlots()->$getter()]);
        }
        return $this->render('Compare/deck_compare.html.twig', [
            'deck1' => $deck1,
            'deck2' => $deck2,
            'parts' => $parts,
        ]);
    }

    #[Route(path: '/decks', name: 'decks_list', methods: ['GET'])]
    public function listAction(DeckManager $deckManager, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        $decks = $deckManager->getByUser($user);
        $tournaments = $this->managerRegistry->getRepository(Tournament::class)->findBy([], ['description' => 'DESC']);

        if (!count($decks)) {
            return $this->render('Builder/no-decks.html.twig', [
                'pagetitle' => $translator->trans('nav.mydecks'),
                'nbmax' => $user->getMaxNbDecks(),
                'tournaments' => $tournaments,
            ]);
        }

        $tags = [];
        foreach ($decks as $deck) {
            foreach (preg_split('/\s+/', (string) $deck->getTags(), -1, PREG_SPLIT_NO_EMPTY) as $tag) {
                $tags[$tag] = true;
            }
        }
        ksort($tags);

        return $this->render('Builder/decks.html.twig', [
            'pagetitle' => $translator->trans('nav.mydecks'),
            'pagedescription' => 'Create custom decks with the help of a powerful deckbuilder.',
            'decks' => $decks,
            'tags' => array_keys($tags),
            'nbmax' => $user->getMaxNbDecks(),
            'nbdecks' => count($decks),
            'cannotcreate' => $user->getMaxNbDecks() <= count($decks),
            'tournaments' => $tournaments,
            'formats' => $this->formats->findAllByCode(),
            'banner_alerts' => $this->managerRegistry->getRepository(BannerAlert::class)->findBy(['active' => true], ['id' => 'DESC']),
        ]);
    }

    /** A new deck from a published decklist */
    #[Route(path: '/deck/copy/{decklist_id}', name: 'deck_copy', methods: ['GET'], requirements: ['decklist_id' => '\d+'])]
    public function copyAction(int $decklist_id, DeckManager $deckManager, TranslatorInterface $translator): Response
    {
        $user = $this->requireUser();
        $decklist = $this->managerRegistry->getRepository(Decklist::class)->find($decklist_id)
            ?? throw new NotFoundHttpException('No such decklist');
        if (count($user->getDecks()) >= $user->getMaxNbDecks()) {
            $this->addFlash('error', $translator->trans('decks.save.outOfSlots'));
            return $this->redirectToRoute('decks_list');
        }
        $deck = new Deck();
        $deck->setUuid(Uuid::uuid4());
        $deckManager->save($user, $deck, [
            'name' => $decklist->getName(),
            'description' => $decklist->getDescriptionMd(),
            'content' => $decklist->getSlots()->getContent(),
            'target_format' => $decklist->getTargetFormat(),
            'effective_date' => $decklist->getEffectiveDate(),
            'decklist_id' => $decklist->getId(),
        ]);
        $this->managerRegistry->getManager()->flush();
        return $this->redirectToRoute('deck_edit', ['deck_uuid' => $deck->getUuid()]);
    }

    /** Unsaved edits, as a diff, so the deckbuilder can restore them */
    #[Route(path: '/deck/autosave', name: 'deck_autosave', methods: ['POST'])]
    public function autosaveAction(Request $request, LoggerInterface $logger): Response
    {
        $user = $this->requireUser();
        $em = $this->managerRegistry->getManager();
        $deck = $em->getRepository(Deck::class)->find((int) $request->get('deck_id'))
            ?? throw new BadRequestHttpException('Cannot find deck');
        if ($user->getId() != $deck->getUser()->getId()) {
            throw new AccessDeniedHttpException("You don't have access to this deck.");
        }
        $diff = json_decode((string) $request->get('diff'), true);
        if (!is_array($diff) || count($diff) != 2) {
            $logger->error('cannot use diff', ['diff' => $request->get('diff')]);
            throw new BadRequestHttpException('Wrong content');
        }
        $date = new \DateTime();
        if (count($diff[0]) || count($diff[1])) {
            $change = new Deckchange();
            $change->setDeck($deck);
            $change->setVariation(json_encode($diff));
            $change->setIsSaved(false);
            $em->persist($change);
            $em->flush();
            $date = $change->getDatecreation();
        }
        return new Response($date->format('c'));
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw new AccessDeniedHttpException('You must be logged in.');
        }
        return $user;
    }

    private function findDeck(string $uuid): Deck
    {
        return $this->managerRegistry->getRepository(Deck::class)->findOneBy(['uuid' => $uuid])
            ?? throw new NotFoundHttpException("This deck doesn't exist.");
    }

    private function isOwner(DeckInterface $deck): bool
    {
        $user = $this->getUser();
        return $user instanceof UserInterface && $user->getId() == $deck->getUser()->getId();
    }

    /** Owner, a deck shared by link (0023), or an owner who shares all their decks */
    private function findViewableDeck(string $uuid): Deck
    {
        $deck = $this->findDeck($uuid);
        if (!$this->isOwner($deck) && !$deck->isShared() && !$deck->getUser()->getIsShareDecks()) {
            throw new AccessDeniedHttpException('You are not allowed to view this deck. Its owner can share it by link.');
        }
        return $deck;
    }

    private function selectableFormat(string $code): ?Format
    {
        $format = $code === '' ? null : $this->formats->findOneByCode($code);
        return $format !== null && $format->isSelectable() ? $format : null;
    }

    private function removeDeck(Deck $deck): void
    {
        foreach ($deck->getChildren() as $decklist) {
            $decklist->setParent(null);
        }
        $this->managerRegistry->getManager()->remove($deck);
    }

    private function textFile(string $content, string $filename): Response
    {
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));
        return $response;
    }
}
