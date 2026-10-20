<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Facades;

use Illuminate\Support\Facades\Facade;
use TheAaronLawrence\Culprit\Culprit as CulpritService;

/**
 * @method static void handles(\Illuminate\Foundation\Configuration\Exceptions $exceptions)
 *
 * @see CulpritService
 */
final class Culprit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CulpritService::class;
    }
}
