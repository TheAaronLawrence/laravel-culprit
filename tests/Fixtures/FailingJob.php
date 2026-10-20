<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Fails on purpose, so job context can be checked.
 */
final class FailingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $warehouseId = 7) {}

    public function handle(): void
    {
        throw new RuntimeException("Inventory sync failed for warehouse {$this->warehouseId}");
    }
}
