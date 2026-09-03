<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonInterface;
use Faest\Abacus\Contracts\LedgerPayload;

readonly class TransactionDraft
{
    public function __construct(
        public LedgerPayload $payload,
        public string $ledgerId,
        public CarbonInterface $eventDate,
        public CarbonInterface $accountingDate,
        public string $reason,
        public string $userId,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function make(string $ledgerId, LedgerPayload $payload): self
    {
        return new self(
            payload: $payload,
            ledgerId: $ledgerId,
            eventDate: now(),
            accountingDate: now(),
            reason: '',
            userId: '',
        );
    }

    public function occurredAt(CarbonInterface $eventDate): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function bookedFor(CarbonInterface $accountingDate): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function authoredBy(string $userId): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $userId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function withReason(string $reason): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $reason,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function correlateUsing(?string $correlationId): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function idempotentUsing(string $idempotencyKey): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            idempotencyKey: $idempotencyKey,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function failIfVersionIsnt(?int $expectedVersion): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            expectedVersion: $expectedVersion,
        );
    }
}
