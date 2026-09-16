<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final readonly class PostingContext
{
    /**
     * @param  array<mixed>  $metadata
     */
    public function __construct(
        public string $actor,
        public CarbonImmutable $eventDate,
        public CarbonImmutable $accountingDate,
        public string $reason,
        public ?string $correlationId = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {
        //
    }

    /**
     * Context for an authenticated interactive user.
     */
    public static function forUser(
        string|int|null $userId = null,
        ?CarbonInterface $eventDate = null,
        ?CarbonInterface $accountingDate = null,
        string $reason = '',
    ): self {
        $resolvedUser = $userId ?? Auth::id();

        if (! $resolvedUser) {
            throw new InvalidArgumentException('A valid user ID or active authentication session is required.');
        }

        $now = CarbonImmutable::now();

        return new self(
            actor: "user:{$resolvedUser}",
            eventDate: $eventDate ? CarbonImmutable::instance($eventDate) : $now,
            accountingDate: $accountingDate ? CarbonImmutable::instance($accountingDate) : $now,
            reason: $reason,
            correlationId: null,
        );
    }

    /**
     * Context for automated processes, scheduled jobs, or CLI commands.
     */
    public static function forProcess(
        string $processName,
        CarbonInterface $eventDate,
        CarbonInterface $accountingDate,
        string $reason,
        ?string $idempotencyKey = null,
    ): self {
        return new self(
            actor: "system:{$processName}",
            eventDate: CarbonImmutable::instance($eventDate),
            accountingDate: CarbonImmutable::instance($accountingDate),
            reason: $reason,
            correlationId: null,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Context for external data imports or integrations.
     */
    public static function forImport(
        string $sourceName,
        string $externalBatchId,
        CarbonInterface $eventDate,
        CarbonInterface $accountingDate,
        string $reason = '',
    ): self {
        return new self(
            actor: "import:{$sourceName}",
            eventDate: CarbonImmutable::instance($eventDate),
            accountingDate: CarbonImmutable::instance($accountingDate),
            reason: $reason,
            correlationId: null,
            idempotencyKey: "import:{$sourceName}:{$externalBatchId}",
        );
    }

    public function withReason(string $reason): self
    {
        return new self(
            actor: $this->actor,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $reason,
            correlationId: $this->correlationId,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
        );
    }

    public function withCorrelationId(?string $correlationId): self
    {
        return new self(
            actor: $this->actor,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            correlationId: $correlationId,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
        );
    }

    public function withIdempotencyKey(?string $idempotencyKey): self
    {
        return new self(
            actor: $this->actor,
            eventDate: $this->eventDate,
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            correlationId: $this->correlationId,
            idempotencyKey: $idempotencyKey,
            metadata: $this->metadata,
        );
    }

    public function withEventDate(CarbonInterface $eventDate): self
    {
        return new self(
            actor: $this->actor,
            eventDate: $eventDate->toImmutable(),
            accountingDate: $this->accountingDate,
            reason: $this->reason,
            correlationId: $this->correlationId,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
        );
    }
}
