<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\RepositoryType;
use App\Satis\ConfigException;
use App\Satis\RepositoryData;
use App\Satis\SatisConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/repositories')]
final class RepositoryController extends AbstractController
{
    public function __construct(private readonly SatisConfig $config)
    {
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
            $config['repositories'][] = $data->applyTo();
            if ($this->save($config, 'Repository added.')) {
                return $this->redirectToRoute('app_repositories');
            }
        }

        return $this->render('repository/index.html.twig', [
            'repositories' => $config['repositories'],
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

        $form = $this->createForm(RepositoryType::class, RepositoryData::fromArray($config['repositories'][$index]));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RepositoryData $data */
            $data = $form->getData();
            $config['repositories'][$index] = $data->applyTo($config['repositories'][$index]);
            if ($this->save($config, 'Repository updated.')) {
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
            unset($config['repositories'][$index]);
            $this->save($config, 'Repository removed.');
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
