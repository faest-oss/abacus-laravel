<?php

declare(strict_types=1);

namespace Faest\Abacus\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Faest\Abacus\Abacus
 */
class Abacus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Faest\Abacus\Abacus::class;
    }
}
