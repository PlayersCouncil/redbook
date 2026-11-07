<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use App\Classes\DeckContent;
use App\Classes\FormMode;
use App\Entity\Card;
use App\Entity\Comment;
use App\Entity\CommentInterface;
use App\Entity\Deck;
use App\Entity\PublishedDeck;
use App\Entity\PublishedDeckInterface;
use App\Entity\Culture;
use App\Entity\PublishedDeckPlay;
use App\Repository\FormatRepository;
use App\Entity\Tournament;
use App\Entity\User;
use App\Services\PublishedDeckFactory;
use App\Services\PublishedDeckManager;
use App\Services\DeckValidator;
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
     */
    #[Route(path: '/deck/publish/{deck_uuid}', name: 'deck_publish_form', methods: ['GET', 'POST'], requirements: ['deck_uuid' => '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}'])]
    public function publishAction(
        Request $request,
        TranslatorInterface $translator,
        PublishedDeckFactory $decklistFactory,
        FormatRepository $formats,
        Texts $texts,
        DeckValidator $validator,
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

        if ($request->isMethod('POST')) {
            $this->applyDecklistForm($decklist, $request, FormMode::Create, $texts, $formats, $validator);
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
            $em->getRepository(PublishedDeck::class)->findBy(['signature' => md5($content)], ['dateCreation' => 'DESC']),
            fn (PublishedDeck $published) => json_encode($published->getSlots()->getContent()) == $content,
        );
        $own = array_filter($same, fn (PublishedDeck $published) => $published->getUser()->getId() === $user->getId());
        $published = reset($own) ?: reset($same);
        if ($published) {
            $url = $this->generateUrl('decklist_detail', [
                'decklist_id' => $published->getId(),
                'decklist_name' => $published->getNameCanonical(),
            ]);
            $this->addFlash('warning', $translator->trans('decklist.publish.warnings.published', ['%url%' => $url]));
        }

        return $this->renderDecklistForm(
            FormMode::Create,
            $decklist,
            $this->generateUrl('deck_publish_form', ['deck_uuid' => $deck->getUuid()]),
            $this->generateUrl('deck_edit', ['deck_uuid' => $deck->getUuid()]),
            $formats,
            $translator,
        );
    }

    /**
     * Changing a published decklist: the decklist form in Edit mode (Ketura,
     * 2026-11-06). The author, or a super admin.
     */
    #[Route(path: '/decklist/edit/{decklist_id}', name: 'decklist_edit', methods: ['GET', 'POST'], requirements: ['decklist_id' => '\d+'])]
    public function editAction(
        Request $request,
        TranslatorInterface $translator,
        FormatRepository $formats,
        Texts $texts,
        DeckValidator $validator,
        int $decklist_id
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException("Anonymous access denied");
        }

        $em = $this->managerRegistry->getManager();
        $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException("Published deck not found");
        }
        if (!$this->isGranted('ROLE_SUPER_ADMIN') && $user->getId() !== $decklist->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied");
        }

        $view = $this->generateUrl('decklist_detail', [
            'decklist_id' => $decklist->getId(),
            'decklist_name' => $decklist->getNameCanonical(),
        ]);

        if ($request->isMethod('POST')) {
            $this->applyDecklistForm($decklist, $request, FormMode::Edit, $texts, $formats, $validator);
            $decklist->setDateUpdate(new DateTime());
            $em->flush();

            return $this->redirectToRoute('decklist_detail', [
                'decklist_id' => $decklist->getId(),
                'decklist_name' => $decklist->getNameCanonical(),
            ]);
        }

        return $this->renderDecklistForm(
            FormMode::Edit,
            $decklist,
            $this->generateUrl('decklist_edit', ['decklist_id' => $decklist->getId()]),
            $view,
            $formats,
            $translator,
        );
    }

    /**
     * The decklist form, for either mode: it posts back to the address that showed it.
     * The format comes from the deck when publishing, so it shows but can't be changed
     * there; afterwards it can be set or corrected (archived decks arrive without one).
     */
    private function renderDecklistForm(
        FormMode $mode,
        PublishedDeck $decklist,
        string $action,
        string $cancel,
        FormatRepository $formats,
        TranslatorInterface $translator,
    ): Response {
        $choices = $formats->findSelectable();
        $current = $decklist->getTargetFormat() ? $formats->findOneByCode($decklist->getTargetFormat()) : null;
        if ($current !== null && !in_array($current, $choices, true)) {
            array_unshift($choices, $current);
        }

        return $this->render('Decklist/decklist_form.html.twig', [
            'mode' => $mode->value,
            'pagetitle' => $translator->trans('decklist.form.title.' . $mode->value),
            'action' => $action,
            'cancel' => $cancel,
            'decklist' => $decklist,
            'formats' => $choices,
            'tournaments' => $this->managerRegistry->getRepository(Tournament::class)->findAll(),
        ]);
    }

    /** What the decklist form sends, onto the decklist; the mode decides what may change */
    private function applyDecklistForm(
        PublishedDeck $decklist,
        Request $request,
        FormMode $mode,
        Texts $texts,
        FormatRepository $formats,
        DeckValidator $validator,
    ): void {
        if (!$this->isCsrfTokenValid('decklist_form', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('The form has expired; reload the page and try again.');
        }
        $form = $request->request;

        // an empty name keeps the one it had: the deck's when publishing
        $name = mb_substr(trim(strip_tags((string) $form->get('name'))), 0, 60) ?: ($decklist->getName() ?: 'Untitled');
        $descriptionMd = trim((string) $form->get('descriptionMd'));
        $decklist->setName($name);
        $decklist->setNameCanonical($texts->slugify($name) . '-' . $decklist->getVersion());
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($texts->markdown($descriptionMd));

        $tournament = (int) $form->get('tournament');
        $decklist->setTournament($tournament ? $this->managerRegistry->getRepository(Tournament::class)->find($tournament) : null);

        // an id, or a decklist's address; never the decklist itself
        $precedent = trim((string) $form->get('precedent'));
        if (!preg_match('/^\d+$/', $precedent)) {
            $precedent = preg_match('/view\/(\d+)/', $precedent, $matches) ? $matches[1] : null;
        }
        $precedent = ($precedent && (int) $precedent !== $decklist->getId())
            ? $this->managerRegistry->getRepository(PublishedDeck::class)->find($precedent)
            : null;
        $decklist->setPrecedent($precedent);

        $decklist->setHalf($this->halfFrom($request));
        $decklist->setThumbnailCard($this->thumbnailFrom($request, $decklist));
        $effectiveDate = $this->dateFrom($form->get('effective_date'));
        if ($effectiveDate) {
            $decklist->setEffectiveDate($effectiveDate);
        }
        $this->applyPlays($decklist, $request);

        if ($mode === FormMode::Edit) {
            $format = $formats->findOneByCode((string) $form->get('target_format'));
            if ($format !== null && $format->getCode() !== $decklist->getTargetFormat()) {
                $decklist->setTargetFormat($format->getCode());
                $validator->validateDeck($decklist);
            }
        }
    }

    /**
     * Deletes a decklist if it has no comment, no vote, no favorite.
     *
     *
     * @param string $decklist_id
     * @param Request $request
     * @return RedirectResponse
     * @throws ORMException
     * @throws OptimisticLockException
     */
    #[Route(path: '/decklist/delete/{decklist_id}', name: 'decklist_delete', methods: ['POST'], requirements: ['decklist_id' => '\d+'])]
    public function deleteAction($decklist_id, Request $request)
    {
        /* @var $em EntityManager */
        $em = $this->managerRegistry->getManager();

        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException("You must be logged in for this operation.");
        }

        /* @var PublishedDeckInterface $decklist */
        $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id);
        if (!$decklist || $decklist->getUser()->getId() != $user->getId()) {
            throw new UnauthorizedHttpException("You don't have access to this decklist.");
        }

        if ($decklist->getnbVotes() || $decklist->getNbfavorites() || $decklist->getNbcomments()) {
            throw new UnauthorizedHttpException("Cannot delete this decklist.");
        }

        $precedent = $decklist->getPrecedent();

        $children_decks = $decklist->getChildren();
        foreach ($children_decks as $children_deck) {
            $children_deck->setParent($precedent);
        }

        $successor_decklists = $decklist->getSuccessors();
        /* @var PublishedDeckInterface $successor_decklist */
        foreach ($successor_decklists as $successor_decklist) {
            $successor_decklist->setPrecedent($precedent);
        }

        $em->remove($decklist);
        $em->flush();

        return $this->redirectToRoute('decklists_list', ['mine' => 1]);
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

        $duplicate = $decklistRepo->findDuplicate($decklist);
        if (
            $duplicate->getDateCreation() >= $decklist->getDateCreation()
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

        return $this->render(
            'Decklist/decklist.html.twig',
            array(
                'pagetitle' => $decklist->getName(),
                'decklist' => $decklist,
                'duplicate' => $duplicate,
                'commenters' => $commenters,
                'versions' => $versions,
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
        if (!$decklist) {
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
        if ($decklist && !empty($comment_text)) {
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
        if (!$decklist) {
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
        if (!$decklist) {
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

    /** free_peoples or shadow from the publish form; anything else is a whole deck */
    /** The thumbnail card the form chose: one of the deck's cards, not a site; none for the cultures */
    private function thumbnailFrom(Request $request, PublishedDeck $decklist): ?Card
    {
        $code = (string) $request->request->get('thumbnail');
        foreach ($decklist->getSlots() as $slot) {
            if ($slot->getCard()->getCode() === $code && $slot->getRole() !== DeckContent::ROLE_SITE) {
                return $slot->getCard();
            }
        }
        return null;
    }

    private function halfFrom(Request $request): ?string
    {
        $half = $request->request->get('half');
        return in_array($half, [Card::SIDE_FREE_PEOPLES, Card::SIDE_SHADOW], true) ? $half : null;
    }

    private function dateFrom(mixed $value): ?DateTime
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        return DateTime::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /**
     * Play records from the publish and edit forms (phase3/03): parallel arrays
     * play_event[], play_date[], play_tier[], play_placing[], play_players[], play_url[].
     * The submitted rows replace the decklist's records.
     */
    private function applyPlays(PublishedDeck $decklist, Request $request): void
    {
        $form = $request->request->all();
        if (!isset($form['play_event'])) {
            return;
        }
        foreach ($decklist->getPlays()->toArray() as $play) {
            $decklist->removePlay($play);
        }
        $tiers = $this->managerRegistry->getRepository(Tournament::class);
        foreach ((array) $form['play_event'] as $i => $event) {
            $event = mb_substr(trim(strip_tags((string) $event)), 0, 255);
            if ($event === '') {
                continue;
            }
            $play = new PublishedDeckPlay();
            $play->setEventName($event);
            $play->setEventDate($this->dateFrom($form['play_date'][$i] ?? null));
            $tierId = (int) ($form['play_tier'][$i] ?? 0);
            $play->setTier($tierId ? $tiers->find($tierId) : null);
            $play->setPlacing(mb_substr(trim(strip_tags((string) ($form['play_placing'][$i] ?? ''))), 0, 50) ?: null);
            $players = (int) ($form['play_players'][$i] ?? 0);
            $play->setPlayerCount($players > 0 ? min($players, 32000) : null);
            $url = trim((string) ($form['play_url'][$i] ?? ''));
            $play->setUrl(filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $url) ? mb_substr($url, 0, 500) : null);
            $decklist->addPlay($play);
        }
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
