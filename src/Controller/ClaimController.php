<?php

namespace App\Controller;

use App\Entity\Decklist;
use App\Entity\DecklistClaim;
use App\Entity\User;
use App\Services\SeedDeckImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Claiming archived decklists (phase3/06): a registered user asks for a seeded
 * decklist, an admin approves, and the decklist moves to the user. Its source
 * details stay with it.
 */
class ClaimController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route(path: '/decklist/claim/{decklist_id}', name: 'decklist_claim_form', methods: ['GET', 'POST'], requirements: ['decklist_id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function claimAction(int $decklist_id, Request $request, TranslatorInterface $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $decklist = $this->em->getRepository(Decklist::class)->find($decklist_id)
            ?? throw $this->createNotFoundException('Decklist not found');
        if ($decklist->getSource() === null || $decklist->getUser()->getUsername() !== SeedDeckImporter::ARCHIVE_USERNAME) {
            throw $this->createAccessDeniedException('Only archived decklists can be claimed');
        }
        $pending = $this->em->getRepository(DecklistClaim::class)->findOneBy(['decklist' => $decklist, 'user' => $user, 'status' => DecklistClaim::STATUS_PENDING]);

        if ($request->isMethod('POST') && $pending === null) {
            if (!$this->isCsrfTokenValid('claim' . $decklist_id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid form');
            }
            $message = mb_substr(trim(strip_tags((string) $request->request->get('message'))), 0, 2000);
            $this->em->persist(new DecklistClaim($decklist, $user, $message ?: null));
            $this->em->flush();
            $this->addFlash('notice', $translator->trans('decklist.claim.sent'));
            return $this->redirectToRoute('decklist_detail', ['decklist_id' => $decklist_id, 'decklist_name' => $decklist->getNameCanonical()]);
        }

        return $this->render('Claim/claim.html.twig', [
            'pagetitle' => $translator->trans('decklist.claim.title'),
            'decklist' => $decklist,
            'pending' => $pending,
        ]);
    }

    #[Route(path: '/admin/claims', name: 'admin_claims', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listAction(TranslatorInterface $translator): Response
    {
        return $this->render('Claim/admin.html.twig', [
            'pagetitle' => $translator->trans('decklist.claim.admin_title'),
            'pending' => $this->em->getRepository(DecklistClaim::class)->findBy(['status' => DecklistClaim::STATUS_PENDING], ['dateCreation' => 'ASC']),
            'recent' => $this->em->getRepository(DecklistClaim::class)->findBy(['status' => [DecklistClaim::STATUS_APPROVED, DecklistClaim::STATUS_REJECTED]], ['dateDecision' => 'DESC'], 30),
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
        $claim = $this->em->getRepository(DecklistClaim::class)->find($claim_id)
            ?? throw $this->createNotFoundException('Claim not found');
        if (!$claim->isPending()) {
            return $this->redirectToRoute('admin_claims');
        }
        if ($request->request->get('decision') === 'approve') {
            $claim->decide(DecklistClaim::STATUS_APPROVED, $admin);
            $claim->getDecklist()->setUser($claim->getUser());
            // the other requests for the same decklist are settled by this one
            foreach ($this->em->getRepository(DecklistClaim::class)->findBy(['decklist' => $claim->getDecklist(), 'status' => DecklistClaim::STATUS_PENDING]) as $other) {
                if ($other !== $claim) {
                    $other->decide(DecklistClaim::STATUS_REJECTED, $admin);
                }
            }
        } else {
            $claim->decide(DecklistClaim::STATUS_REJECTED, $admin);
        }
        $this->em->flush();
        return $this->redirectToRoute('admin_claims');
    }
}
