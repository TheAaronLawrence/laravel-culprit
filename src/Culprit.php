<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Foundation\Configuration\Exceptions;
use Throwable;

/**
 * The package's entry point: call handles() from bootstrap/app.php.
 */
final class Culprit
{
    public const DEFAULT_URL = 'http://useculprit.test/api/errors';

    /**
     * Report every exception to Culprit. The callback returns nothing, so
     * Laravel still logs the exception as usual.
     */
    public function handles(Exceptions $exceptions): void
    {
        $exceptions->report(function (Throwable $e): void {
            app(ErrorReporter::class)->report($e);
        });
    }
}
