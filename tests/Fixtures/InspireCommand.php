<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;

/**
 * Succeeds quietly, so a finished command can be told apart from a failing one.
 */
final class InspireCommand extends Command
{
    protected $signature = 'inspire';

    protected $description = 'Display an inspiring quote';

    public function handle(): int
    {
        $this->comment(Inspiring::quote());

        return self::SUCCESS;
    }
}
