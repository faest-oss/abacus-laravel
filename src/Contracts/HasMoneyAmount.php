<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

interface HasMoneyAmount
{
    /**
     * Amount in integer minor units
     */
    public function amount(): int;

    /**
     * Iso4217 currency code.
     */
    public function currency(): string;
}
