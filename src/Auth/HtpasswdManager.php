<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Maintains the htpasswd file nginx uses for the package files (bcrypt hashes).
 * The plain passwords are kept as well in composer-users.json next to it, so
 * that they can be looked up in the UI later (Composer clients need them).
 */
final class HtpasswdManager
{
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._@+-]{1,64}$/';

    public function __construct(private readonly string $htpasswdFile)
    {
    }

    public function path(): string
    {
        return $this->htpasswdFile;
    }

    public function passwordsPath(): string
    {
        return dirname($this->htpasswdFile).'/composer-users.json';
    }

    /**
     * Plain password of a user, null when unknown (user created before passwords were kept).
     */
    public function password(string $user): ?string
    {
        $password = $this->readPasswords()[$user] ?? null;

        return is_string($password) && '' !== $password ? $password : null;
    }

    /**
     * @return list<string>
     */
    public function users(): array
    {
        return array_keys($this->read());
    }

    public function has(string $user): bool
    {
        return array_key_exists($user, $this->read());
    }

    public function set(string $user, #[\SensitiveParameter] string $password): void
    {
        if (!preg_match(self::USERNAME_PATTERN, $user)) {
            throw new \InvalidArgumentException('Invalid user name.');
        }
        if ('' === $password) {
            throw new \InvalidArgumentException('Password must not be empty.');
        }
        $users = $this->read();
        $users[$user] = password_hash($password, PASSWORD_BCRYPT);
        $this->write($users);

        $passwords = $this->readPasswords();
        $passwords[$user] = $password;
        $this->writePasswords($passwords);
    }

    public function remove(string $user): void
    {
        $users = $this->read();
        unset($users[$user]);
        $this->write($users);

        $passwords = $this->readPasswords();
        unset($passwords[$user]);
        $this->writePasswords($passwords);
    }

    /**
     * @return array<string, string>
     */
    private function readPasswords(): array
    {
        if (!is_file($this->passwordsPath())) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->passwordsPath()), true);

        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    /**
     * @param array<string, string> $passwords
     */
    private function writePasswords(array $passwords): void
    {
        ksort($passwords, SORT_NATURAL | SORT_FLAG_CASE);
        $file = $this->passwordsPath();
        $tmp = $file.'.tmp';
        if (false === file_put_contents($tmp, json_encode((object) $passwords, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $file));
        }
        chmod($tmp, 0600);
        self::keepOwnership($file, $tmp);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot write %s.', $file));
        }
    }

    /**
     * When a console command runs as root the new file must still belong to the
     * web server user, otherwise nginx and the UI cannot read it any more.
     */
    public static function keepOwnership(string $target, string $tmp): void
    {
        $reference = is_file($target) ? $target : dirname($target);
        $owner = fileowner($reference);
        $group = filegroup($reference);
        if (false !== $owner) {
            @chown($tmp, $owner);
        }
        if (false !== $group) {
            @chgrp($tmp, $group);
        }
    }

    /**
     * @return array<string, string> user => hash
     */
    private function read(): array
    {
        if (!is_file($this->htpasswdFile)) {
            return [];
        }
        $users = [];
        foreach (file($this->htpasswdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#') || !str_contains($line, ':')) {
                continue;
            }
            [$user, $hash] = explode(':', $line, 2);
            $users[trim($user)] = trim($hash);
        }

        return $users;
    }

    /**
     * @param array<string, string> $users
     */
    private function write(array $users): void
    {
        ksort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $dir = dirname($this->htpasswdFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $content = '';
        foreach ($users as $user => $hash) {
            $content .= $user.':'.$hash."\n";
        }
        $tmp = $this->htpasswdFile.'.tmp';
        if (false === file_put_contents($tmp, $content, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->htpasswdFile));
        }
        chmod($tmp, 0640);
        self::keepOwnership($this->htpasswdFile, $tmp);
        if (!rename($tmp, $this->htpasswdFile)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot write %s.', $this->htpasswdFile));
        }
    }
}
