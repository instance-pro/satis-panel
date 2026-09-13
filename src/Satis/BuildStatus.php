<?php

declare(strict_types=1);

namespace App\Satis;

final class BuildStatus
{
    public const IDLE = 'idle';
    public const STARTING = 'starting';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    /**
     * @param list<string> $repositories
     */
    public function __construct(
        public readonly string $state,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $finishedAt = null,
        public readonly ?int $exitCode = null,
        public readonly array $repositories = [],
        public readonly string $trigger = '',
    ) {
    }

    public function isRunning(): bool
    {
        return in_array($this->state, [self::STARTING, self::RUNNING], true);
    }

    public function label(): string
    {
        return match ($this->state) {
            self::STARTING => 'Starting',
            self::RUNNING => 'Running',
            self::SUCCESS => 'Successful',
            self::FAILED => 'Failed',
            default => 'Never built',
        };
    }

    public function badgeClass(): string
    {
        return match ($this->state) {
            self::STARTING, self::RUNNING => 'badge-amber',
            self::SUCCESS => 'badge-green',
            self::FAILED => 'badge-red',
            default => 'badge-slate',
        };
    }

    /** Formats a timestamp in the configured timezone (TZ), including its abbreviation. */
    public static function local(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s T');
    }

    public function meta(): string
    {
        $parts = [];
        if (null !== $this->startedAt) {
            $parts[] = 'started '.self::local($this->startedAt);
        }
        if (null !== $this->finishedAt) {
            $parts[] = 'finished '.self::local($this->finishedAt);
        }
        if (null !== $this->exitCode) {
            $parts[] = 'exit code '.$this->exitCode;
        }
        if ('' !== $this->trigger) {
            $parts[] = 'trigger: '.$this->trigger;
        }
        if ([] !== $this->repositories) {
            $parts[] = 'partial build: '.implode(', ', $this->repositories);
        }

        return implode(' · ', $parts);
    }
}
