<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The comment actions answer a refusal as JSON, {message}, with its headers (Ketura,
 * 2026-11-11): the page's script shows what went wrong in place rather than an error page,
 * and on X-Comment-Error: expired fetches a fresh token and sends again by itself.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class CommentErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $route = (string) $event->getRequest()->attributes->get('_route');
        $exception = $event->getThrowable();
        if (!str_starts_with($route, 'comment_') || !$exception instanceof HttpExceptionInterface) {
            return;
        }
        $event->setResponse(new JsonResponse(['message' => $exception->getMessage()], $exception->getStatusCode(), $exception->getHeaders()));
    }
}
