<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonInterface;
use Faest\Abacus\Contracts\LedgerPayload;

final readonly class Append
{
    public function __construct(
        public LedgerPayload $payload,
        public string $ledgerId,
        public CarbonInterface $eventDate,
        public CarbonInterface $accountingDate,
        public CarbonInterface $systemDate,
        public string $reason,
        public string $userId,
        public string $ledgerType,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function makeFromTransactionDraft(TransactionDraft $draft): self
    {
        return new self(
            payload: $draft->payload,
            ledgerId: $draft->ledgerId,
            eventDate: $draft->eventDate,
            accountingDate: $draft->accountingDate,
            systemDate: now(),
            reason: $draft->reason,
            userId: $draft->userId,
            ledgerType: $draft->ledgerType,
            idempotencyKey: $draft->idempotencyKey,
            expectedVersion: $draft->expectedVersion,
        );
    }

    public function withCorrelatingId(?string $correlationId): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            systemDate: $this->systemDate,
            reason: $this->reason,
            userId: $this->userId,
            ledgerType: $this->ledgerType,
            idempotencyKey: $this->idempotencyKey,
            expectedVersion: $this->expectedVersion,
            correlationId: $correlationId,
        );
    }

    public function withExpectedVersion(?int $expectedVersion): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            systemDate: $this->systemDate,
            reason: $this->reason,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            ledgerType: $this->ledgerType,
            expectedVersion: $expectedVersion,
            correlationId: $this->correlationId,
        );
    }
}
