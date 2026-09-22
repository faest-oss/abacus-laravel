<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\OperationAction;

final class OperationBuilder
{
    /** @var list<OperationAction> */
    private array $actions = [];

    public function post(
        string $ledgerType,
        string $ledgerId,
        LedgerPayload $payload,
        ?int $expectedVersion = null,
    ): self {
        $this->actions[] = OperationAction::post($ledgerType, $ledgerId, $payload, $expectedVersion);

        return $this;
    }

    /** @param list<LedgerPayload> $payloads */
    public function postMany(
        string $ledgerType,
        string $ledgerId,
        array $payloads,
        ?int $expectedVersion = null,
    ): self {
        foreach ($payloads as $payload) {
            $this->post($ledgerType, $ledgerId, $payload, $expectedVersion);
        }

        return $this;
    }

    public function reverse(string $transactionId, ?int $expectedVersion = null): self
    {
        $this->actions[] = OperationAction::reverse($transactionId, $expectedVersion);

        return $this;
    }

    public function replace(
        string $transactionId,
        LedgerPayload $replacement,
        ?int $expectedVersion = null,
    ): self {
        $this->actions[] = OperationAction::replace($transactionId, $replacement, $expectedVersion);

        return $this;
    }

    public function adjust(
        string $transactionId,
        LedgerPayload $delta,
        ?int $expectedVersion = null,
    ): self {
        $this->actions[] = OperationAction::adjust($transactionId, $delta, $expectedVersion);

        return $this;
    }

    public function transfer(
        string $ledgerType,
        string $sourceLedgerId,
        string $destinationLedgerId,
        LedgerPayload $payload,
        ?int $expectedSourceVersion = null,
        ?int $expectedDestinationVersion = null,
    ): self {
        $this->actions[] = OperationAction::transfer(
            $ledgerType,
            $sourceLedgerId,
            $destinationLedgerId,
            $payload,
            $expectedSourceVersion,
            $expectedDestinationVersion,
        );

        return $this;
    }

    public function transferBetween(
        string $sourceLedgerType,
        string $sourceLedgerId,
        string $destinationLedgerType,
        string $destinationLedgerId,
        LedgerPayload $payload,
        ?int $expectedSourceVersion = null,
        ?int $expectedDestinationVersion = null,
    ): self {
        $this->actions[] = OperationAction::transferBetween(
            $sourceLedgerType,
            $sourceLedgerId,
            $destinationLedgerType,
            $destinationLedgerId,
            $payload,
            $expectedSourceVersion,
            $expectedDestinationVersion,
        );

        return $this;
    }

    /** @return list<OperationAction> */
    public function actions(): array
    {
        return $this->actions;
    }
}
