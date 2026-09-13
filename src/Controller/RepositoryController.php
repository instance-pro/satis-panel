<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\RepositoryType;
use App\Satis\BuildOutput;
use App\Satis\ConfigException;
use App\Satis\RepositoryData;
use App\Satis\RepositoryUrlMatcher;
use App\Satis\SatisConfig;
use App\Webhook\WebhookSecrets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/repositories')]
final class RepositoryController extends AbstractController
{
    public function __construct(
        private readonly SatisConfig $config,
        private readonly WebhookSecrets $secrets,
        private readonly BuildOutput $output,
    ) {
    }

    #[Route('', name: 'app_repositories', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        try {
            $config = $this->config->load();
        } catch (ConfigException $e) {
            $this->addFlash('error', $e->getMessage());
            $config = ['repositories' => []];
        }

        $form = $this->createForm(RepositoryType::class, new RepositoryData());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RepositoryData $data */
            $data = $form->getData();
            $repository = $data->applyTo();
            $config['repositories'][] = $repository;
            if ($this->save($config, 'Repository added.')) {
                $this->secrets->set($repository['url'], $data->webhookSecret);

                return $this->redirectToRoute('app_repositories');
            }
        }

        $signed = $this->secrets->configured();

        return $this->render('repository/index.html.twig', [
            'repositories' => $config['repositories'],
            'built' => $this->output->packagesForRepositories($config['repositories']),
            'output_exists' => $this->output->exists(),
            'signed' => array_map(static fn (array $r): bool => isset($signed[RepositoryUrlMatcher::normalize((string) ($r['url'] ?? ''))]), $config['repositories']),
            'form' => $form,
        ]);
    }

    #[Route('/{index}/edit', name: 'app_repository_edit', requirements: ['index' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $index, Request $request): Response
    {
        $config = $this->config->load();
        if (!isset($config['repositories'][$index])) {
            throw $this->createNotFoundException();
        }

        $oldUrl = (string) ($config['repositories'][$index]['url'] ?? '');
        $data = RepositoryData::fromArray($config['repositories'][$index]);
        $data->webhookSecret = $this->secrets->get($oldUrl);
        $form = $this->createForm(RepositoryType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RepositoryData $data */
            $data = $form->getData();
            $repository = $data->applyTo($config['repositories'][$index]);
            $config['repositories'][$index] = $repository;
            if ($this->save($config, 'Repository updated.')) {
                $this->secrets->rename($oldUrl, $repository['url']);
                $this->secrets->set($repository['url'], $data->webhookSecret);

                return $this->redirectToRoute('app_repositories');
            }
        }

        return $this->render('repository/edit.html.twig', [
            'index' => $index,
            'repository' => $config['repositories'][$index],
            'form' => $form,
        ]);
    }

    #[Route('/{index}/delete', name: 'app_repository_delete', requirements: ['index' => '\d+'], methods: ['POST'])]
    public function delete(int $index, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-repository-'.$index, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $config = $this->config->load();
        if (isset($config['repositories'][$index])) {
            $url = (string) ($config['repositories'][$index]['url'] ?? '');
            unset($config['repositories'][$index]);
            if ($this->save($config, 'Repository removed.') && '' !== $url) {
                $this->secrets->remove($url);
            }
        }

        return $this->redirectToRoute('app_repositories');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function save(array $config, string $message): bool
    {
        try {
            $this->config->save($config);
            $this->addFlash('success', $message.' Run a build to publish the change.');

            return true;
        } catch (ConfigException $e) {
            $this->addFlash('error', $e->getMessage());

            return false;
        }
    }
}
