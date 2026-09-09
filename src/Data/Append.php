<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonInterface;
use Exception;
use Faest\Abacus\Contracts\LedgerPayload;
use Illuminate\Support\Facades\Auth;

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
        public ?int $version = null,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public ?int $reversesId = null,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function makeFromTransactionDraft(TransactionDraft $draft): self
    {

        $userId = $draft->userId ?? Auth::id();
        if (! $userId) {
            throw new Exception('Ledgers updates must be made by authenticated actors');
        }

        return new self(
            payload: $draft->payload,
            ledgerId: $draft->ledgerId,
            eventDate: $draft->eventDate,
            accountingDate: $draft->accountingDate,
            systemDate: now(),
            reason: $draft->reason,
            userId: (string) $userId,
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
            version: $this->version,
            ledgerType: $this->ledgerType,
            idempotencyKey: $this->idempotencyKey,
            expectedVersion: $this->expectedVersion,
            reversesId: $this->reversesId,
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
            version: $this->version,
            idempotencyKey: $this->idempotencyKey,
            ledgerType: $this->ledgerType,
            expectedVersion: $expectedVersion,
            reversesId: $this->reversesId,
            correlationId: $this->correlationId,
        );
    }

    public function withVersion(?int $version): self
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
            expectedVersion: $this->expectedVersion,
            version: $version,
            reversesId: $this->reversesId,
            correlationId: $this->correlationId,
        );
    }
}
