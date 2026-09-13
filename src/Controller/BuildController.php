<?php

declare(strict_types=1);

namespace App\Controller;

use App\Satis\BuildRunner;
use App\Satis\BuildRunningException;
use App\Satis\SatisConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/build')]
final class BuildController extends AbstractController
{
    public function __construct(
        private readonly BuildRunner $builds,
        private readonly SatisConfig $config,
    ) {
    }

    #[Route('', name: 'app_build', methods: ['GET'])]
    public function index(): Response
    {
        $status = $this->builds->status();

        return $this->render('build/index.html.twig', [
            'status' => $status,
            'log' => $this->builds->log(),
            'command' => implode(' ', $this->builds->command()),
            'repositories' => $this->config->repositories(),
        ]);
    }

    #[Route('/run', name: 'app_build_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('run-build', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $repositoryUrl = trim((string) $request->request->get('repository_url', ''));
        $urls = '' !== $repositoryUrl ? [$repositoryUrl] : [];
        try {
            $this->builds->start($urls, 'ui');
            $this->addFlash('success', [] === $urls ? 'Full build started.' : sprintf('Build of %s started.', $repositoryUrl));
        } catch (BuildRunningException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_build');
    }

    #[Route('/status', name: 'app_build_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $status = $this->builds->status();

        return new JsonResponse([
            'state' => $status->state,
            'running' => $status->isRunning(),
            'label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'meta' => $status->meta(),
            'exit_code' => $status->exitCode,
            'log' => $this->builds->log(),
        ]);
    }
}
