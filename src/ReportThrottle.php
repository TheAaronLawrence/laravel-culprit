<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Support\Facades\Date;

/**
 * In-memory limits for one process: the same error is sent at most once per
 * 10 seconds, and nothing is sent while the tracker has asked us to back off.
 */
final class ReportThrottle
{
    public const DEDUPE_SECONDS = 10;

    public const DEFAULT_BACKOFF_SECONDS = 60;

    private const MAX_REMEMBERED = 500;

    /** @var array<string, int> fingerprint => last sent timestamp */
    private array $sent = [];

    private int $backOffUntil = 0;

    public function isBackingOff(): bool
    {
        return $this->now() < $this->backOffUntil;
    }

    /**
     * Record the fingerprint and say whether it may be sent now.
     */
    public function attempt(string $fingerprint): bool
    {
        $now = $this->now();
        $last = $this->sent[$fingerprint] ?? null;

        if ($last !== null && $now - $last < self::DEDUPE_SECONDS) {
            return false;
        }

        if (count($this->sent) >= self::MAX_REMEMBERED) {
            $this->sent = array_filter($this->sent, fn (int $at): bool => $now - $at < self::DEDUPE_SECONDS);
        }

        $this->sent[$fingerprint] = $now;

        return true;
    }

    public function backOff(?int $seconds): void
    {
        $seconds = $seconds !== null && $seconds > 0 ? $seconds : self::DEFAULT_BACKOFF_SECONDS;

        $this->backOffUntil = max($this->backOffUntil, $this->now() + $seconds);
    }

    private function now(): int
    {
        return Date::now()->getTimestamp();
    }
}
