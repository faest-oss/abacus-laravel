<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Carbon\CarbonImmutable;
use Closure;
use Faest\Abacus\Contracts\CorrectionPolicy;
use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\HasMoneyAmount;
use Faest\Abacus\Contracts\HasPayloadTypes;
use Faest\Abacus\Contracts\HasProjectors;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\OperationPolicy;
use Faest\Abacus\Contracts\OperationProjector;
use Faest\Abacus\Contracts\Projector;
use Faest\Abacus\Contracts\ReplayableProjector;
use Faest\Abacus\Contracts\SnapshotsAggregate;
use Faest\Abacus\Data\AccountingEntry;
use Faest\Abacus\Data\Append;
use Faest\Abacus\Data\CorrectionContext;
use Faest\Abacus\Data\LedgerReplacementResult;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\OperationAction;
use Faest\Abacus\Data\OperationResult;
use Faest\Abacus\Data\OperationValidationContext;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TemporalView;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionCriteria;
use Faest\Abacus\Enums\CorrectionRelationship;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Enums\ReversalStatus;
use Faest\Abacus\Exceptions\EmptyOperationException;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\IdempotencyConflictException;
use Faest\Abacus\Exceptions\InvalidCorrectionException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerSnapshot;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\Support\CanonicalJson;
use Faest\Abacus\Support\PayloadFingerprint;
use Faest\Abacus\Support\StorageConfiguration;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Throwable;
use UnexpectedValueException;

final class Abacus
{
    private const int FINGERPRINT_VERSION = 2;

    /** @var array<string, Ledger> */
    private array $ledgerRegistry = [];

    /** @var array<string, list<class-string<Projector>|Projector>> */
    private array $projectorRegistry = [];

    /** @var list<array{ledgerType: string, projector: class-string<OperationProjector>|OperationProjector}> */
    private array $operationProjectorRegistry = [];

    private ?string $connectionOverride = null;

    private ?int $lockTimeout = null;

    public function __construct(
        private PayloadRegistry $payloadRegistry,
        ?StorageConfiguration $storage = null,
    ) {
        $this->storage = $storage ?? app(StorageConfiguration::class);
    }

    private StorageConfiguration $storage;

    public function registerLedger(Ledger $ledger): self
    {
        $this->ledgerRegistry[$ledger->getLedgerType()] = $ledger;
        $this->ledgerRegistry[$ledger::class] = $ledger;

        if ($ledger instanceof HasPayloadTypes) {
            foreach ($ledger->payloadTypes() as $type => $payloadClass) {
                $this->registerPayload($type, $payloadClass);
            }
        }

        if ($ledger instanceof HasProjectors) {
            foreach ($ledger->projectors() as $projector) {
                $registered = false;

                if ($projector instanceof Projector
                    || (is_string($projector) && is_subclass_of($projector, Projector::class))) {
                    /** @var class-string<Projector>|Projector $projector */
                    $this->addProjector($ledger->getLedgerType(), $projector);
                    $registered = true;
                }

                if ($projector instanceof OperationProjector
                    || (is_string($projector) && is_subclass_of($projector, OperationProjector::class))) {
                    /** @var class-string<OperationProjector>|OperationProjector $projector */
                    $this->addOperationProjector($ledger->getLedgerType(), $projector);
                    $registered = true;
                }

                if (! $registered) {
                    throw new InvalidArgumentException('Ledger projectors must implement a required projector contract.');
                }
            }
        }

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

    /** @param class-string<DeserializablePayload> $payloadClass */
    public function registerPayload(string $type, string $payloadClass): self
    {
        $this->payloadRegistry->register($type, $payloadClass);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deserializePayload(string $type, array $data): LedgerPayload
    {
        return $this->payloadRegistry->deserialize($type, $data);
    }

    /** @param class-string<Projector>|Projector $projector */
    public function registerProjector(string $ledgerType, string|Projector $projector): self
    {
        if (is_string($projector) && ! is_subclass_of($projector, Projector::class)) {
            throw new InvalidArgumentException('Entry projectors must implement Projector.');
        }

        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();
        $this->addProjector($canonicalType, $projector);

        return $this;
    }

    /** @param class-string<OperationProjector>|OperationProjector $projector */
    public function registerOperationProjector(
        string $ledgerType,
        string|OperationProjector $projector,
    ): self {
        if (is_string($projector) && ! is_subclass_of($projector, OperationProjector::class)) {
            throw new InvalidArgumentException('Operation projectors must implement OperationProjector.');
        }

        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();
        $this->addOperationProjector($canonicalType, $projector);

        return $this;
    }

    /**
     * @param  class-string<ReplayableProjector>|ReplayableProjector  $projector
     */
    public function rebuildProjection(
        string|ReplayableProjector $projector,
        string $ledgerType,
        int $chunkSize = 1000,
    ): int {
        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('Projection chunk size must be greater than zero.');
        }

        $resolvedProjector = $this->resolveReplayableProjector($projector);
        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();
        $connection = $this->conn();

        return $connection->transaction(function () use (
            $resolvedProjector,
            $canonicalType,
            $connection,
            $chunkSize,
        ): int {
            $resolvedProjector->resetProjection($canonicalType, $connection);

            $processed = 0;
            $lastSystemDate = null;
            $lastOperationId = null;
            $lastOperationPosition = null;

            do {
                $query = $this->ledgerTranQuery()
                    ->with('operation')
                    ->where('ledger_type', $canonicalType);

                if ($lastSystemDate !== null && $lastOperationId !== null && $lastOperationPosition !== null) {
                    $query->where(function (Builder $cursor) use (
                        $lastSystemDate,
                        $lastOperationId,
                        $lastOperationPosition,
                    ): void {
                        $cursor->where('system_date', '>', $lastSystemDate)
                            ->orWhere(function (Builder $sameDate) use (
                                $lastSystemDate,
                                $lastOperationId,
                                $lastOperationPosition,
                            ): void {
                                $sameDate->where('system_date', $lastSystemDate)
                                    ->where(function (Builder $sameOperation) use (
                                        $lastOperationId,
                                        $lastOperationPosition,
                                    ): void {
                                        $sameOperation->where('operation_id', '>', $lastOperationId)
                                            ->orWhere(function (Builder $sameId) use (
                                                $lastOperationId,
                                                $lastOperationPosition,
                                            ): void {
                                                $sameId->where('operation_id', $lastOperationId)
                                                    ->where('operation_position', '>', $lastOperationPosition);
                                            });
                                    });
                            });
                    });
                }

                /** @var Collection<int, LedgerTransaction> $transactions */
                $transactions = $query
                    ->orderBy('system_date')
                    ->orderBy('operation_id')
                    ->orderBy('operation_position')
                    ->limit($chunkSize)
                    ->get();

                foreach ($transactions as $transaction) {
                    $resolvedProjector->projectHistorical(
                        $transaction,
                        $this->deserializePayload($transaction->payload_type, $transaction->payload),
                        $transaction->operation,
                        $connection,
                    );
                    $processed++;
                }

                $last = $transactions->last();

                if ($last !== null) {
                    $lastSystemDate = $last->system_date;
                    $lastOperationId = $last->operation_id;
                    $lastOperationPosition = $last->operation_position;
                }
            } while ($transactions->count() === $chunkSize);

            return $processed;
        });
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
        $operationTable = $this->storage->table('ledger_operation');

        return $this->operationQuery()
            ->whereHas('transactions', fn (Builder $query) => $query
                ->where('ledger_type', $canonicalType)
                ->where('ledger_id', $ledgerId))
            ->with('transactions')
            ->orderBy(
                $this->ledgerTranQuery()
                    ->selectRaw('MAX(stream_version)')
                    ->whereColumn('operation_id', "{$operationTable}.id")
                    ->where('ledger_type', $canonicalType)
                    ->where('ledger_id', $ledgerId),
            );
    }

    /** @return Builder<LedgerTransaction> */
    public function transactionsForStream(
        string $ledgerType,
        string $ledgerId,
        ?TransactionCriteria $criteria = null,
    ): Builder {
        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();
        $transactionTable = $this->storage->table('ledger_transaction');
        $criteria ??= new TransactionCriteria;
        $query = $this->ledgerTranQuery()
            ->where('ledger_type', $canonicalType)
            ->where('ledger_id', $ledgerId);

        $this->applyTemporalView($query, $criteria->view);

        if ($criteria->operationId !== null) {
            $query->where('operation_id', $criteria->operationId);
        }

        if ($criteria->correlationId !== null) {
            $query->where('correlation_id', $criteria->correlationId);
        }

        if ($criteria->correction !== null) {
            $column = match ($criteria->correction->relationship) {
                CorrectionRelationship::Reversal => 'reverses_transaction_id',
                CorrectionRelationship::Replacement => 'replaces_transaction_id',
                CorrectionRelationship::Adjustment => 'adjusts_transaction_id',
            };

            $criteria->correction->targetTransactionId === null
                ? $query->whereNotNull($column)
                : $query->where($column, $criteria->correction->targetTransactionId);
        }

        if ($criteria->reversalStatus !== null) {
            $reversals = $this->conn()->table("{$transactionTable} as reversals")
                ->selectRaw('1')
                ->whereColumn('reversals.reverses_transaction_id', "{$transactionTable}.id")
                ->where('reversals.ledger_type', $canonicalType)
                ->where('reversals.ledger_id', $ledgerId);

            $this->applyTemporalView($reversals, $criteria->view, 'reversals');
            $query->whereNull("{$transactionTable}.reverses_transaction_id");

            $criteria->reversalStatus === ReversalStatus::Reversed
                ? $query->whereExists($reversals)
                : $query->whereNotExists($reversals);
        }

        return $query->orderBy('stream_version');
    }

    /** @return array<mixed>|JsonSerializable */
    public function getAggregate(
        string $ledgerType,
        string $ledgerId,
        ?TemporalView $view = null,
    ): array|JsonSerializable {
        $canonicalType = $this->resolveLedger($ledgerType)->getLedgerType();

        return $this->rebuildAggregate(
            $canonicalType,
            $ledgerId,
            $this->streamHeadVersion($canonicalType, $ledgerId),
            $view,
        );
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

    public function createAggregateSnapshot(string $ledgerType, string $ledgerId): int
    {
        $ledger = $this->resolveLedger($ledgerType);

        if (! $ledger instanceof SnapshotsAggregate) {
            throw new InvalidArgumentException('The selected ledger does not support aggregate snapshots.');
        }

        $snapshotVersion = $this->snapshotVersion($ledger);
        $canonicalType = $ledger->getLedgerType();

        return $this->conn()->transaction(function () use (
            $ledger,
            $canonicalType,
            $ledgerId,
            $snapshotVersion,
        ): int {
            if ($this->lockTimeout !== null && $this->lockTimeout > 0) {
                $this->conn()->statement("SET statement_timeout = {$this->lockTimeout}");
            }

            $headQuery = $this->conn()->table($this->storage->table('ledger_stream_head'))
                ->where('ledger_type', $canonicalType)
                ->where('ledger_id', $ledgerId);
            $head = $this->lockTimeout === 0
                ? $headQuery->lock('for update nowait')->first()
                : $headQuery->lockForUpdate()->first();

            if ($head === null || (int) $head->version === 0) {
                return 0;
            }

            $streamVersion = (int) $head->version;
            $existing = LedgerSnapshot::on($this->connectionName())
                ->where('ledger_type', $canonicalType)
                ->where('ledger_id', $ledgerId)
                ->where('stream_version', $streamVersion)
                ->where('snapshot_version', $snapshotVersion)
                ->exists();

            if ($existing) {
                return $streamVersion;
            }

            $aggregate = $this->rebuildAggregate($canonicalType, $ledgerId, $streamVersion);
            $serialized = CanonicalJson::normalizeArray($ledger->serializeAggregateSnapshot($aggregate));
            $history = $this->ledgerTranQuery()
                ->where('ledger_type', $canonicalType)
                ->where('ledger_id', $ledgerId)
                ->where('stream_version', '<=', $streamVersion);
            $boundary = (clone $history)
                ->where('stream_version', $streamVersion)
                ->firstOrFail();

            $snapshot = new LedgerSnapshot;
            $snapshot->setConnection($this->connectionName());
            $snapshot->ledger_type = $canonicalType;
            $snapshot->ledger_id = $ledgerId;
            $snapshot->stream_version = $streamVersion;
            $snapshot->operation_id = $boundary->operation_id;
            $snapshot->snapshot_version = $snapshotVersion;
            $snapshot->aggregate = $serialized;
            $snapshot->max_event_date = (clone $history)->max('event_date');
            $snapshot->max_accounting_date = (clone $history)->max('accounting_date');
            $snapshot->max_system_date = (clone $history)->max('system_date');
            $snapshot->created_at = CarbonImmutable::now();
            $snapshot->save();

            return $streamVersion;
        });
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
            fn (LedgerPayload $payload): OperationAction => OperationAction::post(
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

        $builder = new OperationBuilder;
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
        return $this->transferBetween(
            $payload,
            $ledgerType,
            $sourceLedgerId,
            $ledgerType,
            $destinationLedgerId,
            $context,
            $expectedSourceVersion,
            $expectedDestinationVersion,
        );
    }

    public function transferBetween(
        LedgerPayload $payload,
        string $sourceLedgerType,
        string $sourceLedgerId,
        string $destinationLedgerType,
        string $destinationLedgerId,
        ?PostingContext $context = null,
        ?int $expectedSourceVersion = null,
        ?int $expectedDestinationVersion = null,
    ): LedgerTransferResult {
        $result = $this->executeOperation(
            [OperationAction::transferBetween(
                $sourceLedgerType,
                $sourceLedgerId,
                $destinationLedgerType,
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

        return $this->streamHeadVersion($canonicalType, $ledgerId);
    }

    private function streamHeadVersion(string $canonicalType, string $ledgerId): int
    {
        $head = $this->conn()->table($this->storage->table('ledger_stream_head'))->where([
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
        return LedgerTransaction::on($this->connectionName());
    }

    /** @return Builder<LedgerOperation> */
    public function operationQuery(): Builder
    {
        return LedgerOperation::on($this->connectionName());
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
            throw new EmptyOperationException;
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
            $this->assertOperationPolicies($kind, $planned, $heads, $context, $before, $after);
            $this->assertAggregateInvariants($after);
            $transactions = $this->persistAppends($operation, $planned, $context, $recordedAt);
            $this->runRequiredProjectors($operation, $transactions, $context);

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
                    if ($action->destinationLedgerType === null || $action->destinationLedgerId === null) {
                        throw new InvalidArgumentException('A transfer requires a destination ledger stream.');
                    }

                    $destinationLedgerType = $this->resolveLedger($action->destinationLedgerType)->getLedgerType();

                    if ($destinationLedgerType === $ledgerType && $action->destinationLedgerId === $action->ledgerId) {
                        throw new InvalidArgumentException('A transfer requires two different ledger streams.');
                    }

                    $normalized[] = OperationAction::transferBetween(
                        $ledgerType,
                        $action->ledgerId,
                        $destinationLedgerType,
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
            'actions' => array_map(fn (OperationAction $action): array => [
                'kind' => $action->kind->value,
                'ledger_type' => $action->ledgerType,
                'ledger_id' => $action->ledgerId,
                'payload_type' => $action->payload?->payloadType(),
                'payload' => $action->payload?->jsonSerialize(),
                'target_transaction_id' => $action->targetTransactionId,
                'destination_ledger_type' => $action->destinationLedgerType,
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
        $operation = new LedgerOperation;
        $operation->setConnection($this->connectionName());
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
            $this->conn()->transaction(fn () => $operation->save());

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
                    (string) $action->destinationLedgerType,
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
            $originalPayload = $this->deserializePayload($original->payload_type, $original->payload);

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
                $streams[] = [(string) $action->destinationLedgerType, (string) $action->destinationLedgerId];

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

        usort($streams, fn (array $left, array $right): int => strcmp($left[0], $right[0]) ?: strcmp($left[1], $right[1]));

        return array_values(array_unique($streams, SORT_REGULAR));
    }

    /** @param list<array{0: string, 1: string}> $streams */
    private function prepareStreamHeads(array $streams): void
    {
        foreach ($streams as [$ledgerType, $ledgerId]) {
            $query = $this->conn()->table($this->storage->table('ledger_stream_head'))->where([
                'ledger_type' => $ledgerType,
                'ledger_id' => $ledgerId,
            ]);

            if ($query->exists()) {
                continue;
            }

            $this->conn()->table($this->storage->table('ledger_stream_head'))->insertOrIgnore([
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
            $query = $this->conn()->table($this->storage->table('ledger_stream_head'))
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
                $aggregate = $this->rebuildAggregate(
                    $append->ledgerType,
                    $append->ledgerId,
                    $heads[$append->ledgerType][$append->ledgerId],
                );
                $before[$append->ledgerType][$append->ledgerId] = $aggregate;
                $after[$append->ledgerType][$append->ledgerId] = $aggregate;
            }

            try {
                $this->assertValidMoneyPayload($append->payload);
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

        return [$planned, $before, $after];
    }

    /**
     * @param  list<Append>  $planned
     * @param  array<string, array<string, int>>  $heads
     * @param  array<string, array<string, array<mixed>|JsonSerializable>>  $before
     * @param  array<string, array<string, array<mixed>|JsonSerializable>>  $after
     */
    private function assertOperationPolicies(
        OperationKind $kind,
        array $planned,
        array $heads,
        PostingContext $context,
        array $before,
        array $after,
    ): void {
        foreach ($after as $ledgerType => $streamAggregates) {
            $ledger = $this->resolveLedger($ledgerType);

            if (! $ledger instanceof OperationPolicy) {
                continue;
            }

            foreach ($streamAggregates as $ledgerId => $aggregateAfter) {
                $streamId = (string) $ledgerId;
                $streamAppends = array_values(array_filter(
                    $planned,
                    fn (Append $append): bool => $append->ledgerType === $ledgerType
                        && $append->ledgerId === $streamId,
                ));
                $ledger->assertOperationAllowed(new OperationValidationContext(
                    kind: $kind,
                    ledgerType: $ledgerType,
                    ledgerId: $streamId,
                    streamVersionBefore: $heads[$ledgerType][$ledgerId],
                    proposedPayloads: array_map(
                        fn (Append $append): LedgerPayload => $append->payload,
                        $streamAppends,
                    ),
                    postingContext: $context,
                    aggregateBefore: $before[$ledgerType][$ledgerId],
                    aggregateAfter: $aggregateAfter,
                    accountingEntries: $this->accountingEntries(
                        $ledgerType,
                        $streamId,
                        $heads[$ledgerType][$ledgerId],
                        $streamAppends,
                        $context,
                    ),
                ));
            }
        }
    }

    /**
     * @param  list<Append>  $planned
     * @return list<AccountingEntry>
     */
    private function accountingEntries(
        string $ledgerType,
        string $ledgerId,
        int $throughVersion,
        array $planned,
        PostingContext $context,
    ): array {
        $entries = $this->ledgerTranQuery()
            ->where('ledger_type', $ledgerType)
            ->where('ledger_id', $ledgerId)
            ->where('stream_version', '<=', $throughVersion)
            ->get()
            ->map(fn (LedgerTransaction $transaction): AccountingEntry => new AccountingEntry(
                accountingDate: $transaction->accounting_date,
                payload: $this->deserializePayload($transaction->payload_type, $transaction->payload),
                streamVersion: $transaction->stream_version,
                proposed: false,
            ))
            ->all();

        foreach ($planned as $append) {
            $entries[] = new AccountingEntry(
                accountingDate: $context->accountingDate,
                payload: $append->payload,
                streamVersion: $append->version ?? throw new LogicException('A planned append must have a stream version.'),
                proposed: true,
            );
        }

        usort($entries, function (AccountingEntry $left, AccountingEntry $right): int {
            $dateOrder = $left->accountingDate <=> $right->accountingDate;

            return $dateOrder !== 0 ? $dateOrder : $left->streamVersion <=> $right->streamVersion;
        });

        return $entries;
    }

    /** @param array<string, array<string, array<mixed>|JsonSerializable>> $aggregates */
    private function assertAggregateInvariants(array $aggregates): void
    {
        foreach ($aggregates as $ledgerType => $streamAggregates) {
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

    /**
     * @param  list<Append>  $appends
     * @return Collection<int, LedgerTransaction>
     */
    private function persistAppends(
        LedgerOperation $operation,
        array $appends,
        PostingContext $context,
        CarbonImmutable $recordedAt,
    ): Collection {
        if ($this->conn()->transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a database transaction.');
        }

        $transactions = [];
        $finalHeads = [];

        foreach ($appends as $append) {
            $transaction = new LedgerTransaction;
            $transaction->setConnection($this->connectionName());
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
            $transaction->payload = CanonicalJson::normalizeArray($append->payload->jsonSerialize());
            $transaction->reason = $context->reason;
            $transaction->stream_version = $append->version;
            $transaction->save();

            $transactions[] = $transaction;
            $finalHeads[$append->ledgerType][$append->ledgerId] = $append->version;
        }

        foreach ($finalHeads as $ledgerType => $streamHeads) {
            foreach ($streamHeads as $ledgerId => $version) {
                $this->conn()->table($this->storage->table('ledger_stream_head'))->where([
                    'ledger_type' => $ledgerType,
                    'ledger_id' => $ledgerId,
                ])->update(['version' => $version]);
            }
        }

        return new Collection($transactions);
    }

    /** @return array<mixed>|JsonSerializable */
    private function rebuildAggregate(
        string $ledgerType,
        string $ledgerId,
        int $throughVersion,
        ?TemporalView $view = null,
    ): array|JsonSerializable {
        $ledger = $this->resolveLedger($ledgerType);
        $aggregate = $ledger->initializeAggregate();
        $startingVersion = 0;

        if ($ledger instanceof SnapshotsAggregate) {
            $snapshotQuery = LedgerSnapshot::on($this->connectionName())
                ->where('ledger_type', $ledger->getLedgerType())
                ->where('ledger_id', $ledgerId)
                ->where('snapshot_version', $this->snapshotVersion($ledger))
                ->where('stream_version', '<=', $throughVersion);

            if ($view?->eventThrough !== null) {
                $snapshotQuery->where('max_event_date', '<=', $view->eventThrough);
            }

            if ($view?->accountingThrough !== null) {
                $snapshotQuery->where('max_accounting_date', '<=', $view->accountingThrough);
            }

            if ($view?->recordedThrough !== null) {
                $snapshotQuery->where('max_system_date', '<=', $view->recordedThrough);
            }

            $snapshot = $snapshotQuery->orderByDesc('stream_version')->first();

            if ($snapshot !== null) {
                if (! is_array($snapshot->aggregate)) {
                    throw new UnexpectedValueException('Stored aggregate snapshot must decode to an array.');
                }

                $aggregate = $ledger->hydrateAggregateSnapshot($snapshot->aggregate);
                $startingVersion = $snapshot->stream_version;
            }
        }

        $query = $this->ledgerTranQuery()
            ->where('ledger_type', $ledger->getLedgerType())
            ->where('ledger_id', $ledgerId)
            ->where('stream_version', '>', $startingVersion)
            ->where('stream_version', '<=', $throughVersion)
            ->orderBy('stream_version');

        if ($view !== null) {
            $this->applyTemporalView($query, $view);
        }

        /** @var Collection<int, LedgerTransaction> $history */
        $history = $query->get();

        foreach ($history as $entry) {
            $aggregate = $ledger->applyToAggregate(
                $this->deserializePayload($entry->payload_type, $entry->payload),
                $aggregate,
            );
        }

        return $aggregate;
    }

    private function snapshotVersion(SnapshotsAggregate $ledger): int
    {
        $version = $ledger->snapshotVersion();

        if ($version <= 0) {
            throw new InvalidArgumentException('Aggregate snapshot versions must be greater than zero.');
        }

        return $version;
    }

    /** @param Builder<LedgerTransaction>|QueryBuilder $query */
    private function applyTemporalView(
        Builder|QueryBuilder $query,
        TemporalView $view,
        ?string $table = null,
    ): void {
        $table ??= $this->storage->table('ledger_transaction');

        if ($view->eventThrough !== null) {
            $query->where("{$table}.event_date", '<=', $view->eventThrough);
        }

        if ($view->accountingThrough !== null) {
            $query->where("{$table}.accounting_date", '<=', $view->accountingThrough);
        }

        if ($view->recordedThrough !== null) {
            $query->where("{$table}.system_date", '<=', $view->recordedThrough);
        }
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
        return DB::connection($this->connectionName());
    }

    private function connectionName(): ?string
    {
        return $this->storage->connection($this->connectionOverride);
    }

    private function assertValidMoneyPayload(LedgerPayload $payload): void
    {
        if (! $payload instanceof HasMoneyAmount) {
            return;
        }

        $payload->amount();

        if (preg_match('/^[A-Z]{3}$/D', $payload->currency()) !== 1) {
            throw new InvalidArgumentException('Payload currencies must use three uppercase ASCII letters.');
        }
    }

    /** @param class-string<Projector>|Projector $projector */
    private function addProjector(string $ledgerType, string|Projector $projector): void
    {
        $identity = $this->projectorIdentity($projector);

        foreach ($this->projectorRegistry[$ledgerType] ?? [] as $registered) {
            if ($this->projectorIdentity($registered) === $identity) {
                return;
            }
        }

        $this->projectorRegistry[$ledgerType][] = $projector;
    }

    /** @param class-string<OperationProjector>|OperationProjector $projector */
    private function addOperationProjector(string $ledgerType, string|OperationProjector $projector): void
    {
        $identity = $this->projectorIdentity($projector);

        foreach ($this->operationProjectorRegistry as $registered) {
            if ($registered['ledgerType'] === $ledgerType
                && $this->projectorIdentity($registered['projector']) === $identity) {
                return;
            }
        }

        $this->operationProjectorRegistry[] = [
            'ledgerType' => $ledgerType,
            'projector' => $projector,
        ];
    }

    /** @param class-string<Projector|OperationProjector>|Projector|OperationProjector $projector */
    private function projectorIdentity(string|Projector|OperationProjector $projector): string
    {
        return is_string($projector) ? $projector : $projector::class;
    }

    /** @param Collection<int, LedgerTransaction> $transactions */
    private function runRequiredProjectors(
        LedgerOperation $operation,
        Collection $transactions,
        PostingContext $context,
    ): void {
        $connection = $this->conn();

        foreach ($transactions as $transaction) {
            $payload = $this->deserializePayload($transaction->payload_type, $transaction->payload);

            foreach ($this->projectorRegistry[$transaction->ledger_type] ?? [] as $registered) {
                $projector = is_string($registered) ? app($registered) : $registered;
                $projector->project($transaction, $payload, $context, $connection);
            }
        }

        $participatingTypes = array_fill_keys($transactions->pluck('ledger_type')->all(), true);
        $invoked = [];

        foreach ($this->operationProjectorRegistry as $registered) {
            $identity = $this->projectorIdentity($registered['projector']);

            if (! isset($participatingTypes[$registered['ledgerType']]) || isset($invoked[$identity])) {
                continue;
            }

            $projector = is_string($registered['projector'])
                ? app($registered['projector'])
                : $registered['projector'];
            $projector->projectOperation($operation, $transactions, $context, $connection);
            $invoked[$identity] = true;
        }
    }

    /**
     * @param  class-string<ReplayableProjector>|ReplayableProjector  $projector
     */
    private function resolveReplayableProjector(string|ReplayableProjector $projector): ReplayableProjector
    {
        if ($projector instanceof ReplayableProjector) {
            return $projector;
        }

        if (! is_subclass_of($projector, ReplayableProjector::class)) {
            throw new InvalidArgumentException('Replayable projectors must implement ReplayableProjector.');
        }

        return app($projector);
    }
}
