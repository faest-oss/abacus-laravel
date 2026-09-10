<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Faest\Abacus\Contracts\Ledger;

final class Abacus
{
    /** @var array<string, Ledger> $ledgerRegistry */
    private $ledgerRegistry = [];


    public function __construct()
    {
        //
    }

    public function registerLedger(Ledger $ledger): self
    {
        $this->ledgerRegistry[$ledger->getLedgerType()] = $ledger;
        return $this;
    }
}
