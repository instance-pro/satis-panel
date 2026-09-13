<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Manages the Composer auth.json in COMPOSER_HOME, used by satis to reach
 * private HTTPS sources (GitHub/GitLab tokens, Bitbucket OAuth, basic auth
 * for other Composer repositories).
 */
final class ComposerAuthManager
{
    public const TYPES = [
        'github-oauth' => ['label' => 'GitHub token (github-oauth)', 'fields' => ['token']],
        'gitlab-token' => ['label' => 'GitLab private/deploy token (gitlab-token)', 'fields' => ['token']],
        'gitlab-oauth' => ['label' => 'GitLab OAuth token (gitlab-oauth)', 'fields' => ['token']],
        'bitbucket-oauth' => ['label' => 'Bitbucket OAuth consumer (bitbucket-oauth)', 'fields' => ['consumer-key', 'consumer-secret']],
        'http-basic' => ['label' => 'HTTP basic auth (http-basic)', 'fields' => ['username', 'password']],
        'bearer' => ['label' => 'Bearer token (bearer)', 'fields' => ['token']],
    ];

    private readonly string $file;

    public function __construct(string $composerHome)
    {
        $this->file = rtrim($composerHome, '/').'/auth.json';
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * @return list<array{type: string, host: string, summary: string, secret: string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->read() as $type => $hosts) {
            if (!isset(self::TYPES[$type]) || !is_array($hosts)) {
                continue;
            }
            foreach ($hosts as $host => $value) {
                [$summary, $secret] = $this->describe($type, $value);
                $entries[] = ['type' => $type, 'host' => (string) $host, 'summary' => $summary, 'secret' => $secret];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, string> $values field => value
     */
    public function set(string $type, string $host, array $values): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Unsupported authentication type.');
        }
        $host = strtolower(trim($host));
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            throw new \InvalidArgumentException('Host must be a host name like github.com, without scheme or path.');
        }
        $fields = self::TYPES[$type]['fields'];
        foreach ($fields as $field) {
            if ('' === trim((string) ($values[$field] ?? ''))) {
                throw new \InvalidArgumentException(sprintf('%s is required.', ucfirst($field)));
            }
        }
        $data = $this->read();
        if (1 === count($fields)) {
            $data[$type][$host] = trim($values[$fields[0]]);
        } else {
            $entry = [];
            foreach ($fields as $field) {
                $entry[$field] = trim($values[$field]);
            }
            $data[$type][$host] = $entry;
        }
        $this->write($data);
    }

    public function remove(string $type, string $host): void
    {
        $data = $this->read();
        unset($data[$type][$host]);
        if (isset($data[$type]) && [] === $data[$type]) {
            unset($data[$type]);
        }
        $this->write($data);
    }

    /**
     * @return array<string, mixed>
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
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $tmp = $this->file.'.tmp';
        $json = [] === $data ? "{}\n" : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (false === file_put_contents($tmp, $json, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->file));
        }
        chmod($tmp, 0600);
        HtpasswdManager::keepOwnership($this->file, $tmp);
        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->file));
        }
    }

    /**
     * @return array{0: string, 1: string} visible summary and the secret part
     */
    private function describe(string $type, mixed $value): array
    {
        if (is_array($value)) {
            $fields = self::TYPES[$type]['fields'];
            $first = (string) ($value[$fields[0]] ?? '');
            $second = (string) ($value[$fields[1] ?? ''] ?? '');

            return [$first, $second];
        }

        return ['', (string) $value];
    }
}
