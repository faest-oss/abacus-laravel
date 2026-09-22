<?php

declare(strict_types=1);

namespace Faest\Abacus\Ledgers;

use Faest\Abacus\Contracts\HasMoneyAmount;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\OperationPolicy;
use Faest\Abacus\Contracts\OpposablePayload;
use Faest\Abacus\Data\OperationValidationContext;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

abstract class MoneyBalanceLedger implements Ledger, OperationPolicy
{
    /** @return array{balance_minor: int} */
    final public function initializeAggregate(): array
    {
        return ['balance_minor' => 0];
    }

    /** @param array{balance_minor: int}|JsonSerializable $existingAggregate */
    final public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array
    {
        $payload = $this->moneyPayload($payload);
        $balance = $this->balanceMinor($existingAggregate);

        return ['balance_minor' => $balance + $payload->amount()];
    }

    final public function assertValidPayload(LedgerPayload $payload): void
    {
        $this->moneyPayload($payload);
    }

    final public function assertAggregateInvariants(array|JsonSerializable $aggregate): void
    {
        $this->assertBalanceAllowed($this->balanceMinor($aggregate), null);
    }

    final public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        return $this->moneyPayload($payload)->opposing();
    }

    final public function assertOperationAllowed(OperationValidationContext $context): void
    {
        $balance = 0;
        $date = null;

        foreach ($context->accountingEntries as $entry) {
            if ($date !== null && ! $entry->accountingDate->equalTo($date)) {
                if ($date->greaterThanOrEqualTo($context->postingContext->accountingDate)) {
                    $this->assertBalanceAllowed($balance, $context);
                }
            }

            $date = $entry->accountingDate;
            $balance += $this->moneyPayload($entry->payload)->amount();
        }

        if ($date !== null && $date->greaterThanOrEqualTo($context->postingContext->accountingDate)) {
            $this->assertBalanceAllowed($balance, $context);
        }
    }

    abstract protected function acceptsMoneyPayload(LedgerPayload $payload): bool;

    abstract protected function currency(): string;

    protected function allowsNegativeBalance(?OperationValidationContext $context = null): bool
    {
        return true;
    }

    protected function negativeBalanceException(OperationValidationContext $context): Throwable
    {
        return new InvalidArgumentException("Ledger stream {$context->ledgerType}:{$context->ledgerId} cannot have a negative balance.");
    }

    private function assertBalanceAllowed(int $balanceMinor, ?OperationValidationContext $context): void
    {
        if ($balanceMinor >= 0 || $this->allowsNegativeBalance($context)) {
            return;
        }

        if ($context === null) {
            throw new InvalidArgumentException('Money ledger balances cannot be negative.');
        }

        throw $this->negativeBalanceException($context);
    }

    private function moneyPayload(LedgerPayload $payload): LedgerPayload&HasMoneyAmount&OpposablePayload
    {
        if (! $payload instanceof HasMoneyAmount
            || ! $payload instanceof OpposablePayload
            || ! $this->acceptsMoneyPayload($payload)) {
            throw new InvalidArgumentException('Unsupported money ledger payload.');
        }

        if ($payload->currency() !== $this->currency()) {
            throw new InvalidArgumentException("Money ledger payloads must use {$this->currency()}.");
        }

        return $payload;
    }

    /** @param array<mixed>|JsonSerializable $aggregate */
    private function balanceMinor(array|JsonSerializable $aggregate): int
    {
        if (! is_array($aggregate) || ! is_int($aggregate['balance_minor'] ?? null)) {
            throw new InvalidArgumentException('Money ledger aggregates must contain an integer balance_minor value.');
        }

        return $aggregate['balance_minor'];
    }
}
