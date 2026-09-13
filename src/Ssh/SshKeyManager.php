<?php

declare(strict_types=1);

namespace App\Ssh;

use Symfony\Component\Process\Process;

/**
 * Manages the SSH identity git uses for private repositories plus the
 * user-level known_hosts file. Everything lives in SSH_DIR (a volume).
 */
final class SshKeyManager
{
    public const KEY_NAME = 'id_satis_panel';

    public function __construct(private readonly string $sshDir)
    {
    }

    public function dir(): string
    {
        return $this->sshDir;
    }

    public function privateKeyPath(): string
    {
        return $this->sshDir.'/'.self::KEY_NAME;
    }

    public function publicKeyPath(): string
    {
        return $this->privateKeyPath().'.pub';
    }

    public function hasKey(): bool
    {
        return is_file($this->privateKeyPath());
    }

    public function publicKey(): ?string
    {
        if (!$this->hasKey()) {
            return null;
        }
        if (!is_file($this->publicKeyPath())) {
            $this->writePublicKey();
        }
        $key = @file_get_contents($this->publicKeyPath());

        return false === $key ? null : trim($key);
    }

    public function fingerprint(): ?string
    {
        if (!is_file($this->publicKeyPath())) {
            return null;
        }
        $process = new Process(['ssh-keygen', '-lf', $this->publicKeyPath()]);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    public function generate(string $type = 'ed25519', string $comment = ''): void
    {
        if (!in_array($type, ['ed25519', 'rsa'], true)) {
            throw new \InvalidArgumentException('Unsupported key type.');
        }
        $this->ensureDir();
        $this->delete();

        $command = ['ssh-keygen', '-q', '-t', $type, '-N', '', '-C', '' !== $comment ? $comment : 'satis-panel', '-f', $this->privateKeyPath()];
        if ('rsa' === $type) {
            array_splice($command, 4, 0, ['-b', '4096']);
        }
        $process = new Process($command);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ssh-keygen failed: '.trim($process->getErrorOutput()));
        }
        chmod($this->privateKeyPath(), 0600);
        $this->writeSshConfig();
    }

    /**
     * Imports an existing private key (OpenSSH or PEM format).
     */
    public function import(#[\SensitiveParameter] string $privateKey): void
    {
        $privateKey = str_replace("\r\n", "\n", trim($privateKey))."\n";
        if (!preg_match('/^-----BEGIN [A-Z ]*PRIVATE KEY-----/', $privateKey)) {
            throw new \InvalidArgumentException('This does not look like a private key.');
        }
        $this->ensureDir();
        $tmp = $this->sshDir.'/.import.tmp';
        file_put_contents($tmp, $privateKey);
        chmod($tmp, 0600);

        $process = new Process(['ssh-keygen', '-y', '-P', '', '-f', $tmp]);
        $process->run();
        if (!$process->isSuccessful()) {
            @unlink($tmp);
            throw new \InvalidArgumentException('The private key is invalid or passphrase protected: '.trim($process->getErrorOutput()));
        }

        $this->delete();
        rename($tmp, $this->privateKeyPath());
        file_put_contents($this->publicKeyPath(), trim($process->getOutput())."\n");
        chmod($this->publicKeyPath(), 0644);
        $this->writeSshConfig();
    }

    public function delete(): void
    {
        foreach ([$this->privateKeyPath(), $this->publicKeyPath()] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return list<array{host: string, type: string}>
     */
    public function knownHosts(): array
    {
        $file = $this->knownHostsPath();
        if (!is_file($file)) {
            return [];
        }
        $hosts = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (false === $parts || count($parts) < 2) {
                continue;
            }
            $hosts[] = ['host' => str_starts_with($parts[0], '|1|') ? '(hashed entry)' : $parts[0], 'type' => $parts[1]];
        }

        return $hosts;
    }

    public function addKnownHost(string $host, int $port = 22): void
    {
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new \InvalidArgumentException('Invalid host name.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid port.');
        }
        $process = new Process(['ssh-keyscan', '-T', '10', '-p', (string) $port, $host], null, null, null, 30);
        $process->run();
        // OpenSSH 10 prints "# host:port banner" comment lines to stdout, keep only key lines.
        $lines = array_filter(preg_split('/\r?\n/', $process->getOutput()) ?: [], static fn (string $l): bool => '' !== trim($l) && !str_starts_with(ltrim($l), '#'));
        $keys = implode("\n", $lines);
        if ('' === $keys) {
            throw new \RuntimeException(sprintf('ssh-keyscan did not return any key for %s:%d. %s', $host, $port, trim($process->getErrorOutput())));
        }
        $this->ensureDir();
        $this->removeKnownHost($host, $port);
        file_put_contents($this->knownHostsPath(), $keys."\n", FILE_APPEND | LOCK_EX);
    }

    public function removeKnownHost(string $host, ?int $port = null): void
    {
        $file = $this->knownHostsPath();
        if (!is_file($file)) {
            return;
        }
        $names = [];
        if (null === $port) {
            $names[] = strtolower($host);
            $names[] = '['.strtolower($host).']';
        } else {
            $names[] = 22 === $port ? strtolower($host) : sprintf('[%s]:%d', strtolower($host), $port);
        }
        $kept = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $first = strtolower((string) strtok(trim($line), " \t"));
            $drop = false;
            foreach ($names as $name) {
                if ($first === $name || (null === $port && str_starts_with($first, $name.']:'))) {
                    $drop = true;
                }
            }
            if (!$drop) {
                $kept[] = $line;
            }
        }
        file_put_contents($file, implode("\n", $kept).([] !== $kept ? "\n" : ''), LOCK_EX);
    }

    public function knownHostsPath(): string
    {
        return $this->sshDir.'/known_hosts';
    }

    private function writePublicKey(): void
    {
        $process = new Process(['ssh-keygen', '-y', '-P', '', '-f', $this->privateKeyPath()]);
        $process->run();
        if ($process->isSuccessful()) {
            file_put_contents($this->publicKeyPath(), trim($process->getOutput())."\n");
        }
    }

    private function writeSshConfig(): void
    {
        $config = sprintf("Host *\n    IdentityFile %s\n    IdentitiesOnly yes\n", $this->privateKeyPath());
        file_put_contents($this->sshDir.'/config', $config, LOCK_EX);
        chmod($this->sshDir.'/config', 0600);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->sshDir) && !@mkdir($this->sshDir, 0700, true) && !is_dir($this->sshDir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $this->sshDir));
        }
    }
}
