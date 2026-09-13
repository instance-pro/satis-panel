<?php

declare(strict_types=1);

namespace App\Controller;

use App\Satis\BuildRunner;
use App\Satis\BuildRunningException;
use App\Satis\ConfigException;
use App\Satis\RepositoryUrlMatcher;
use App\Satis\SatisConfig;
use App\Webhook\PayloadParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /webhook/<WEBHOOK_SECRET>
 *
 * Works with GitHub, GitLab, Gitea, Bitbucket and Azure DevOps push hooks:
 * every repository URL in the payload is matched against satis.json and a
 * partial build (satis --repository-url) is started for the matches.
 *
 * Manual use:  POST /webhook/<secret>?url=<repository url>
 * Full build:  POST /webhook/<secret>?full=1
 */
final class WebhookController
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly SatisConfig $config,
        private readonly BuildRunner $builds,
        private readonly PayloadParser $parser,
        private readonly RepositoryUrlMatcher $matcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/{token}', name: 'app_webhook', methods: ['POST'])]
    public function __invoke(string $token, Request $request): JsonResponse
    {
        if ('' === $this->webhookSecret || !hash_equals($this->webhookSecret, $token)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        try {
            $repositories = $this->config->repositories();
        } catch (ConfigException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        if ($request->query->getBoolean('full')) {
            return $this->start([], 'webhook (full)');
        }

        $candidates = [];
        foreach (explode(',', (string) $request->query->get('url', '')) as $url) {
            if ('' !== trim($url)) {
                $candidates[] = trim($url);
            }
        }
        $payload = $this->payload($request);
        if (null !== $payload) {
            $candidates = [...$candidates, ...$this->parser->extractUrls($payload)];
        }
        if ([] === $candidates) {
            return new JsonResponse(['error' => 'No repository URL found in the request. Send a JSON push payload or pass ?url=<repository url>.'], 400);
        }

        $matched = $this->matcher->match($repositories, $candidates);
        if ([] === $matched) {
            $this->logger->info('Webhook: no configured repository matches.', ['candidates' => $candidates]);

            return new JsonResponse(['error' => 'No configured repository matches the request.', 'candidates' => array_values(array_unique($candidates))], 404);
        }

        return $this->start($matched, 'webhook');
    }

    /**
     * @param list<string> $urls
     */
    private function start(array $urls, string $trigger): JsonResponse
    {
        try {
            $this->builds->start($urls, $trigger);
        } catch (BuildRunningException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'repositories' => $urls], 409);
        }
        $this->logger->info('Webhook: build started.', ['repositories' => $urls]);

        return new JsonResponse(['status' => 'started', 'repositories' => $urls], 202);
    }

    private function payload(Request $request): mixed
    {
        $content = $request->getContent();
        if ('' === $content || strlen($content) > 2_000_000) {
            return null;
        }
        // GitHub can send application/x-www-form-urlencoded with a "payload" field.
        if ($request->request->has('payload')) {
            $content = (string) $request->request->get('payload');
        }
        try {
            return json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $request->request->all();
        }
    }
}
