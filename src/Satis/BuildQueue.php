<?php

declare(strict_types=1);

namespace App\Satis;

use App\Redis\RedisClientFactory;

/**
 * Build jobs in a Redis list, processed one after another by app:build:worker.
 *
 *   satis-panel:build:queue       waiting jobs (JSON), oldest first
 *   satis-panel:build:processing  the job the worker is currently building
 *   satis-panel:build:worker      heartbeat of the worker
 *
 * A job is moved from queue to processing atomically (BLMOVE) and removed
 * only after the build finished, so a crashed worker loses nothing: on start
 * it moves leftovers from processing back to the front of the queue.
 */
final class BuildQueue
{
    public const KEY_QUEUE = 'satis-panel:build:queue';
    public const KEY_PROCESSING = 'satis-panel:build:processing';
    public const KEY_WORKER = 'satis-panel:build:worker';
    public const WORKER_STALE_SECONDS = 30;

    public function __construct(private readonly RedisClientFactory $redis)
    {
    }

    public function isEnabled(): bool
    {
        return $this->redis->isConfigured();
    }

    /**
     * @param list<string> $repositoryUrls empty = full build
     *
     * @return string 'queued' or 'merged' (an equivalent job is already waiting)
     */
    public function enqueue(array $repositoryUrls, string $trigger): string
    {
        $client = $this->redis->client();
        $repositoryUrls = array_values(array_unique($repositoryUrls));
        sort($repositoryUrls);

        foreach ($this->entries() as $entry) {
            $waiting = $entry['repositories'];
            if ([] === $waiting) {
                return 'merged'; // a full build is already waiting and covers everything
            }
            if ($waiting === $repositoryUrls) {
                return 'merged';
            }
        }
        if ([] === $repositoryUrls) {
            $client->del([self::KEY_QUEUE]); // a full build replaces all waiting partial builds
        }
        $client->rpush(self::KEY_QUEUE, [json_encode([
            'repositories' => $repositoryUrls,
            'trigger' => $trigger,
            'queued_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_UNESCAPED_SLASHES)]);

        return 'queued';
    }

    /**
     * @return list<array{repositories: list<string>, trigger: string, queued_at: string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->redis->client()->lrange(self::KEY_QUEUE, 0, -1) as $json) {
            $entry = $this->decode((string) $json);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function length(): int
    {
        return (int) $this->redis->client()->llen(self::KEY_QUEUE);
    }

    /**
     * Blocks up to $timeout seconds for the next job and moves it to the processing list.
     *
     * @return array{raw: string, repositories: list<string>, trigger: string, queued_at: string}|null
     */
    public function reserve(int $timeout): ?array
    {
        $json = $this->redis->client()->blmove(self::KEY_QUEUE, self::KEY_PROCESSING, 'LEFT', 'RIGHT', $timeout);
        if (!is_string($json) || '' === $json) {
            return null;
        }
        $entry = $this->decode($json);
        if (null === $entry) {
            $this->redis->client()->lrem(self::KEY_PROCESSING, 1, $json);

            return null;
        }

        return ['raw' => $json] + $entry;
    }

    public function ack(string $raw): void
    {
        $this->redis->client()->lrem(self::KEY_PROCESSING, 1, $raw);
    }

    /**
     * Moves jobs a crashed worker left in the processing list back to the queue.
     */
    public function recover(): int
    {
        $client = $this->redis->client();
        $moved = 0;
        while (null !== $client->lmove(self::KEY_PROCESSING, self::KEY_QUEUE, 'RIGHT', 'LEFT')) {
            $moved++;
            if ($moved > 1000) {
                break;
            }
        }

        return $moved;
    }

    public function heartbeat(string $state): void
    {
        $this->redis->client()->set(self::KEY_WORKER, json_encode([
            'seen_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pid' => getmypid(),
            'state' => $state,
        ]));
    }

    /**
     * @return array{seen_at: \DateTimeImmutable, pid: int, state: string, alive: bool}|null
     */
    public function worker(): ?array
    {
        $data = json_decode((string) $this->redis->client()->get(self::KEY_WORKER), true);
        if (!is_array($data) || !is_string($data['seen_at'] ?? null)) {
            return null;
        }
        $seenAt = new \DateTimeImmutable($data['seen_at']);

        return [
            'seen_at' => $seenAt,
            'pid' => (int) ($data['pid'] ?? 0),
            'state' => (string) ($data['state'] ?? 'idle'),
            'alive' => (time() - $seenAt->getTimestamp()) <= self::WORKER_STALE_SECONDS,
        ];
    }

    /**
     * @return array{repositories: list<string>, trigger: string, queued_at: string}|null
     */
    private function decode(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'repositories' => array_values(array_map('strval', (array) ($data['repositories'] ?? []))),
            'trigger' => (string) ($data['trigger'] ?? ''),
            'queued_at' => (string) ($data['queued_at'] ?? ''),
        ];
    }
}
