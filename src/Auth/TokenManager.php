<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Access tokens for Composer clients ("bearer" auth), stored in tokens.json
 * next to the htpasswd file. nginx delegates requests with an Authorization
 * Bearer header to the app (auth_request), which verifies them here.
 */
final class TokenManager
{
    public const NAME_PATTERN = '/^[\w .@+-]{1,64}$/u';
    private const PREFIX = 'satis_';
    private const TOUCH_INTERVAL = 3600;

    private readonly string $file;

    public function __construct(string $htpasswdFile)
    {
        $this->file = dirname($htpasswdFile).'/tokens.json';
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * @return list<array{id: string, name: string, token: string, created_at: string, last_used_at: ?string}>
     */
    public function all(): array
    {
        $tokens = array_values($this->read());
        usort($tokens, static fn (array $a, array $b): int => strcmp($a['created_at'], $b['created_at']));

        return $tokens;
    }

    /**
     * @return array{id: string, name: string, token: string, created_at: string, last_used_at: ?string}
     */
    public function create(string $name): array
    {
        $name = trim($name);
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Invalid token name.');
        }
        $tokens = $this->read();
        $entry = [
            'id' => bin2hex(random_bytes(6)),
            'name' => $name,
            'token' => self::PREFIX.bin2hex(random_bytes(24)),
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'last_used_at' => null,
        ];
        $tokens[$entry['id']] = $entry;
        $this->write($tokens);

        return $entry;
    }

    public function remove(string $id): void
    {
        $tokens = $this->read();
        unset($tokens[$id]);
        $this->write($tokens);
    }

    /**
     * Returns the matching entry or null. Updates last_used_at at most once per hour.
     */
    public function verify(#[\SensitiveParameter] string $token): ?array
    {
        $token = trim($token);
        if ('' === $token) {
            return null;
        }
        $tokens = $this->read();
        foreach ($tokens as $id => $entry) {
            if (hash_equals($entry['token'], $token)) {
                $last = $entry['last_used_at'] ? strtotime($entry['last_used_at']) : 0;
                if (time() - (int) $last > self::TOUCH_INTERVAL) {
                    $tokens[$id]['last_used_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                    try {
                        $this->write($tokens);
                    } catch (\RuntimeException) {
                        // read-only file system must not break authentication
                    }
                }

                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{id: string, name: string, token: string, created_at: string, last_used_at: ?string}>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($data)) {
            return [];
        }
        $tokens = [];
        foreach ($data as $id => $entry) {
            if (is_array($entry) && is_string($entry['token'] ?? null)) {
                $tokens[(string) $id] = [
                    'id' => (string) $id,
                    'name' => (string) ($entry['name'] ?? ''),
                    'token' => $entry['token'],
                    'created_at' => (string) ($entry['created_at'] ?? ''),
                    'last_used_at' => isset($entry['last_used_at']) ? (string) $entry['last_used_at'] : null,
                ];
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, array<string, mixed>> $tokens
     */
    private function write(array $tokens): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $tmp = $this->file.'.tmp';
        if (false === file_put_contents($tmp, json_encode((object) $tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX)) {
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
