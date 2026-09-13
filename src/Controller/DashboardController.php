<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\HtpasswdManager;
use App\Satis\BuildOutput;
use App\Satis\BuildRunner;
use App\Satis\ConfigException;
use App\Satis\SatisConfig;
use App\Ssh\SshKeyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SatisConfig $config,
        private readonly BuildRunner $builds,
        private readonly BuildOutput $output,
        private readonly HtpasswdManager $htpasswd,
        private readonly SshKeyManager $ssh,
        private readonly string $webhookSecret,
        private readonly bool $satisAuthDisabled,
    ) {
    }

    #[Route('/admin', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $configError = null;
        $config = [];
        try {
            $config = $this->config->load();
        } catch (ConfigException $e) {
            $configError = $e->getMessage();
        }

        $built = array_filter($this->output->packagesForRepositories($config['repositories'] ?? []));
        $outputDir = $this->config->outputDir();
        $packagesJson = $outputDir.'/packages.json';
        $composerAuth = '' !== (string) getenv('COMPOSER_AUTH') || is_file((getenv('COMPOSER_HOME') ?: '').'/auth.json');

        return $this->render('dashboard/index.html.twig', [
            'config' => $config,
            'config_error' => $configError,
            'config_path' => $this->config->path(),
            'repository_count' => count($config['repositories'] ?? []),
            'built_count' => count($built),
            'package_count' => count(array_unique(array_merge([], ...array_values($built)))),
            'archive_enabled' => isset($config['archive']),
            'output_dir' => $outputDir,
            'output_writable' => is_dir($outputDir) && is_writable($outputDir),
            'last_build_file' => is_file($packagesJson) ? date('Y-m-d H:i:s T', filemtime($packagesJson) ?: 0) : null,
            'build' => $this->builds->status(),
            'users' => $this->htpasswd->users(),
            'auth_disabled' => $this->satisAuthDisabled,
            'ssh_public_key' => $this->ssh->publicKey(),
            'composer_auth' => $composerAuth,
            'webhook_url' => '' !== $this->webhookSecret ? $this->generateUrl('app_webhook', ['token' => $this->webhookSecret], 0) : null,
            'base_url' => $request->getSchemeAndHttpHost(),
        ]);
    }
}
