<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Auth\HtpasswdManager;
use App\Satis\RepositoryUrlMatcher;

/**
 * Per-repository webhook secrets, stored next to satis.json in webhooks.json
 * (never inside satis.json, which is passed to satis and may be shared).
 * Keys are normalized repository URLs so that a secret survives switching a
 * repository between its SSH and HTTPS URL.
 */
final class WebhookSecrets
{
    private readonly string $file;

    public function __construct(string $satisConfigFile)
    {
        $this->file = dirname($satisConfigFile).'/webhooks.json';
    }

    public function path(): string
    {
        return $this->file;
    }

    public function get(string $url): ?string
    {
        $entry = $this->read()[RepositoryUrlMatcher::normalize($url)] ?? null;
        $secret = $entry['secret'] ?? null;

        return is_string($secret) && '' !== $secret ? $secret : null;
    }

    public function has(string $url): bool
    {
        return null !== $this->get($url);
    }

    /**
     * @return array<string, true> normalized url => true
     */
    public function configured(): array
    {
        $result = [];
        foreach ($this->read() as $key => $entry) {
            if (is_string($entry['secret'] ?? null) && '' !== $entry['secret']) {
                $result[$key] = true;
            }
        }

        return $result;
    }

    public function set(string $url, ?string $secret): void
    {
        $secrets = $this->read();
        $key = RepositoryUrlMatcher::normalize($url);
        if (null === $secret || '' === trim($secret)) {
            unset($secrets[$key]);
        } else {
            $secrets[$key] = ['url' => $url, 'secret' => trim($secret)];
        }
        $this->write($secrets);
    }

    public function rename(string $oldUrl, string $newUrl): void
    {
        $oldKey = RepositoryUrlMatcher::normalize($oldUrl);
        $newKey = RepositoryUrlMatcher::normalize($newUrl);
        if ($oldKey === $newKey) {
            return;
        }
        $secrets = $this->read();
        if (isset($secrets[$oldKey])) {
            $secrets[$newKey] = ['url' => $newUrl, 'secret' => $secrets[$oldKey]['secret']];
            unset($secrets[$oldKey]);
            $this->write($secrets);
        }
    }

    public function remove(string $url): void
    {
        $this->set($url, null);
    }

    /**
     * @return array<string, array{url: string, secret: string}>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array{url: string, secret: string}> $secrets
     */
    private function write(array $secrets): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $tmp = $this->file.'.tmp';
        if (false === file_put_contents($tmp, json_encode($secrets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->file));
        }
        chmod($tmp, 0600);
        HtpasswdManager::keepOwnership($this->file, $tmp);
        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->file));
        }
    }
}
