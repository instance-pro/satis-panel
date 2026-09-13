<?php

declare(strict_types=1);

namespace App\Satis;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs "satis build" and keeps its state in var/satis:
 *   build.lock    flock() held while a build runs (the real mutual exclusion)
 *   build.pid     pid of the running build
 *   build.log     output of the last/current build
 *   build.json    status of the last/current build
 *
 * The web UI starts builds detached through "bin/console app:satis:build",
 * so a build survives the HTTP request that triggered it.
 */
final class BuildRunner
{
    private const STARTING_GRACE_SECONDS = 30;

    public function __construct(
        private readonly string $buildStateDir,
        private readonly string $satisBin,
        private readonly string $projectDir,
        private readonly SatisConfig $config,
    ) {
    }

    public function status(): BuildStatus
    {
        $status = $this->readStatus();
        if (null === $status) {
            return new BuildStatus(BuildStatus::IDLE);
        }
        if ($status->isRunning() && !$this->processAlive()) {
            $graceOver = BuildStatus::STARTING === $status->state
                && null !== $status->startedAt
                && (time() - $status->startedAt->getTimestamp()) > self::STARTING_GRACE_SECONDS;
            if (BuildStatus::RUNNING === $status->state || $graceOver) {
                // The worker died without writing a final status (OOM, container stop, ...).
                $status = new BuildStatus(BuildStatus::FAILED, $status->startedAt, new \DateTimeImmutable(), null, $status->repositories, $status->trigger);
                $this->writeStatus($status);
            }
        }

        return $status;
    }

    public function isRunning(): bool
    {
        return $this->status()->isRunning();
    }

    public function log(int $maxBytes = 250_000): string
    {
        $file = $this->buildStateDir.'/build.log';
        if (!is_file($file)) {
            return '';
        }
        $size = filesize($file) ?: 0;
        if ($size <= $maxBytes) {
            return (string) file_get_contents($file);
        }
        $tail = (string) file_get_contents($file, false, null, $size - $maxBytes, $maxBytes);

        return "[... log truncated ...]\n".$tail;
    }

    /**
     * Starts a detached build.
     *
     * @param list<string> $repositoryUrls only rebuild these repositories (satis --repository-url)
     *
     * @throws BuildRunningException
     */
    public function start(array $repositoryUrls = [], string $trigger = 'ui'): void
    {
        if ($this->isRunning()) {
            throw new BuildRunningException();
        }
        $this->ensureStateDir();
        $this->writeStatus(new BuildStatus(BuildStatus::STARTING, new \DateTimeImmutable(), null, null, $repositoryUrls, $trigger));
        @file_put_contents($this->buildStateDir.'/build.log', '');

        $command = [$this->php(), $this->projectDir.'/bin/console', 'app:satis:build', '--trigger='.$trigger, '--no-interaction', '--no-ansi'];
        foreach ($repositoryUrls as $url) {
            $command[] = '--repository-url='.$url;
        }
        $shell = 'setsid nohup '.implode(' ', array_map('escapeshellarg', $command)).' > /dev/null 2>&1 &';

        $process = Process::fromShellCommandline($shell, $this->projectDir, null, null, 10);
        $process->run();
    }

    /**
     * Runs the build in the current process (used by the console command).
     *
     * @param list<string>          $repositoryUrls
     * @param callable(string):void $onOutput
     *
     * @throws BuildRunningException
     */
    public function run(array $repositoryUrls, string $trigger, callable $onOutput): int
    {
        $this->ensureStateDir();
        $lock = fopen($this->buildStateDir.'/build.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new BuildRunningException();
        }

        $startedAt = new \DateTimeImmutable();
        $logFile = $this->buildStateDir.'/build.log';
        $log = fopen($logFile, 'w');
        file_put_contents($this->buildStateDir.'/build.pid', (string) getmypid());
        $this->writeStatus(new BuildStatus(BuildStatus::RUNNING, $startedAt, null, null, $repositoryUrls, $trigger));

        $write = static function (string $text) use ($log, $onOutput): void {
            if (false !== $log) {
                fwrite($log, $text);
                fflush($log);
            }
            $onOutput($text);
        };

        try {
            $command = $this->command($repositoryUrls);
            $write('$ '.implode(' ', array_map(static fn (string $a): string => str_contains($a, ' ') ? escapeshellarg($a) : $a, $command))."\n");
            $process = new Process($command, $this->projectDir, null, null, null);
            $exitCode = $process->run(static fn (string $type, string $buffer) => $write($buffer));
            $write(sprintf("\n[build %s with exit code %d]\n", 0 === $exitCode ? 'finished' : 'failed', $exitCode));
        } catch (\Throwable $e) {
            $exitCode = 1;
            $write("\n[build crashed: ".$e->getMessage()."]\n");
        } finally {
            $this->writeStatus(new BuildStatus(0 === $exitCode ? BuildStatus::SUCCESS : BuildStatus::FAILED, $startedAt, new \DateTimeImmutable(), $exitCode, $repositoryUrls, $trigger));
            @unlink($this->buildStateDir.'/build.pid');
            if (false !== $log) {
                fclose($log);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $exitCode;
    }

    /**
     * @param list<string> $repositoryUrls
     *
     * @return list<string>
     */
    public function command(array $repositoryUrls = []): array
    {
        $command = [$this->php(), $this->satisBin, 'build', $this->config->path(), $this->config->outputDir(), '--skip-errors', '--no-ansi', '--no-interaction', '-v'];
        foreach ($repositoryUrls as $url) {
            $command[] = '--repository-url='.$url;
        }

        return $command;
    }

    /**
     * PHP_BINARY points to php-fpm inside a web request, so look up the CLI binary.
     */
    private function php(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    private function processAlive(): bool
    {
        $pidFile = $this->buildStateDir.'/build.pid';
        if (!is_file($pidFile)) {
            return false;
        }
        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return file_exists('/proc/'.$pid);
    }

    private function readStatus(): ?BuildStatus
    {
        $file = $this->buildStateDir.'/build.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        $date = static fn (mixed $v): ?\DateTimeImmutable => is_string($v) && '' !== $v ? new \DateTimeImmutable($v) : null;

        return new BuildStatus(
            (string) ($data['state'] ?? BuildStatus::IDLE),
            $date($data['started_at'] ?? null),
            $date($data['finished_at'] ?? null),
            isset($data['exit_code']) ? (int) $data['exit_code'] : null,
            array_values(array_map('strval', (array) ($data['repositories'] ?? []))),
            (string) ($data['trigger'] ?? ''),
        );
    }

    private function writeStatus(BuildStatus $status): void
    {
        $this->ensureStateDir();
        file_put_contents($this->buildStateDir.'/build.json', json_encode([
            'state' => $status->state,
            'started_at' => $status->startedAt?->format(\DateTimeInterface::ATOM),
            'finished_at' => $status->finishedAt?->format(\DateTimeInterface::ATOM),
            'exit_code' => $status->exitCode,
            'repositories' => $status->repositories,
            'trigger' => $status->trigger,
        ], JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function ensureStateDir(): void
    {
        if (!is_dir($this->buildStateDir) && !@mkdir($this->buildStateDir, 0775, true) && !is_dir($this->buildStateDir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $this->buildStateDir));
        }
    }
}
