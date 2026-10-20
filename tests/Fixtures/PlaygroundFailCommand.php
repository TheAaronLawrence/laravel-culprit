<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Fixtures;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fails on purpose, so console context can be checked.
 */
final class PlaygroundFailCommand extends Command
{
    protected $signature = 'playground:fail
        {--warehouse=7 : Warehouse to pretend to sync}
        {--password= : A secret, to check that it is redacted}';

    protected $description = 'Throw an exception, to test console error reporting';

    public function handle(): int
    {
        $warehouse = $this->option('warehouse');

        throw new RuntimeException('Daily report failed for warehouse '.(is_string($warehouse) ? $warehouse : '7'));
    }
}
