<?php

declare(strict_types=1);

namespace Faest\Abacus\Facades;

use Closure;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\OperationProjector;
use Faest\Abacus\Contracts\Projector;
use Faest\Abacus\Contracts\ReplayableProjector;
use Faest\Abacus\Data\LedgerReplacementResult;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\OperationResult;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TemporalView;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionCriteria;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\OperationBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;

/**
 * @method static \Faest\Abacus\Abacus registerLedger(Ledger $ledger)
 * @method static \Faest\Abacus\Abacus registerPayload(string $type, string $payloadClass)
 * @method static LedgerPayload deserializePayload(string $type, array<string, mixed> $data)
 * @method static \Faest\Abacus\Abacus registerProjector(string $ledgerType, string|Projector $projector)
 * @method static \Faest\Abacus\Abacus registerOperationProjector(string $ledgerType, string|OperationProjector $projector)
 * @method static int rebuildProjection(string|ReplayableProjector $projector, string $ledgerType, int $chunkSize = 1000)
 * @method static Ledger resolveLedger(string $ledgerType)
 * @method static array<mixed>|JsonSerializable getAggregate(string $ledgerType, string $ledgerId, ?TemporalView $view = null)
 * @method static array<mixed>|JsonSerializable getAggregateAtOperation(string $ledgerType, string $ledgerId, string $operationId)
 * @method static int createAggregateSnapshot(string $ledgerType, string $ledgerId)
 * @method static ?OperationResult findOperation(string $operationId)
 * @method static Builder<LedgerOperation> operationsForStream(string $ledgerType, string $ledgerId)
 * @method static Builder<LedgerTransaction> transactionsForStream(string $ledgerType, string $ledgerId, ?TransactionCriteria $criteria = null)
 * @method static int streamVersion(string $ledgerType, string $ledgerId)
 * @method static Transaction post(string $ledgerType, string $ledgerId, LedgerPayload $payload, ?PostingContext $context = null, ?int $expectedVersion = null)
 * @method static array<int, Transaction> postMany(string $ledgerType, string $ledgerId, array<int, LedgerPayload> $payloads, ?PostingContext $context = null, ?int $expectedVersion = null)
 * @method static OperationResult operation(PostingContext|Closure(OperationBuilder): void $contextOrCallback, ?(Closure(OperationBuilder): void) $callback = null)
 * @method static Transaction reverse(string $transactionId, ?PostingContext $context = null, ?int $expectedVersion = null)
 * @method static LedgerReplacementResult replace(string $transactionId, LedgerPayload $replacement, ?PostingContext $context = null, ?int $expectedVersion = null)
 * @method static Transaction adjust(string $transactionId, LedgerPayload $delta, ?PostingContext $context = null, ?int $expectedVersion = null)
 * @method static array<int, Transaction> reverseOperation(string $operationId, ?PostingContext $context = null)
 * @method static LedgerTransferResult transfer(LedgerPayload $payload, string $sourceLedgerId, string $destinationLedgerId, string $ledgerType, ?PostingContext $context = null, ?int $expectedSourceVersion = null, ?int $expectedDestinationVersion = null)
 * @method static LedgerTransferResult transferBetween(LedgerPayload $payload, string $sourceLedgerType, string $sourceLedgerId, string $destinationLedgerType, string $destinationLedgerId, ?PostingContext $context = null, ?int $expectedSourceVersion = null, ?int $expectedDestinationVersion = null)
 * @method static void overrideConnection(string $connection)
 * @method static void overrideLockTimeout(?int $timeout)
 *
 * @see \Faest\Abacus\Abacus
 */
class Abacus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Faest\Abacus\Abacus::class;
    }
}
