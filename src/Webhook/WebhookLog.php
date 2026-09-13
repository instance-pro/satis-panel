<?php

declare(strict_types=1);

namespace App\Webhook;

use Predis\Client;
use Psr\Log\LoggerInterface;

/**
 * Keeps the last N webhook requests in a Redis list. Only active when
 * REDIS_URL is configured; a Redis outage never breaks the webhook itself.
 */
final class WebhookLog
{
    public const DEFAULT_RETENTION = 100;
    public const RETENTION_OPTIONS = [10, 25, 50, 100, 250, 500, 1000];
    public const PER_PAGE = 10;
    public const MAX_PAYLOAD_BYTES = 65536;

    private const KEY_LOG = 'satis-panel:webhook:log';
    private const KEY_RETENTION = 'satis-panel:webhook:retention';

    private ?Client $client = null;

    public function __construct(
        private readonly string $redisUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->redisUrl);
    }

    /**
     * Returns null when Redis answers, otherwise the connection error.
     */
    public function connectionError(): ?string
    {
        if (!$this->isEnabled()) {
            return 'REDIS_URL is not configured.';
        }
        try {
            $this->client()->ping();

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function record(array $entry): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $client = $this->client();
            $client->lpush(self::KEY_LOG, [json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
            $client->ltrim(self::KEY_LOG, 0, $this->retention() - 1);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook log: cannot write to Redis.', ['exception' => $e]);
        }
    }

    /**
     * @return array{entries: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function page(int $page): array
    {
        $client = $this->client();
        $total = (int) $client->llen(self::KEY_LOG);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $start = ($page - 1) * self::PER_PAGE;

        $entries = [];
        foreach ($client->lrange(self::KEY_LOG, $start, $start + self::PER_PAGE - 1) as $json) {
            $entry = json_decode((string) $json, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE];
    }

    public function retention(): int
    {
        $value = (int) ($this->client()->get(self::KEY_RETENTION) ?? 0);

        return $value > 0 ? $value : self::DEFAULT_RETENTION;
    }

    public function setRetention(int $retention): void
    {
        if (!in_array($retention, self::RETENTION_OPTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported retention value.');
        }
        $client = $this->client();
        $client->set(self::KEY_RETENTION, (string) $retention);
        $client->ltrim(self::KEY_LOG, 0, $retention - 1);
    }

    public function clear(): void
    {
        $this->client()->del([self::KEY_LOG]);
    }

    private function client(): Client
    {
        if (null === $this->client) {
            if (!$this->isEnabled()) {
                throw new \RuntimeException('REDIS_URL is not configured.');
            }
            // redis://:@host (empty password from an unset variable) means no authentication
            $url = (string) preg_replace('#^([a-z]+://):@#', '$1', trim($this->redisUrl));
            $this->client = new Client($url, ['exceptions' => true]);
        }

        return $this->client;
    }
}
