<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ConfigurationType;
use App\Satis\ConfigException;
use App\Satis\ConfigurationData;
use App\Satis\SatisConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigurationController extends AbstractController
{
    public function __construct(private readonly SatisConfig $config)
    {
    }

    #[Route('/admin/configuration', name: 'app_configuration', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $config = $this->config->load();
        } catch (ConfigException $e) {
            $this->addFlash('error', $e->getMessage());
            $config = $this->config->defaults();
        }

        $form = $this->createForm(ConfigurationType::class, ConfigurationData::fromArray($config));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ConfigurationData $data */
            $data = $form->getData();
            try {
                $this->config->save($data->applyTo($config));
                $this->addFlash('success', 'Configuration saved. Run a build to publish the change.');

                return $this->redirectToRoute('app_configuration');
            } catch (ConfigException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $raw = $this->config->exists() ? (string) file_get_contents($this->config->path()) : json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $this->render('configuration/index.html.twig', [
            'form' => $form,
            'config_path' => $this->config->path(),
            'raw_json' => $raw,
        ]);
    }
}
