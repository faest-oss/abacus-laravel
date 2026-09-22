<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use Faest\Abacus\Contracts\HasPayloadTypes;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\OperationValidationContext;
use Faest\Abacus\Ledgers\MoneyBalanceLedger;

class MoneyLedger extends MoneyBalanceLedger implements HasPayloadTypes
{
    public function __construct(private readonly bool $allowNegative = true) {}

    public function getLedgerType(): string
    {
        return 'money-account';
    }

    public function payloadTypes(): array
    {
        return ['test:money' => MoneyPayload::class];
    }

    protected function acceptsMoneyPayload(LedgerPayload $payload): bool
    {
        return $payload instanceof MoneyPayload;
    }

    protected function currency(): string
    {
        return 'USD';
    }

    protected function allowsNegativeBalance(?OperationValidationContext $context = null): bool
    {
        return $this->allowNegative;
    }
}
