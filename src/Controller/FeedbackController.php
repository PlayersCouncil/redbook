<?php

namespace App\Controller;

use App\Entity\User;
use App\Services\Texts;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bug report form (Ketura, 2026-11-09): who's writing, how to reach them, the page they
 * were on, a category and a message in Markdown, emailed to the site's admins. Anyone may
 * send one; a hidden field and a few a session catch the obvious robots.
 */
class FeedbackController extends AbstractController
{
    public const CATEGORIES = ['bug', 'feedback', 'question'];
    /** At most this many from one session an hour, and none closer together than the gap */
    private const PER_HOUR = 10;
    private const GAP = 20;

    /** At most this many admins get it, when no address is configured */
    private const MAX_ADMINS = 20;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly Texts $texts,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Route(path: '/feedback', name: 'feedback_send', methods: ['POST'])]
    public function sendAction(
        Request $request,
        MailerInterface $mailer,
        TranslatorInterface $translator,
        string $emailSenderAddress,
        string $feedbackAddress,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('feedback', (string) $request->request->get('_token'))) {
            return $this->fail($translator->trans('feedback.error.expired'), 400);
        }
        // a field people never see, which form-filling robots fill
        if (trim((string) $request->request->get('website')) !== '') {
            return new JsonResponse(['success' => true]);
        }
        $session = $request->getSession();
        $now = time();
        $sent = array_values(array_filter((array) $session->get('feedback_sent', []), fn ($t) => is_int($t) && $t > $now - 3600));
        if (count($sent) >= self::PER_HOUR || ($sent && max($sent) > $now - self::GAP)) {
            return $this->fail($translator->trans('feedback.error.too_many'), 429);
        }

        $line = fn (string $key, int $max) => mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $request->request->get($key)))), 0, $max);
        $name = $line('name', 100);
        $contact = $line('contact', 200);
        $page = $line('page', 500);
        $category = (string) $request->request->get('category');
        $message = mb_substr(trim((string) $request->request->get('message')), 0, 10000);
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'feedback';
        }
        if ($message === '') {
            return $this->fail($translator->trans('feedback.error.empty'), 400);
        }

        $recipients = $this->recipients($feedbackAddress);
        if ($recipients === []) {
            return $this->fail($translator->trans('feedback.error.nobody'), 503);
        }
        $user = $this->getUser();
        $categoryLabel = $translator->trans('feedback.category.' . $category, [], null, 'en');
        $summary = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($this->texts->markdown($message)))), 0, 60);
        $data = [
            'category' => $categoryLabel,
            'name' => $name,
            'contact' => $contact,
            'page' => $page,
            'page_is_url' => (bool) preg_match('~^https?://~i', $page),
            'account' => $user instanceof User ? $user : null,
            'message_md' => $message,
            // Markdown made HTML and purified, as comments are
            'message_html' => $this->texts->markdown($message),
            'ip' => $request->getClientIp(),
            'agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 300),
        ];
        $text = $this->renderView('Emails/feedback.txt.twig', $data);
        $html = $this->renderView('Emails/feedback.html.twig', $data);
        // one message to each, so a bad address doesn't stop the others
        $delivered = 0;
        foreach ($recipients as $recipient) {
            $email = (new Email())
                ->subject(sprintf('[Red Book] %s: %s', $categoryLabel, $summary))
                ->from(new Address($emailSenderAddress, 'Red Book feedback'))
                ->to($recipient)
                ->text($text)
                ->html($html);
            if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $email->replyTo($contact);
            }
            try {
                $mailer->send($email);
                $delivered++;
            } catch (TransportExceptionInterface $e) {
                $this->logger?->error('Feedback not delivered to {to}: {error}', ['to' => $recipient, 'error' => $e->getMessage()]);
            }
        }
        if ($delivered === 0) {
            return $this->fail($translator->trans('feedback.error.failed'), 502);
        }

        $sent[] = $now;
        $session->set('feedback_sent', $sent);
        return new JsonResponse(['success' => true, 'message' => $translator->trans('feedback.sent')]);
    }

    /**
     * Who gets it: APP_FEEDBACK_EMAIL (one or more addresses, comma-separated); left empty,
     * the admins with a real address (the newest twenty)
     * @return string[]
     */
    private function recipients(string $configured): array
    {
        $addresses = array_values(array_filter(array_map('trim', explode(',', $configured)), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if ($addresses) {
            return $addresses;
        }
        $admins = $this->managerRegistry->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles LIKE :admin OR u.roles LIKE :super')
            ->andWhere('u.historic = false AND u.organization = false')
            ->setParameter('admin', '%"ROLE_ADMIN"%')
            ->setParameter('super', '%"ROLE_SUPER_ADMIN"%')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(self::MAX_ADMINS)
            ->getQuery()->getResult();
        $found = [];
        foreach ($admins as $admin) {
            $address = (string) $admin->getEmail();
            if (filter_var($address, FILTER_VALIDATE_EMAIL) && !str_ends_with(mb_strtolower($address), '.invalid')) {
                $found[] = $address;
            }
        }
        return array_values(array_unique($found));
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
