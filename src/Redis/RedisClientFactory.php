<?php

declare(strict_types=1);

namespace App\Redis;

use Predis\Client;

/**
 * Shared Predis client for the optional Redis features (webhook log, build queue).
 */
final class RedisClientFactory
{
    private ?Client $client = null;

    public function __construct(private readonly string $redisUrl)
    {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->redisUrl);
    }

    public function client(): Client
    {
        if (null === $this->client) {
            if (!$this->isConfigured()) {
                throw new \RuntimeException('REDIS_URL is not configured.');
            }
            // redis://:@host (empty password from an unset variable) means no authentication
            $url = (string) preg_replace('#^([a-z]+://):@#', '$1', trim($this->redisUrl));
            $this->client = new Client($url, ['exceptions' => true]);
        }

        return $this->client;
    }

    /**
     * Returns null when Redis answers, otherwise the connection error.
     */
    public function connectionError(): ?string
    {
        if (!$this->isConfigured()) {
            return 'REDIS_URL is not configured.';
        }
        try {
            $this->client()->ping();

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
