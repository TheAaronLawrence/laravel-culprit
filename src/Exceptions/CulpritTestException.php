<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Exceptions;

use RuntimeException;

/**
 * The exception that `php artisan culprit:test` sends.
 */
final class CulpritTestException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This is a test error from Culprit.');
    }
}
