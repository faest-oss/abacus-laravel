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
        public string $actor,
        public string $ledgerType,
        public TransactionDraft $source,
        public PostingContext $sourceContext,
        public ?int $version = null,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public ?string $reversesId = null,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function make(TransactionDraft $draft, PostingContext $context): self
    {
        return new self(
            payload: $draft->payload,
            ledgerId: $draft->ledgerId,
            eventDate: $context->eventDate,
            accountingDate: $context->accountingDate,
            systemDate: now(),
            reason: $context->reason,
            actor: $context->actor,
            ledgerType: $draft->ledgerType,
            idempotencyKey: $context->idempotencyKey,
            correlationId: $context->correlationId,
            expectedVersion: $draft->expectedVersion,
            reversesId: $draft->reversesId,
            source: $draft,
            sourceContext: $context,
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
            actor: $this->actor,
            version: $this->version,
            ledgerType: $this->ledgerType,
            idempotencyKey: $this->idempotencyKey,
            expectedVersion: $this->expectedVersion,
            reversesId: $this->reversesId,
            correlationId: $correlationId,
            source: $this->source,
            sourceContext: $this->sourceContext,
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
            actor: $this->actor,
            version: $this->version,
            idempotencyKey: $this->idempotencyKey,
            ledgerType: $this->ledgerType,
            expectedVersion: $expectedVersion,
            reversesId: $this->reversesId,
            correlationId: $this->correlationId,
            source: $this->source,
            sourceContext: $this->sourceContext,
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
            actor: $this->actor,
            idempotencyKey: $this->idempotencyKey,
            ledgerType: $this->ledgerType,
            expectedVersion: $this->expectedVersion,
            version: $version,
            reversesId: $this->reversesId,
            correlationId: $this->correlationId,
            source: $this->source,
            sourceContext: $this->sourceContext,
        );
    }

    public function reverses(?string $reversesId): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            systemDate: $this->systemDate,
            reason: $this->reason,
            actor: $this->actor,
            idempotencyKey: $this->idempotencyKey,
            ledgerType: $this->ledgerType,
            expectedVersion: $this->expectedVersion,
            version: $this->version,
            reversesId: $reversesId,
            correlationId: $this->correlationId,
            source: $this->source,
            sourceContext: $this->sourceContext,
        );
    }
}
