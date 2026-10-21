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
    public const DEFAULT_URL = 'https://useculprit.com/api/errors';

    /**
     * Sent with every report. An error raised while handling a request that
     * carries it is never reported, so a tracker can report its own errors
     * without looping when its ingest endpoint fails.
     */
    public const REPORT_HEADER = 'X-Culprit-Report';

    /**
     * Report every exception to Culprit. The callback returns nothing, so
     * Laravel still logs the exception as usual.
     */
    public function handles(Exceptions $exceptions): void
    {
        $exceptions->report(function (Throwable $e): void {
            try {
                app(ErrorReporter::class)->report($e);
            } catch (Throwable) {
                // Reporting must never break the host app.
            }
        });
    }
}
