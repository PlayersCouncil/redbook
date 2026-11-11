<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\User;
use App\Services\CommentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comments on decklists and libraries (Ketura, 2026-11-11), one set of actions for both:
 * post and reply, edit, delete, hide, History, Cheers and fire, report, and what the viewer
 * has done to a page's comments. The writing ones answer the comment as the page draws it, or
 * JSON. App\Services\CommentManager does the work and decides who may.
 */
class CommentController extends AbstractController
{
    public function __construct(private readonly CommentManager $comments)
    {
    }

    /** A comment, or a reply (parent); answers it as the thread draws it */
    #[Route(path: '/comment/post', name: 'comment_post', methods: ['POST'])]
    public function postAction(Request $request): Response
    {
        $user = $this->writer($request);
        $subject = $this->comments->subject((string) $request->request->get('kind'), (string) $request->request->get('id'), $user);
        $parent = (int) $request->request->get('parent') ?: null;
        $comment = $this->comments->post($subject, $user, (string) $request->request->get('comment'), $parent);
        $depth = 0;
        for ($p = $comment->getParent(); $p !== null && $depth < 50; $p = $p->getParent()) {
            $depth++;
        }
        return $this->render('Comment/node.html.twig', [
            'node' => ['comment' => $comment, 'replies' => [], 'count' => 0],
            'depth' => $depth,
            'subject_url' => $this->comments->url($subject),
        ], new Response('', Response::HTTP_OK, ['X-Comment-Count' => (string) $subject->getNbComments()]));
    }

    /** New text; answers the comment's body as it now reads */
    #[Route(path: '/comment/{id}/edit', name: 'comment_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editAction(int $id, Request $request): Response
    {
        $user = $this->writer($request);
        $comment = $this->comments->find($id, $user);
        $this->comments->edit($comment, $user, (string) $request->request->get('comment'));
        return $this->render('Comment/body.html.twig', ['comment' => $comment, 'subject_url' => $this->comments->url($comment->getSubject())]);
    }

    /** Deleted; answers whether it still shows (it does while replies stand under it), and the new count */
    #[Route(path: '/comment/{id}/delete', name: 'comment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id, Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $comment = $this->comments->find($id, $user);
        $this->comments->delete($comment, $user);
        return new JsonResponse([
            'count' => $comment->getSubject()->getNbComments(),
            'body' => $this->renderView('Comment/body.html.twig', ['comment' => $comment, 'subject_url' => $this->comments->url($comment->getSubject())]),
        ]);
    }

    #[Route(path: '/comment/{id}/hide', name: 'comment_hide', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function hideAction(int $id, Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $comment = $this->comments->find($id, $user);
        $this->comments->hide($comment, $user, (bool) $request->request->get('hidden'));
        return new JsonResponse(['hidden' => $comment->getIsHidden()]);
    }

    #[Route(path: '/comment/{id}/cheer', name: 'comment_cheer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cheerAction(int $id, Request $request): JsonResponse
    {
        $user = $this->writer($request);
        return new JsonResponse($this->comments->cheer($this->comments->find($id, $user), $user));
    }

    #[Route(path: '/comment/{id}/fire', name: 'comment_fire', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function fireAction(int $id, Request $request): JsonResponse
    {
        $user = $this->writer($request);
        return new JsonResponse($this->comments->fire($this->comments->find($id, $user), $user));
    }

    #[Route(path: '/comment/{id}/report', name: 'comment_report', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reportAction(int $id, Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $this->comments->report($this->comments->find($id, $user), $user, (string) $request->request->get('reason'));
        return new JsonResponse(['reported' => true]);
    }

    /** Its versions, newest first, for the History popup; a deleted one's only for moderators */
    #[Route(path: '/comment/{id}/history', name: 'comment_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function historyAction(int $id): Response
    {
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        $comment = $this->comments->find($id, $user);
        if ($comment->isDeleted() && !$this->comments->isModerator()) {
            throw new AccessDeniedHttpException('That comment was deleted.');
        }
        $response = $this->render('Comment/history.html.twig', ['comment' => $comment, 'versions' => $this->comments->history($comment)]);
        $response->setPrivate();
        return $response;
    }

    /** The markdown of a comment, for Edit, Copy Text and Quote; null for one from before it was kept */
    #[Route(path: '/comment/{id}/source', name: 'comment_source', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sourceAction(int $id): JsonResponse
    {
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        $comment = $this->comments->find($id, $user);
        if ($comment->isDeleted()) {
            throw new AccessDeniedHttpException('That comment was deleted.');
        }
        $response = new JsonResponse(['markdown' => $this->comments->markdownOf($comment), 'author' => $comment->getUser()->getDisplayName()]);
        $response->setPrivate();
        return $response;
    }

    /** What the logged-in viewer has done to a page's comments, and may do with them */
    #[Route(path: '/comment/state', name: 'comment_state', methods: ['GET'])]
    public function stateAction(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(null);
        }
        $subject = $this->comments->subject((string) $request->query->get('kind'), (string) $request->query->get('id'), $user);
        $state = $this->comments->state($subject, $user);
        $state['token'] = $this->container->get('security.csrf.token_manager')->getToken(CommentManager::TOKEN)->getValue();
        $response = new JsonResponse($state);
        $response->setPrivate();
        $response->setMaxAge(0);
        return $response;
    }

    /** The logged-in user, with the page's token */
    private function writer(Request $request): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('You must be logged in.');
        }
        if (!$this->isCsrfTokenValid(CommentManager::TOKEN, (string) $request->request->get('token'))) {
            // the page's script fetches a fresh token and sends it again
            throw new BadRequestHttpException('The page has expired.', null, 0, ['X-Comment-Error' => 'expired']);
        }
        return $user;
    }
}
