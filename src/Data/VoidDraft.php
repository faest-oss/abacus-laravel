<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonInterface;

readonly class VoidDraft
{
    public function __construct(
        public string $transactionId,
        public string $reason,
        public string $userId,
        public CarbonInterface $eventDate,
        public CarbonInterface $accountingDate,
        public ?string $correlationId = null,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function make(string $transactionId): self
    {
        return new self(
            transactionId: $transactionId,
            eventDate: now(),
            accountingDate: now(),
            reason: '',
            userId: '',
        );
    }

    public function occurredAt(CarbonInterface $eventDate): self
    {
        return new self(
            transactionId: $this->transactionId,
            eventDate: $eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function bookedFor(CarbonInterface $accountingDate): self
    {
        return new self(
            transactionId: $this->transactionId,
            eventDate: $this->eventDate,
            accountingDate: $accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function authoredBy(string $userId): self
    {
        return new self(
            transactionId: $this->transactionId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $userId,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function withReason(string $reason): self
    {
        return new self(
            transactionId: $this->transactionId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $reason,
            userId: $this->userId,
            correlationId: $this->correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function correlateUsing(string $correlationId): self
    {
        return new self(
            transactionId: $this->transactionId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            correlationId: $correlationId,
            expectedVersion: $this->expectedVersion,
        );
    }

    public function failIfVersionMismatch(int $expectedVersion): self
    {
        return new self(
            transactionId: $this->transactionId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            userId: $this->userId,
            correlationId: $this->correlationId,
            expectedVersion: $expectedVersion,
        );
    }
}
