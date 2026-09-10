<?php

declare(strict_types=1);

namespace Faest\Abacus\Facades;

use Closure;
use Faest\Abacus\BundleBuilder;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;

/**
 * @method static \Faest\Abacus\Abacus registerLedger(Ledger $ledger)
 * @method static Ledger resolveLedger(string $ledgerType)
 * @method static array<mixed>|JsonSerializable getAggregate(string $ledgerType, string $ledgerId)
 * @method static int streamVersion(string $ledgerType, string $ledgerId)
 * @method static Transaction post(TransactionDraft $draft, ?PostingContext $context = null)
 * @method static array<int, Transaction> postMany(array<int, TransactionDraft> $drafts, ?PostingContext $context = null)
 * @method static array<int, Transaction> bundle(PostingContext|Closure(BundleBuilder): void $contextOrCallback, ?(Closure(BundleBuilder): void) $callback = null)
 * @method static Transaction void(VoidDraft $draft, ?PostingContext $context = null)
 * @method static Transaction reverse(string $transactionId, ?PostingContext $context = null)
 * @method static array<int, Transaction> reverseOperation(string $correlationId, ?PostingContext $context = null)
 * @method static LedgerTransferResult transfer(LedgerPayload $payload, string $sourceLedgerId, string $destinationLedgerId, string $ledgerType, ?PostingContext $context = null)
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
