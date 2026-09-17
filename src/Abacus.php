<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Carbon\CarbonImmutable;
use Closure;
use Faest\Abacus\Contracts\CorrectionPolicy;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\Append;
use Faest\Abacus\Data\CorrectionContext;
use Faest\Abacus\Data\LedgerReplacementResult;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\OperationAction;
use Faest\Abacus\Data\OperationResult;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Exceptions\EmptyOperationException;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\IdempotencyConflictException;
use Faest\Abacus\Exceptions\InvalidCorrectionException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\Support\PayloadFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Throwable;

final class Abacus
{
    private const int FINGERPRINT_VERSION = 1;

    /** @var array<string, Ledger> */
    private array $ledgerRegistry = [];

    private ?string $connectionOverride = null;

    private ?int $lockTimeout = null;

    public function __construct(
        private PayloadRegistry $payloadRegistry,
    ) {
        //
    }

    public function registerLedger(Ledger $ledger): self
    {
        $this->ledgerRegistry[$ledger->getLedgerType()] = $ledger;
        $this->ledgerRegistry[$ledger::class] = $ledger;

        return $this;
    }

    public function resolveLedger(string $ledgerType): Ledger
    {
        if (isset($this->ledgerRegistry[$ledgerType])) {
            return $this->ledgerRegistry[$ledgerType];
        }

        if (class_exists($ledgerType) && is_subclass_of($ledgerType, Ledger::class)) {
            /** @var Ledger $instance */
            $instance = app($ledgerType);
            $this->registerLedger($instance);

            return $instance;
        }

        throw new InvalidArgumentException("Unregistered or invalid ledger type: {$ledgerType}");
    }

    public function registerPayload(string $type, string $payloadClass): self
    {
        $this->payloadRegistry->register($type, $payloadClass);
        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function deserialize(string $type, array $data): LedgerPayload
    {
        return $this->payloadRegistry->deserialize($type, $data);
    }

    public function findTransaction(string $transactionId): ?LedgerTransaction
    {
        return $this->ledgerTranQuery()->find($transactionId);
    }

    public function findOperation(string $operationId): ?OperationResult
    {
        $operation = $this->operationQuery()->find($operationId);

        return $operation ? $this->toOperationResult($operation) : null;
    }

    /** @return Builder<LedgerOperation> */
    public function operationsForStream(string $ledgerType, string $ledgerId): Builder
    {
        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();

        return $this->operationQuery()
            ->whereHas('transactions', fn(Builder $query) => $query
                ->where('ledger_type', $canonicalType)
                ->where('ledger_id', $ledgerId))
            ->with('transactions')
            ->orderBy(
                $this->ledgerTranQuery()
                    ->selectRaw('MAX(stream_version)')
                    ->whereColumn('operation_id', 'ledger_operation.id')
                    ->where('ledger_type', $canonicalType)
                    ->where('ledger_id', $ledgerId),
            );
    }

    /** @return array<mixed>|JsonSerializable */
    public function getAggregate(string $ledgerType, string $ledgerId): array|JsonSerializable
    {
        return $this->rebuildAggregate($ledgerType, $ledgerId);
    }

    /** @return array<mixed>|JsonSerializable */
    public function getAggregateAtOperation(
        string $ledgerType,
        string $ledgerId,
        string $operationId,
    ): array|JsonSerializable {
        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();
        $endingVersion = $this->ledgerTranQuery()
            ->where('operation_id', $operationId)
            ->where('ledger_type', $canonicalType)
            ->where('ledger_id', $ledgerId)
            ->max('stream_version');

        if ($endingVersion === null) {
            throw new InvalidArgumentException("Operation {$operationId} does not contain stream {$canonicalType}:{$ledgerId}.");
        }

        return $this->rebuildAggregate($canonicalType, $ledgerId, (int) $endingVersion);
    }

    public function post(
        string $ledgerType,
        string $ledgerId,
        LedgerPayload $payload,
        ?PostingContext $context = null,
        ?int $expectedVersion = null,
    ): Transaction {
        return $this->executeOperation(
            [OperationAction::post($ledgerType, $ledgerId, $payload, $expectedVersion)],
            $context ?? PostingContext::forUser(),
        )->transactions[0];
    }

    /**
     * @param  list<LedgerPayload>  $payloads
     * @return list<Transaction>
     */
    public function postMany(
        string $ledgerType,
        string $ledgerId,
        array $payloads,
        ?PostingContext $context = null,
        ?int $expectedVersion = null,
    ): array {
        $actions = array_map(
            fn(LedgerPayload $payload): OperationAction => OperationAction::post(
                $ledgerType,
                $ledgerId,
                $payload,
                $expectedVersion,
            ),
            $payloads,
        );

        return $this->executeOperation($actions, $context ?? PostingContext::forUser())->transactions;
    }

    /**
     * @param  PostingContext|Closure(OperationBuilder): void  $contextOrCallback
     * @param  (Closure(OperationBuilder): void)|null  $callback
     */
    public function operation(
        PostingContext|Closure $contextOrCallback,
        ?Closure $callback = null,
    ): OperationResult {
        if ($contextOrCallback instanceof Closure) {
            $callback = $contextOrCallback;
            $context = PostingContext::forUser();
        } else {
            $context = $contextOrCallback;
        }

        if ($callback === null) {
            throw new InvalidArgumentException('An operation callback must be provided.');
        }

        $builder = new OperationBuilder();
        $callback($builder);

        return $this->executeOperation($builder->actions(), $context);
    }

    public function reverse(
        string $transactionId,
        ?PostingContext $context = null,
        ?int $expectedVersion = null,
    ): Transaction {
        return $this->executeOperation(
            [OperationAction::reverse($transactionId, $expectedVersion)],
            $context ?? PostingContext::forUser(),
        )->transactions[0];
    }

    public function replace(
        string $transactionId,
        LedgerPayload $replacement,
        ?PostingContext $context = null,
        ?int $expectedVersion = null,
    ): LedgerReplacementResult {
        $result = $this->executeOperation(
            [OperationAction::replace($transactionId, $replacement, $expectedVersion)],
            $context ?? PostingContext::forUser(),
        );

        return new LedgerReplacementResult($result->id, $result->transactions[0], $result->transactions[1]);
    }

    public function adjust(
        string $transactionId,
        LedgerPayload $delta,
        ?PostingContext $context = null,
        ?int $expectedVersion = null,
    ): Transaction {
        return $this->executeOperation(
            [OperationAction::adjust($transactionId, $delta, $expectedVersion)],
            $context ?? PostingContext::forUser(),
        )->transactions[0];
    }

    /** @return list<Transaction> */
    public function reverseOperation(string $operationId, ?PostingContext $context = null): array
    {
        $operation = $this->operationQuery()->find($operationId);

        if (! $operation) {
            throw new InvalidArgumentException("Ledger operation {$operationId} was not found.");
        }

        $transactions = $this->ledgerTranQuery()
            ->where('operation_id', $operationId)
            ->orderBy('operation_position')
            ->get();

        if ($transactions->isEmpty()) {
            throw new InvalidArgumentException("Ledger operation {$operationId} has no entries.");
        }

        foreach ($transactions as $transaction) {
            if ($transaction->reverses_transaction_id !== null) {
                throw new InvalidCorrectionException(
                    'operation_contains_reversal',
                    $transaction->id,
                    'An operation containing a reversal entry cannot be reversed.',
                );
            }
        }

        $actions = [];

        foreach ($transactions as $transaction) {
            $actions[] = OperationAction::reverse($transaction->id);
        }

        return $this->executeOperation(
            $actions,
            $context ?? PostingContext::forUser(),
            OperationKind::OperationReversal,
            $operationId,
        )->transactions;
    }

    public function transfer(
        LedgerPayload $payload,
        string $sourceLedgerId,
        string $destinationLedgerId,
        string $ledgerType,
        ?PostingContext $context = null,
        ?int $expectedSourceVersion = null,
        ?int $expectedDestinationVersion = null,
    ): LedgerTransferResult {
        $result = $this->executeOperation(
            [OperationAction::transfer(
                $ledgerType,
                $sourceLedgerId,
                $destinationLedgerId,
                $payload,
                $expectedSourceVersion,
                $expectedDestinationVersion,
            )],
            $context ?? PostingContext::forUser(),
        );
        $operation = $this->operationQuery()->findOrFail($result->id);

        return new LedgerTransferResult(
            $result->id,
            (string) $operation->correlation_id,
            $result->transactions[0],
            $result->transactions[1],
        );
    }

    public function streamVersion(string $ledgerType, string $ledgerId): int
    {
        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();
        $head = $this->conn()->table('ledger_stream_head')->where([
            'ledger_type' => $canonicalType,
            'ledger_id' => $ledgerId,
        ])->first();

        return $head ? (int) $head->version : 0;
    }

    public function overrideConnection(string $connection): void
    {
        $this->connectionOverride = $connection;
    }

    public function overrideLockTimeout(?int $timeout): void
    {
        $this->lockTimeout = $timeout;
    }

    /** @return Builder<LedgerTransaction> */
    public function ledgerTranQuery(): Builder
    {
        return LedgerTransaction::on($this->connectionOverride);
    }

    /** @return Builder<LedgerOperation> */
    public function operationQuery(): Builder
    {
        return LedgerOperation::on($this->connectionOverride);
    }

    /**
     * @param  list<OperationAction>  $actions
     */
    private function executeOperation(
        array $actions,
        PostingContext $context,
        ?OperationKind $forcedKind = null,
        ?string $reversesOperationId = null,
    ): OperationResult {
        if ($actions === []) {
            throw new EmptyOperationException();
        }

        $actions = $this->normalizeActions($actions);
        $kind = $forcedKind ?? $this->deriveOperationKind($actions);
        $fingerprint = $this->fingerprint($actions, $kind, $context, $reversesOperationId);

        if ($context->correlationId === null && $this->containsTransfer($actions)) {
            $context = $context->withCorrelationId((string) Str::orderedUuid());
        }

        $recordedAt = CarbonImmutable::now();

        return $this->conn()->transaction(function () use (
            $actions,
            $kind,
            $context,
            $fingerprint,
            $recordedAt,
            $reversesOperationId,
        ): OperationResult {
            [$operation, $replayed] = $this->claimOperation(
                $kind,
                $context,
                $fingerprint,
                $recordedAt,
                $reversesOperationId,
            );

            if ($replayed) {
                return $this->toOperationResult($operation);
            }

            $streams = $this->affectedStreams($actions);
            $this->prepareStreamHeads($streams);
            $heads = $this->lockStreamHeads($streams);
            [$appends, $corrections] = $this->expandActions($actions);
            $this->assertExpectedVersions($appends, $heads);
            $this->assertCorrectionRelationships($corrections);

            [$planned, $before, $after] = $this->planAppends($appends, $heads);
            $this->assertCorrectionPolicies($corrections, $context, $before, $after);
            $this->persistAppends($operation, $planned, $context, $recordedAt);

            return $this->toOperationResult($operation);
        });
    }

    /**
     * @param  list<OperationAction>  $actions
     * @return list<OperationAction>
     */
    private function normalizeActions(array $actions): array
    {
        $normalized = [];

        foreach ($actions as $action) {
            foreach ([$action->expectedVersion, $action->expectedDestinationVersion] as $expectedVersion) {
                if ($expectedVersion !== null && $expectedVersion < 0) {
                    throw new InvalidArgumentException('Expected version must not be negative.');
                }
            }

            if (in_array($action->kind, [OperationKind::Posting, OperationKind::Transfer], true)) {
                if ($action->ledgerType === null || $action->ledgerId === null || $action->payload === null) {
                    throw new LogicException('Posting and transfer actions require a stream and payload.');
                }

                $ledgerType = $this->resolveLedger($action->ledgerType)->getLedgerType();

                if ($action->kind === OperationKind::Transfer) {
                    if ($action->destinationLedgerId === null || $action->destinationLedgerId === $action->ledgerId) {
                        throw new InvalidArgumentException('A transfer requires two different ledger streams.');
                    }

                    $normalized[] = OperationAction::transfer(
                        $ledgerType,
                        $action->ledgerId,
                        $action->destinationLedgerId,
                        $action->payload,
                        $action->expectedVersion,
                        $action->expectedDestinationVersion,
                    );
                } else {
                    $normalized[] = OperationAction::post(
                        $ledgerType,
                        $action->ledgerId,
                        $action->payload,
                        $action->expectedVersion,
                    );
                }

                continue;
            }

            $normalized[] = $action;
        }

        return $normalized;
    }

    /** @param list<OperationAction> $actions */
    private function deriveOperationKind(array $actions): OperationKind
    {
        $onlyPostings = true;

        foreach ($actions as $action) {
            if ($action->kind !== OperationKind::Posting) {
                $onlyPostings = false;

                break;
            }
        }

        if ($onlyPostings) {
            return OperationKind::Posting;
        }

        return count($actions) === 1 ? $actions[0]->kind : OperationKind::Composite;
    }

    /** @param list<OperationAction> $actions */
    private function containsTransfer(array $actions): bool
    {
        foreach ($actions as $action) {
            if ($action->kind === OperationKind::Transfer) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<OperationAction>  $actions
     */
    private function fingerprint(
        array $actions,
        OperationKind $kind,
        PostingContext $context,
        ?string $reversesOperationId,
    ): string {
        $intent = [
            'version' => self::FINGERPRINT_VERSION,
            'kind' => $kind->value,
            'actor' => $context->actor,
            'event_date' => $context->eventDate->toIso8601String(),
            'accounting_date' => $context->accountingDate->toIso8601String(),
            'reason' => $context->reason,
            'metadata' => $context->metadata,
            'correlation_id' => $context->correlationId,
            'reverses_operation_id' => $reversesOperationId,
            'actions' => array_map(fn(OperationAction $action): array => [
                'kind' => $action->kind->value,
                'ledger_type' => $action->ledgerType,
                'ledger_id' => $action->ledgerId,
                'payload_type' => $action->payload?->payloadType(),
                'payload' => $action->payload?->jsonSerialize(),
                'target_transaction_id' => $action->targetTransactionId,
                'destination_ledger_id' => $action->destinationLedgerId,
            ], $actions),
        ];

        return hash('sha256', PayloadFingerprint::canonicalize($intent));
    }

    /** @return array{LedgerOperation, bool} */
    private function claimOperation(
        OperationKind $kind,
        PostingContext $context,
        string $fingerprint,
        CarbonImmutable $recordedAt,
        ?string $reversesOperationId,
    ): array {
        $operation = new LedgerOperation();
        $operation->setConnection($this->connectionOverride);
        $operation->kind = $kind;
        $operation->actor = $context->actor;
        $operation->reason = $context->reason;
        $operation->event_date = $context->eventDate;
        $operation->accounting_date = $context->accountingDate;
        $operation->system_date = $recordedAt;
        $operation->correlation_id = $context->correlationId;
        $operation->metadata = $context->metadata;
        $operation->idempotency_key = $context->idempotencyKey;
        $operation->request_fingerprint_version = self::FINGERPRINT_VERSION;
        $operation->request_fingerprint = $fingerprint;
        $operation->reverses_operation_id = $reversesOperationId;

        try {
            $this->conn()->transaction(fn() => $operation->save());

            return [$operation, false];
        } catch (QueryException $exception) {
            if ($context->idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->operationQuery()
                ->where('idempotency_key', $context->idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            if ($existing->request_fingerprint !== $fingerprint
                || $existing->request_fingerprint_version !== self::FINGERPRINT_VERSION) {
                throw new IdempotencyConflictException($context->idempotencyKey, $existing->id);
            }

            return [$existing, true];
        }
    }

    /**
     * @param  list<OperationAction>  $actions
     * @return array{list<Append>, list<array{kind: OperationKind, original: LedgerTransaction, payloads: list<LedgerPayload>}>}
     */
    private function expandActions(array $actions): array
    {
        $appends = [];
        $corrections = [];

        foreach ($actions as $action) {
            if ($action->kind === OperationKind::Posting) {
                $appends[] = new Append(
                    (string) $action->ledgerType,
                    (string) $action->ledgerId,
                    $action->payload ?? throw new LogicException('Posting payload is missing.'),
                    $action->expectedVersion,
                );

                continue;
            }

            if ($action->kind === OperationKind::Transfer) {
                $ledger = $this->resolveLedger((string) $action->ledgerType);
                $payload = $action->payload ?? throw new LogicException('Transfer payload is missing.');
                $appends[] = new Append(
                    $ledger->getLedgerType(),
                    (string) $action->ledgerId,
                    $payload,
                    $action->expectedVersion,
                );
                $appends[] = new Append(
                    $ledger->getLedgerType(),
                    (string) $action->destinationLedgerId,
                    $ledger->computeOpposing($payload),
                    $action->expectedDestinationVersion,
                );

                continue;
            }

            $targetId = $action->targetTransactionId
                ?? throw new LogicException('Correction target is missing.');
            $original = $this->findTransaction($targetId);

            if (! $original) {
                throw new InvalidCorrectionException(
                    'target_not_found',
                    $targetId,
                    "Correction target {$targetId} was not found.",
                );
            }

            $ledger = $this->resolveLedger($original->ledger_type);
            $originalPayload = $ledger->deserialize($original->payload_type, $original->payload);

            if ($action->kind === OperationKind::Reversal) {
                $opposing = $ledger->computeOpposing($originalPayload);
                $appends[] = new Append(
                    $original->ledger_type,
                    $original->ledger_id,
                    $opposing,
                    $action->expectedVersion,
                    reversesId: $original->id,
                );
                $corrections[] = ['kind' => $action->kind, 'original' => $original, 'payloads' => [$opposing]];

                continue;
            }

            if ($action->kind === OperationKind::Replacement) {
                $opposing = $ledger->computeOpposing($originalPayload);
                $replacement = $action->payload ?? throw new LogicException('Replacement payload is missing.');
                $appends[] = new Append(
                    $original->ledger_type,
                    $original->ledger_id,
                    $opposing,
                    $action->expectedVersion,
                    reversesId: $original->id,
                );
                $appends[] = new Append(
                    $original->ledger_type,
                    $original->ledger_id,
                    $replacement,
                    $action->expectedVersion,
                    replacesId: $original->id,
                );
                $corrections[] = [
                    'kind' => $action->kind,
                    'original' => $original,
                    'payloads' => [$opposing, $replacement],
                ];

                continue;
            }

            $delta = $action->payload ?? throw new LogicException('Adjustment payload is missing.');
            $appends[] = new Append(
                $original->ledger_type,
                $original->ledger_id,
                $delta,
                $action->expectedVersion,
                adjustsId: $original->id,
            );
            $corrections[] = ['kind' => $action->kind, 'original' => $original, 'payloads' => [$delta]];
        }

        return [$appends, $corrections];
    }

    /**
     * @param  list<array{kind: OperationKind, original: LedgerTransaction, payloads: list<LedgerPayload>}>  $corrections
     */
    private function assertCorrectionRelationships(array $corrections): void
    {
        $reversedTargets = [];

        foreach ($corrections as $correction) {
            $original = $correction['original']->fresh();

            if (! $original) {
                throw new InvalidCorrectionException(
                    'target_not_found',
                    $correction['original']->id,
                    'The correction target no longer exists.',
                );
            }

            if ($original->reverses_transaction_id !== null) {
                throw new InvalidCorrectionException(
                    'reversal_target',
                    $original->id,
                    'A reversal entry cannot be corrected.',
                );
            }

            if (! in_array($correction['kind'], [OperationKind::Reversal, OperationKind::Replacement], true)) {
                continue;
            }

            if (in_array($original->id, $reversedTargets, true)
                || $this->ledgerTranQuery()->where('reverses_transaction_id', $original->id)->exists()) {
                throw new InvalidCorrectionException(
                    'already_reversed',
                    $original->id,
                    'This transaction has already been reversed.',
                );
            }

            $reversedTargets[] = $original->id;
        }
    }

    /**
     * @param  list<OperationAction>  $actions
     * @return list<array{0: string, 1: string}>
     */
    private function affectedStreams(array $actions): array
    {
        $streams = [];

        foreach ($actions as $action) {
            if ($action->kind === OperationKind::Posting) {
                $streams[] = [(string) $action->ledgerType, (string) $action->ledgerId];

                continue;
            }

            if ($action->kind === OperationKind::Transfer) {
                $streams[] = [(string) $action->ledgerType, (string) $action->ledgerId];
                $streams[] = [(string) $action->ledgerType, (string) $action->destinationLedgerId];

                continue;
            }

            $targetId = $action->targetTransactionId
                ?? throw new LogicException('Correction target is missing.');
            $target = $this->findTransaction($targetId);

            if (! $target) {
                throw new InvalidCorrectionException(
                    'target_not_found',
                    $targetId,
                    "Correction target {$targetId} was not found.",
                );
            }

            $streams[] = [$target->ledger_type, $target->ledger_id];
        }

        usort($streams, fn(array $left, array $right): int => strcmp($left[0], $right[0]) ?: strcmp($left[1], $right[1]));

        return array_values(array_unique($streams, SORT_REGULAR));
    }

    /** @param list<array{0: string, 1: string}> $streams */
    private function prepareStreamHeads(array $streams): void
    {
        foreach ($streams as [$ledgerType, $ledgerId]) {
            $query = $this->conn()->table('ledger_stream_head')->where([
                'ledger_type' => $ledgerType,
                'ledger_id' => $ledgerId,
            ]);

            if ($query->exists()) {
                continue;
            }

            $this->conn()->table('ledger_stream_head')->insertOrIgnore([
                'ledger_type' => $ledgerType,
                'ledger_id' => $ledgerId,
            ]);
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $streams
     * @return array<string, array<string, int>>
     */
    private function lockStreamHeads(array $streams): array
    {
        $heads = [];

        if ($this->lockTimeout !== null && $this->lockTimeout > 0) {
            $this->conn()->statement("SET statement_timeout = {$this->lockTimeout}");
        }

        foreach ($streams as [$ledgerType, $ledgerId]) {
            $query = $this->conn()->table('ledger_stream_head')
                ->where('ledger_type', $ledgerType)
                ->where('ledger_id', $ledgerId);
            $head = $this->lockTimeout === 0
                ? $query->lock('for update nowait')->first()
                : $query->lockForUpdate()->first();

            if (! $head) {
                throw new LogicException("Stream head {$ledgerType}:{$ledgerId} was not prepared.");
            }

            $heads[$ledgerType][$ledgerId] = (int) $head->version;
        }

        return $heads;
    }

    /**
     * @param  list<Append>  $appends
     * @param  array<string, array<string, int>>  $heads
     */
    private function assertExpectedVersions(array $appends, array $heads): void
    {
        $expectations = [];

        foreach ($appends as $append) {
            if ($append->expectedVersion === null) {
                continue;
            }

            $existing = $expectations[$append->ledgerType][$append->ledgerId] ?? null;

            if ($existing !== null && $existing !== $append->expectedVersion) {
                throw new InvalidArgumentException('Expected versions for the same stream must agree.');
            }

            $expectations[$append->ledgerType][$append->ledgerId] = $append->expectedVersion;
        }

        foreach ($expectations as $ledgerType => $ledgerExpectations) {
            foreach ($ledgerExpectations as $ledgerId => $expectedVersion) {
                $actualVersion = $heads[$ledgerType][$ledgerId];

                if ($expectedVersion !== $actualVersion) {
                    throw new UnexpectedStreamVersionException(
                        'Stream expectation failed',
                        $ledgerType,
                        (string) $ledgerId,
                        $expectedVersion,
                        $actualVersion,
                    );
                }
            }
        }
    }

    /**
     * @param  list<Append>  $appends
     * @param  array<string, array<string, int>>  $heads
     * @return array{list<Append>, array<string, array<string, array<mixed>|JsonSerializable>>, array<string, array<string, array<mixed>|JsonSerializable>>}
     */
    private function planAppends(array $appends, array $heads): array
    {
        $planned = [];
        $before = [];
        $after = [];

        foreach ($appends as $position => $append) {
            $ledger = $this->resolveLedger($append->ledgerType);

            if (! isset($after[$append->ledgerType][$append->ledgerId])) {
                $aggregate = $this->rebuildAggregate($append->ledgerType, $append->ledgerId);
                $before[$append->ledgerType][$append->ledgerId] = $aggregate;
                $after[$append->ledgerType][$append->ledgerId] = $aggregate;
            }

            try {
                $ledger->assertValidPayload($append->payload);
                $after[$append->ledgerType][$append->ledgerId] = $ledger->applyToAggregate(
                    $append->payload,
                    $after[$append->ledgerType][$append->ledgerId],
                );
            } catch (Throwable $exception) {
                throw new FailedInvariantException(
                    $exception->getMessage(),
                    $append->ledgerType,
                    $append->ledgerId,
                    previous: $exception,
                );
            }

            $nextVersion = $heads[$append->ledgerType][$append->ledgerId] + 1;
            $heads[$append->ledgerType][$append->ledgerId] = $nextVersion;
            $planned[] = $append->planned($nextVersion, $position + 1);
        }

        foreach ($after as $ledgerType => $streamAggregates) {
            $ledger = $this->resolveLedger($ledgerType);

            foreach ($streamAggregates as $ledgerId => $aggregate) {
                try {
                    $ledger->assertAggregateInvariants($aggregate);
                } catch (Throwable $exception) {
                    throw new FailedInvariantException(
                        $exception->getMessage(),
                        $ledgerType,
                        $ledgerId,
                        previous: $exception,
                    );
                }
            }
        }

        return [$planned, $before, $after];
    }

    /**
     * @param  list<array{kind: OperationKind, original: LedgerTransaction, payloads: list<LedgerPayload>}>  $corrections
     * @param  array<string, array<string, array<mixed>|JsonSerializable>>  $before
     * @param  array<string, array<string, array<mixed>|JsonSerializable>>  $after
     */
    private function assertCorrectionPolicies(
        array $corrections,
        PostingContext $context,
        array $before,
        array $after,
    ): void {
        foreach ($corrections as $correction) {
            $original = $correction['original'];
            $ledger = $this->resolveLedger($original->ledger_type);

            if (! $ledger instanceof CorrectionPolicy) {
                continue;
            }

            $ledger->assertCorrectionAllowed(new CorrectionContext(
                $correction['kind'],
                $original,
                $correction['payloads'],
                $context,
                $before[$original->ledger_type][$original->ledger_id],
                $after[$original->ledger_type][$original->ledger_id],
            ));
        }
    }

    /** @param list<Append> $appends */
    private function persistAppends(
        LedgerOperation $operation,
        array $appends,
        PostingContext $context,
        CarbonImmutable $recordedAt,
    ): void {
        if ($this->conn()->transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a database transaction.');
        }

        foreach ($appends as $append) {
            $transaction = new LedgerTransaction();
            $transaction->setConnection($this->connectionOverride);
            $transaction->operation_id = $operation->id;
            $transaction->operation_position = $append->operationPosition;
            $transaction->actor = $context->actor;
            $transaction->system_date = $recordedAt;
            $transaction->reverses_transaction_id = $append->reversesId;
            $transaction->replaces_transaction_id = $append->replacesId;
            $transaction->adjusts_transaction_id = $append->adjustsId;
            $transaction->correlation_id = $context->correlationId;
            $transaction->payload_type = $append->payload->payloadType();
            $transaction->ledger_type = $append->ledgerType;
            $transaction->ledger_id = $append->ledgerId;
            $transaction->event_date = $context->eventDate;
            $transaction->accounting_date = $context->accountingDate;
            $transaction->payload = $append->payload->jsonSerialize();
            $transaction->reason = $context->reason;
            $transaction->stream_version = $append->version;
            $transaction->save();

            $this->conn()->table('ledger_stream_head')->where([
                'ledger_type' => $append->ledgerType,
                'ledger_id' => $append->ledgerId,
            ])->update(['version' => $append->version]);
        }
    }

    /** @return array<mixed>|JsonSerializable */
    private function rebuildAggregate(
        string $ledgerType,
        string $ledgerId,
        ?int $throughVersion = null,
    ): array|JsonSerializable {
        $ledger = $this->resolveLedger($ledgerType);
        $query = $this->ledgerTranQuery()
            ->where('ledger_type', $ledger->getLedgerType())
            ->where('ledger_id', $ledgerId)
            ->orderBy('stream_version');

        if ($throughVersion !== null) {
            $query->where('stream_version', '<=', $throughVersion);
        }

        /** @var Collection<int, LedgerTransaction> $history */
        $history = $query->get();
        $aggregate = $ledger->initializeAggregate();

        foreach ($history as $entry) {
            $aggregate = $ledger->applyToAggregate(
                $ledger->deserialize($entry->payload_type, $entry->payload),
                $aggregate,
            );
        }

        return $aggregate;
    }

    private function toTransaction(LedgerTransaction $transaction): Transaction
    {
        return new Transaction(
            $transaction->id,
            $transaction->stream_version,
            $transaction->operation_id,
            $transaction->operation_position,
        );
    }

    private function toOperationResult(LedgerOperation $operation): OperationResult
    {
        $models = $this->ledgerTranQuery()
            ->where('operation_id', $operation->id)
            ->orderBy('operation_position')
            ->get();
        $transactions = [];

        foreach ($models as $model) {
            $transactions[] = $this->toTransaction($model);
        }

        return new OperationResult($operation->id, $operation->kind, $transactions);
    }

    private function conn(): Connection
    {
        return DB::connection($this->connectionOverride);
    }
}
