<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Card;
use App\Entity\PublishedDeck;
use App\Entity\Faction;
use App\Entity\User;
use App\Entity\UserInterface;
use FOS\UserBundle\Mailer\MailerInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * @package App\Controller
 */
class UserController extends AbstractController
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }
    /**
     * @param int $cacheExpiration
     * @param int $user_id
     * @param string $user_name
     * @param int $page
     * @return Response
     * @todo Eliminate unused args from route and action. [ST 2020/08/09]
     */
    #[Route(path: '/user/profile/{user_id}/{user_name}/{page}', name: 'user_profile_public', methods: ['GET'], defaults: ['page' => 1], requirements: ['user_id' => '\d+'])]
    public function publicProfileAction(int $cacheExpiration, $user_id, $user_name, $page)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        /* @var $em EntityManager */
        $em = $this->managerRegistry->getManager();

        /* @var UserInterface $user */
        $user = $em->getRepository(User::class)->find($user_id);
        if (! $user) {
            throw new NotFoundHttpException("No such user.");
        }
        if ($user->getMergedInto() !== null) {
            // a claimed or merged historic account: its decks live with the person now
            $into = \App\Services\AccountMerger::resolve($user);
            return $this->redirectToRoute('user_profile_public', ['user_id' => $into->getId(), 'user_name' => $into->getUsername()], 301);
        }

        return $this->render('User/profile_public.html.twig', array(
                'user' => $user
        ));
    }

    /**
     * @return Response
     */
    /**
     * The profile being edited: your own; or, for an admin, an organization's (Ketura,
     * 2026-11-09: nobody logs in as one, so its profile is edited from an admin's)
     */
    private function profileTarget(mixed $userId): User
    {
        $self = $this->getUser();
        if (!$self instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($userId === null || $userId === '' || (int) $userId === $self->getId()) {
            return $self;
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $target = $this->managerRegistry->getRepository(User::class)->find((int) $userId);
        if (!$target instanceof User || !$target->isOrganization()) {
            throw $this->createNotFoundException('Only an organization\'s profile can be edited by an admin.');
        }
        return $target;
    }

    /** A card with a picture (and its framing), or a culture with banner art, or neither */
    private function applyAvatar(User $user, array $avatar): void
    {
        $em = $this->managerRegistry->getManager();
        $mode = (string) ($avatar['mode'] ?? 'none');
        if ($mode === 'card') {
            $card = $em->getRepository(\App\Entity\Card::class)->findOneBy(['code' => (string) ($avatar['card'] ?? '')]);
            if ($card && $card->getImageUrl()) {
                $user->setAvatar($card, \App\Classes\ThumbnailCrop::sanitize($avatar['crop'] ?? null, [$card->getCode()]), null);
                return;
            }
        } elseif ($mode === 'culture') {
            $code = (string) (((array) ($avatar['cultures'] ?? []))[0] ?? '');
            $culture = $code !== '' ? $em->getRepository(\App\Entity\Culture::class)->findOneBy(['code' => $code]) : null;
            if ($culture && $culture->getSide()) {
                $user->setAvatar(null, null, $culture);
                return;
            }
        }
        $user->setAvatar(null, null, null);
    }

    private function profileEditUrl(User $user): string
    {
        return $user->isOrganization()
            ? $this->generateUrl('user_profile_edit', ['user_id' => $user->getId()])
            : $this->generateUrl('user_profile_edit');
    }

    #[Route(path: '/user/profile_edit', name: 'user_profile_edit', methods: ['GET'])]
    public function editProfileAction(Request $request)
    {
        $user = $this->profileTarget($request->query->get('user_id'));

        $factions = $this->managerRegistry->getRepository(Faction::class)->findAll();

        return $this->render('User/profile_edit.html.twig', array(
                'user' => $user,
                'factions' => $factions,
                // the avatar's culture buttons (Ketura, 2026-11-11)
                'cultures' => $this->managerRegistry->getRepository(\App\Entity\Culture::class)->findBy([], ['name' => 'ASC']),
        ));
    }

    /**
     *
     * @param Request $request
     * @param SessionInterface $session
     * @return RedirectResponse
     */
    #[Route(path: '/user/profile_save', name: 'user_profile_save', methods: ['POST'])]
    public function saveProfileAction(Request $request, SessionInterface $session, \Symfony\Contracts\Translation\TranslatorInterface $translator)
    {
        $user = $this->profileTarget($request->request->get('user_id'));
        $em = $this->managerRegistry->getManager();
        $back = $this->profileEditUrl($user);
        // an organization's: its name, bio and colour; its username addresses it and its email is none
        $organization = $user->isOrganization();

        $username = filter_var($request->get('username'), FILTER_SANITIZE_STRING);
        if (!$organization && $username !== $user->getUserIdentifier()) {
            $user_existing = $em->getRepository(User::class)->findOneBy(array('username' => $username));

            if ($user_existing) {
                $session->getFlashBag()->set('error', "Username $username is already taken.");
                return $this->redirect($back);
            }

            $user->setUsername($username);
        }

        // display names are unique among real accounts, ignoring case; historic accounts don't count (Ketura, 2026-11-02)
        $displayName = trim(strip_tags((string) $request->get('display_name')));
        $displayName = mb_substr(preg_replace('/\s+/u', ' ', $displayName), 0, 100);
        $shown = mb_strtolower($displayName !== '' ? $displayName : $user->getUsername());
        $taken = (int) $em->createQuery(
            'SELECT COUNT(u) FROM App\Entity\User u WHERE u.id != :id AND u.historic = false AND u.mergedInto IS NULL
             AND (LOWER(u.displayName) = :name OR (u.displayName IS NULL AND u.usernameCanonical = :name))'
        )->setParameters(['id' => $user->getId(), 'name' => $shown])->getSingleScalarResult();
        if ($taken > 0) {
            $session->getFlashBag()->set('error', $translator->trans('user.display_name_taken', ['%name%' => $displayName ?: $user->getUsername()]));
            return $this->redirect($back);
        }
        $user->setDisplayName($displayName);

        // the avatar (Ketura, 2026-11-11): {mode: card|culture|none, card, crop, cultures}
        $avatar = json_decode((string) $request->request->get('avatar'), true);
        if (is_array($avatar)) {
            $this->applyAvatar($user, $avatar);
        }

        $resume = filter_var($request->get('resume'), FILTER_SANITIZE_STRING);
        $faction_code = filter_var($request->get('user_faction_code'), FILTER_SANITIZE_STRING);
        $user->setColor($faction_code);
        $user->setResume($resume);

        if (!$organization) {
            $email = filter_var($request->get('email'), FILTER_SANITIZE_STRING);
            if ($email !== $user->getEmail()) {
                $user->setEmail($email);
            }
            $user->setIsNotifAuthor($request->get('notif_author') ? true : false);
            $user->setIsNotifCommenter($request->get('notif_commenter') ? true : false);
            $user->setIsNotifMention($request->get('notif_mention') ? true : false);
            $user->setIsShareDecks($request->get('share_decks') ? true : false);
        }

        $this->managerRegistry->getManager()->flush();

        $session->getFlashBag()->set('notice', $organization ? $translator->trans('user.organization.saved', ['%name%' => $user->getDisplayName()]) : "Successfully saved your profile.");

        return $this->redirect($back);
    }

    /**
     * @param Request $request
     * @param RouterInterface $router
     * @return Response
     */
    #[Route(path: '/api/public/user/info', name: 'user_info', methods: ['GET'])]
    public function infoAction(Request $request, RouterInterface $router, \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $tokens)
    {
        $jsonp = $request->query->get('jsonp');

        $decklist_id = $request->query->get('decklist_id');
        $card_id = $request->query->get('card_id');

        $content = null;

        if ($this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            /** @var UserInterface $user */
            $user = $this->getUser();
            $user_id = $user->getId();

            $public_profile_url = $router->generate('user_profile_public', array(
                    'user_id' => $user_id,
                    'user_name' => urlencode($user->getUserIdentifier())
            ));
            $content = array(
                    'public_profile_url' => $public_profile_url,
                    'id' => $user_id,
                    'name' => $user->getUserIdentifier(),
                    'faction' => $user->getColor(),
                    'donation' => $user->getDonation(),
                    'display_name' => $user->getDisplayName(),
                    'historian' => $this->isGranted('ROLE_HISTORIAN'),
                    'admin' => $this->isGranted('ROLE_ADMIN'),
            );

            if (isset($decklist_id)) {
                /* @var EntityManager $em */
                $em = $this->managerRegistry->getManager();
                /* @var PublishedDeckInterface $decklist */
                $decklist = $em->getRepository(PublishedDeck::class)->find($decklist_id);

                if ($decklist) {
                    $decklist_id = $decklist->getId();

                    $dbh = $this->managerRegistry->getConnection();

                    $content['is_liked'] = (bool) $dbh->executeQuery("SELECT
                        count(*)
                        from published_deck d
                        join vote v on v.published_deck_id=d.id
                        where v.user_id=?
                        and d.id=?", array($user_id, $decklist_id))->fetchOne();

                    $content['is_favorite'] = (bool) $dbh->executeQuery("SELECT
                        count(*)
                        from published_deck d
                        join favorite f on f.published_deck_id=d.id
                        where f.user_id=?
                        and d.id=?", array($user_id, $decklist_id))->fetchOne();

                    $content['is_fired'] = (bool) $dbh->executeQuery("SELECT count(*) from fire f where f.user_id=? and f.published_deck_id=?",
                        array($user_id, $decklist_id))->fetchOne();

                    $content['is_author'] = ($user_id == $decklist->getUser()->getId());

                    // the author's undo publish while the grace period lasts; deleting is for
                    // moderators (Ketura, 2026-11-07)
                    $content['can_withdraw'] = $content['is_author'] && $decklist->canWithdraw();
                    if ($content['can_withdraw']) {
                        $content['withdraw_until'] = $decklist->getGraceEnds()->format('c');
                        // never in a JSONP answer, which another site could read
                        if ($jsonp === null) {
                            $content['state_token'] = $tokens->getToken('decklist_state')->getValue();
                        }
                    }
                    // the author's deck gone: Recreate in place of Clone (Ketura, 2026-11-08)
                    $content['can_recreate'] = $content['is_author'] && $decklist->getParent() === null && !$decklist->isWithdrawn();
                    if ($content['can_recreate'] && $jsonp === null && !isset($content['state_token'])) {
                        $content['state_token'] = $tokens->getToken('decklist_state')->getValue();
                    }
                    $content['can_delete'] = $this->isGranted('ROLE_ADMIN');
                }
            }

            if (isset($card_id)) {
                /* @var $em EntityManager */
                $em = $this->managerRegistry->getManager();
                /* @var CardInterface $card */
                $card = $em->getRepository(Card::class)->find($card_id);

                if ($card) {
                    $reviews = $card->getReviews();
                    /* @var ReviewInterface $review */
                    foreach ($reviews as $review) {
                        if ($review->getUser()->getId() === $user->getId()) {
                            $content['review_id'] = $review->getId();
                            $content['review_text'] = $review->getTextMd();
                        }
                    }
                }
            }
        }
        $content = json_encode($content);

        $response = new Response();
        $response->setPrivate();
        if (isset($jsonp)) {
            $content = "$jsonp($content)";
            $response->headers->set('Content-Type', 'application/javascript');
        } else {
            $response->headers->set('Content-Type', 'application/json');
        }
        $response->setContent($content);

        return $response;
    }

    /**
     *
     * @param UserManagerInterface $userManager
     * @param MailerInterface $mailer
     * @param SessionInterface $session
     * @param RouterInterface $router
     * @param string $username
     * @return RedirectResponse|Response
     */
    #[Route(path: '/user/remind/{username}', name: 'remind_email', methods: ['GET'])]
    public function remindAction(
        UserManagerInterface $userManager,
        MailerInterface $mailer,
        SessionInterface $session,
        RouterInterface $router,
        $username
    ) {
        $user = $userManager->findUserByUsername($username);
        if (!$user) {
            throw new NotFoundHttpException("Cannot find user from username [$username]");
        }
        if (!$user->getConfirmationToken()) {
            return $this->render('User/remind-no-token.html.twig');
        }

        $mailer->sendConfirmationEmailMessage($user);

        $session->set('fos_user_send_confirmation_email/email', $user->getEmail());

        $url = $router->generate('fos_user_registration_check_email');
        return $this->redirect($url);
    }
}
