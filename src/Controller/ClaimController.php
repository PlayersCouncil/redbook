<?php

namespace App\Controller;

use App\Entity\HistoricClaim;
use App\Entity\User;
use App\Services\AccountMerger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Claiming a historic account (Ketura, 2026-11-02): a registered user says "that
 * archive author is me", an admin approves, and the historic account is merged into
 * theirs: its decks and published decks become theirs, and its name an alias.
 */
class ClaimController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountMerger $merger,
    ) {
    }

    #[Route(path: '/claim/{user_id}', name: 'historic_claim_form', methods: ['GET', 'POST'], requirements: ['user_id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function claimAction(int $user_id, Request $request, TranslatorInterface $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $historic = $this->em->getRepository(User::class)->find($user_id);
        if (!$historic instanceof User || !$historic->isHistoric()) {
            throw $this->createNotFoundException('Historic account not found');
        }
        if ($historic->getMergedInto() !== null) {
            $this->addFlash('notice', $translator->trans('claim.already_claimed'));
            return $this->redirectToRoute('user_profile_public', ['user_id' => $historic->getId(), 'user_name' => $historic->getUsername()]);
        }
        if ($user->isHistoric()) {
            throw $this->createAccessDeniedException();
        }
        $pending = $this->em->getRepository(HistoricClaim::class)->findOneBy(['historicUser' => $historic, 'user' => $user, 'status' => HistoricClaim::STATUS_PENDING]);

        if ($request->isMethod('POST') && $pending === null) {
            if (!$this->isCsrfTokenValid('claim' . $user_id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid form');
            }
            $message = mb_substr(trim(strip_tags((string) $request->request->get('message'))), 0, 2000);
            $this->em->persist(new HistoricClaim($historic, $user, $message ?: null));
            $this->em->flush();
            $this->addFlash('notice', $translator->trans('claim.sent'));
            return $this->redirectToRoute('user_profile_public', ['user_id' => $historic->getId(), 'user_name' => $historic->getUsername()]);
        }

        return $this->render('Claim/claim.html.twig', [
            'pagetitle' => $translator->trans('claim.title', ['%name%' => $historic->getDisplayName()]),
            'historic' => $historic,
            'pending' => $pending,
            'nb_decklists' => $this->em->getRepository(\App\Entity\PublishedDeck::class)->count(['user' => $historic]),
        ]);
    }

    #[Route(path: '/admin/claims', name: 'admin_claims', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listAction(TranslatorInterface $translator): Response
    {
        $repository = $this->em->getRepository(HistoricClaim::class);
        return $this->render('Claim/admin.html.twig', [
            'pagetitle' => $translator->trans('claim.admin_title'),
            'pending' => $repository->findBy(['status' => HistoricClaim::STATUS_PENDING], ['dateCreation' => 'ASC']),
            'recent' => $repository->findBy(['status' => [HistoricClaim::STATUS_APPROVED, HistoricClaim::STATUS_REJECTED]], ['dateDecision' => 'DESC'], 30),
        ]);
    }

    #[Route(path: '/admin/claims/{claim_id}', name: 'admin_claim_decide', methods: ['POST'], requirements: ['claim_id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function decideAction(int $claim_id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('decide' . $claim_id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form');
        }
        /** @var User $admin */
        $admin = $this->getUser();
        $claim = $this->em->getRepository(HistoricClaim::class)->find($claim_id)
            ?? throw $this->createNotFoundException('Claim not found');
        if (!$claim->isPending()) {
            return $this->redirectToRoute('admin_claims');
        }
        if ($request->request->get('decision') === 'approve' && $claim->getHistoricUser()->getMergedInto() === null) {
            $claim->decide(HistoricClaim::STATUS_APPROVED, $admin);
            // the other requests for the same account are settled by the merge
            $this->merger->merge($claim->getHistoricUser(), $claim->getUser(), $admin);
        } else {
            $claim->decide(HistoricClaim::STATUS_REJECTED, $admin);
        }
        $this->em->flush();
        return $this->redirectToRoute('admin_claims');
    }
}
