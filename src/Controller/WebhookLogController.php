<?php

declare(strict_types=1);

namespace App\Controller;

use App\Webhook\WebhookLog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/webhooks')]
final class WebhookLogController extends AbstractController
{
    public function __construct(
        private readonly WebhookLog $log,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('', name: 'app_webhooks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = null;
        $retention = WebhookLog::DEFAULT_RETENTION;
        $error = $this->log->connectionError();
        if ($this->log->isEnabled() && null === $error) {
            $page = $this->log->page($request->query->getInt('page', 1));
            $retention = $this->log->retention();
        }

        return $this->render('webhook/index.html.twig', [
            'enabled' => $this->log->isEnabled(),
            'error' => $error,
            'page' => $page,
            'retention' => $retention,
            'retention_options' => WebhookLog::RETENTION_OPTIONS,
            'webhook_url' => '' !== $this->webhookSecret ? $this->generateUrl('app_webhook', ['token' => $this->webhookSecret], 0) : null,
        ]);
    }

    #[Route('/retention', name: 'app_webhooks_retention', methods: ['POST'])]
    public function retention(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('webhook-retention', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->log->setRetention($request->request->getInt('retention'));
            $this->addFlash('success', 'Retention updated.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_webhooks');
    }

    #[Route('/clear', name: 'app_webhooks_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('webhook-clear', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->log->clear();
            $this->addFlash('success', 'Webhook log cleared.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_webhooks');
    }
}
