<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Contracts\Queue\Job;
use Throwable;

/**
 * The queued job being processed, if any.
 *
 * Set on `Queue::before`, cleared on `Queue::after` (success) and when a worker
 * loops for the next job. A failed job stays current, because the worker
 * reports its exception after the job's events have fired.
 */
final class CurrentJob
{
    private ?Job $job = null;

    public function set(Job $job): void
    {
        $this->job = $job;
    }

    public function clear(): void
    {
        $this->job = null;
    }

    public function exists(): bool
    {
        return $this->job !== null;
    }

    /**
     * Read from the job's metadata only. The serialised command is never sent.
     *
     * @return array{name: string, connection: string, queue: string, attempts: int, max_tries: int|null, id: string|int|null, uuid: string|null}|null
     */
    public function context(): ?array
    {
        if ($this->job === null) {
            return null;
        }

        $job = $this->job;

        return [
            'name' => $this->attempt(fn (): string => (string) $job->resolveName(), 'Unknown job'),
            'connection' => $this->attempt(fn (): string => (string) $job->getConnectionName(), ''),
            'queue' => $this->attempt(fn (): string => (string) $job->getQueue(), ''),
            'attempts' => $this->attempt(fn (): int => (int) $job->attempts(), 0),
            'max_tries' => $this->attempt(fn (): ?int => $job->maxTries() === null ? null : (int) $job->maxTries(), null),
            'id' => $this->attempt(fn () => $job->getJobId(), null),
            'uuid' => $this->attempt(fn (): ?string => $job->uuid(), null),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @param  T  $default
     * @return T
     */
    private function attempt(callable $read, mixed $default): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return $default;
        }
    }
}
