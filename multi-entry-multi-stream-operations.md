# Developer Guide: Multi-Entry and Multi-Stream Operations

## Purpose

This document specifies the design and implementation plan for **Prioritization Item #3: Multi-Entry and Multi-Stream Operations** in the Abacus Ledger Toolkit.

Currently, [`AbstractLedger`](src/AbstractLedger.php) manages both domain ledger logic (aggregates, invariants, payload formatting) and low-level stream coordination. While `AbstractLedger::postMany` supports multiple entries across streams of the *same* ledger type, it cannot coordinate operations spanning **different ledger types** (e.g. `VehicleEquityLedger` and `VehicleDepreciationLedger`, or `AccountsPayableLedger` and `GeneralLedger`).

This guide defines how the [`Abacus`](src/Abacus.php) manager and facade will act as a higher-level transaction coordinator, establishing a clean two-tier architecture:
1. **The Abacus Coordinator:** Owns write orchestration, global lock ordering, DB transaction boundaries, version assignment, and shared correlation IDs.
2. **Individual Ledgers:** Own domain rules, aggregates, invariant verification, and payload serialization for their specific stream type.

---

## Required Guarantees

For any multi-entry or multi-stream operation, Abacus must guarantee:

1. **Deadlock-Free Global Locking:** All affected stream heads `(ledger_type, ledger_id)` across all participating ledgers are locked in strict lexicographical order before reading state or checking invariants.
2. **Atomic All-or-Nothing Commit:** All transactions across all streams commit together in a single database transaction, or none do.
3. **Continuous Running Aggregates:** If multiple entries in a bundle target the *same* stream, invariant checks for subsequent entries evaluate against the running in-memory aggregate of prior staged entries in that batch.
4. **No Version Burning on Failure:** If an invariant or optimistic concurrency check fails on any stream, all locks are released and no stream version is incremented.
5. **Unified Correlation Envelope:** A single `correlation_id` (either supplied by the domain or auto-generated) links all created transactions across all streams in the operation.
6. **Consistent Recorded Time:** All transactions in the operation share the exact same toolkit-assigned system timestamp (`recorded_at`).

---

## Architecture: The Two-Tier Model

```text
               ┌────────────────────────────────────────────────────────┐
               │              Abacus Facade / Coordinator               │
               │  - Global stream canonicalization & lock ordering      │
               │  - Atomic DB transaction management                    │
               │  - Shared correlation & posting context management     │
               │  - Ledger registry & container resolution              │
               └──────────────────────────┬─────────────────────────────┘
                                          │ dispatches to
                         ┌────────────────┴────────────────┐
                         ▼                                 ▼
             ┌───────────────────────┐         ┌───────────────────────┐
             │  VehicleEquityLedger  │         │ VehicleDeprecLedger   │
             │ - Invariants          │         │ - Invariants          │
             │ - Aggregates          │         │ - Aggregates          │
             │ - Payload definitions │         │ - Payload definitions │
             └───────────────────────┘         └───────────────────────┘
```

---

## Core Components

### 1. `PostingContext`
Carries the operational context across all transactions in the operation.

```php
namespace Faest\Abacus\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

readonly class PostingContext
{
    public function __construct(
        public string $actor,
        public CarbonImmutable $eventDate,
        public CarbonImmutable $accountingDate,
        public string $reason,
        public ?string $correlationId = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {}

    public static function forUser(
        string|int|null $userId = null,
        ?CarbonInterface $eventDate = null,
        ?CarbonInterface $accountingDate = null,
        string $reason = '',
    ): self;

    public static function forProcess(
        string $processName,
        CarbonInterface $eventDate,
        CarbonInterface $accountingDate,
        string $reason,
        ?string $idempotencyKey = null,
    ): self;
}
```

### 2. `TransactionDraft`
Represents an individual planned entry for a specific stream.

```php
namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;

readonly class TransactionDraft
{
    public function __construct(
        public string $ledgerType,
        public string $ledgerId,
        public LedgerPayload $payload,
        public ?int $expectedVersion = null,
        public ?string $reversesTransactionId = null,
    ) {}

    public static function make(
        string $ledgerType,
        string $ledgerId,
        LedgerPayload $payload,
    ): self;
}
```

### 3. `BundleBuilder`
A fluent builder for staging multi-entry and multi-ledger operations.

```php
namespace Faest\Abacus\Coordination;

use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\TransactionDraft;

class BundleBuilder
{
    /** @var array<int, TransactionDraft> */
    private array $drafts = [];

    public function post(string $ledgerType, string $ledgerId, LedgerPayload $payload, ?int $expectedVersion = null): self
    {
        $this->drafts[] = TransactionDraft::make($ledgerType, $ledgerId, $payload)
            ->withExpectedVersion($expectedVersion);

        return $this;
    }

    public function reverse(string $ledgerType, string $transactionId): self
    {
        // Resolves the transaction to reverse and appends opposing draft
        return $this;
    }

    public function addDraft(TransactionDraft $draft): self
    {
        $this->drafts[] = $draft;

        return $this;
    }

    /** @return array<int, TransactionDraft> */
    public function getDrafts(): array
    {
        return $this->drafts;
    }
}
```

### 4. `Abacus` Manager & Registry
The coordinator resolved via container (`app(Abacus::class)`) and facade (`Faest\Abacus\Facades\Abacus`).

```php
namespace Faest\Abacus;

use Closure;
use Faest\Abacus\Coordination\BundleBuilder;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionDraft;

class Abacus
{
    /** @var array<string, class-string<AbstractLedger>> */
    private array $ledgerRegistry = [];

    public function registerLedger(string $type, string $ledgerClass): void
    {
        $this->ledgerRegistry[$type] = $ledgerClass;
    }

    public function resolveLedger(string $ledgerType): AbstractLedger
    {
        $class = $this->ledgerRegistry[$ledgerType] ?? $ledgerType;

        if (! class_exists($class) || ! is_subclass_of($class, AbstractLedger::class)) {
            throw new \InvalidArgumentException("Unregistered or invalid ledger type: {$ledgerType}");
        }

        return app($class);
    }

    /**
     * Post multiple drafts across arbitrary streams/ledger types.
     *
     * @param array<int, TransactionDraft> $drafts
     * @return array<int, Transaction>
     */
    public function postMany(array $drafts, ?PostingContext $context = null): array;

    /**
     * Coordinate a bundle using a builder callback.
     *
     * @param Closure(BundleBuilder): void $callback
     * @return array<int, Transaction>
     */
    public function bundle(PostingContext $context, Closure $callback): array;
}
```

---

## Public API Usage Patterns

### Pattern 1: Declarative Multi-Ledger Batch (`Abacus::postMany`)

```php
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TransactionDraft;

$context = PostingContext::forProcess(
    processName: 'vehicle-decommissioning',
    eventDate: now(),
    accountingDate: now(),
    reason: 'Retire Vehicle #101',
);

$transactions = Abacus::postMany([
    TransactionDraft::make(
        ledgerType: VehicleEquityLedger::class,
        ledgerId: 'vehicle-101',
        payload: new EquityWriteOffPayload(amount: 150000),
    ),
    TransactionDraft::make(
        ledgerType: VehicleDepreciationLedger::class,
        ledgerId: 'vehicle-101',
        payload: new CloseDepreciationPayload(salvageValue: 5000),
    ),
], $context);
```

### Pattern 2: Fluent Bundle Builder (`Abacus::bundle`)

```php
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Coordination\BundleBuilder;

$context = PostingContext::forUser(
    userId: auth()->id(),
    reason: 'Vehicle transfer and account adjustment',
);

$results = Abacus::bundle($context, function (BundleBuilder $bundle) use ($fromVehicle, $toVehicle) {
    // 1. Source vehicle debit
    $bundle->post(
        VehicleEquityLedger::class,
        $fromVehicle->id,
        new TransferDebitPayload(amount: 50000)
    );

    // 2. Destination vehicle credit
    $bundle->post(
        VehicleEquityLedger::class,
        $toVehicle->id,
        new TransferCreditPayload(amount: 50000)
    );
});
```

### Pattern 3: Refactored `AbstractLedger` Delegation

`AbstractLedger` delegates directly to the central coordinator so that single-ledger and multi-ledger paths share identical locking and write protocols:

```php
abstract class AbstractLedger
{
    public function post(TransactionDraft $draft, ?PostingContext $context = null): Transaction
    {
        return $this->postMany([$draft], $context)[0];
    }

    public function postMany(array $drafts, ?PostingContext $context = null): array
    {
        return app(Abacus::class)->postMany($drafts, $context ?? PostingContext::forUser());
    }
}
```

---

## The 4-Stage Execution Pipeline

When executing `Abacus::postMany` or `Abacus::bundle`, the coordinator runs this 4-stage pipeline inside a database transaction:

```text
┌────────────────────────────────────────────────────────────────────────┐
│ 1. CANONICALIZE & DEDUPLICATE STREAMS                                  │
│    - Extract all (ledger_type, ledger_id) pairs from drafts            │
│    - Sort lexicographically: strcmp(ledger_type) -> strcmp(ledger_id)  │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│ 2. SAFE LOCK ACQUISITION                                               │
│    - Execute insertOrIgnore on ledger_stream_head for missing pairs    │
│    - SELECT FOR UPDATE on ledger_stream_head in sorted order           │
│    - Load current stream versions into lock map                        │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│ 3. VALIDATION & INVARIANT EVALUATION                                   │
│    - Verify optimistic expectedVersion on each stream                  │
│    - For each draft:                                                   │
│        a. Resolve ledger instance for draft->ledgerType                │
│        b. Fetch or retrieve in-memory running aggregate                │
│        c. Execute ledger->assertInvariants(draft->payload, aggregate)  │
│        d. Update in-memory aggregate via applyToAggregate()            │
│        e. Stage append with next incremented stream version            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│ 4. ATOMIC PERSISTENCE                                                  │
│    - Resolve unified correlation_id (from context, or generate UUID)   │
│    - Bulk insert LedgerTransaction records                             │
│    - UPDATE ledger_stream_head versions                                │
│    - Execute synchronous inline projections (if registered)            │
│    - Return completed Transaction DTOs                                 │
└────────────────────────────────────────────────────────────────────────┘
```

---

## Edge Cases and Implementation Rules

### 1. Same-Stream Multiple Appends in a Single Bundle
When a single bundle contains multiple entries for the same stream:
- The initial aggregate is loaded once from the database.
- Subsequent drafts evaluate invariants against the mutated in-memory aggregate produced by earlier drafts in the bundle.
- Stream version increments sequentially ($v+1, v+2, \dots$) and the stream head is updated to the final version.

### 2. Invariant Failure Isolation
If any invariant assertion throws a `FailedInvariantException`:
- The database transaction rolls back automatically.
- No `ledger_transaction` records are created.
- Stream head versions remain at their pre-transaction values (no "burned" versions).

### 3. Correlation ID Rules
- **Explicit Correlation:** If `PostingContext::$correlationId` is set, all transactions receive that ID.
- **Auto-Generated Correlation (> 1 entry):** If no ID is supplied and the bundle contains $\ge 2$ entries, Abacus generates one UUID and assigns it to all rows in the bundle.
- **Single Independent Entry (1 entry):** `correlation_id` defaults to `null` to prevent audit noise unless explicitly specified.

---

## Implementation Checklist

- [ ] Create `PostingContext` value object with factories (`forUser`, `forProcess`, `forImport`).
- [ ] Create `BundleBuilder` for staging multi-draft operations.
- [ ] Implement `Abacus` manager class with `registerLedger()`, `resolveLedger()`, `postMany()`, and `bundle()`.
- [ ] Refactor stream-head locking and write protocols out of `AbstractLedger` and into `Abacus` coordinator.
- [ ] Update `AbstractLedger::postMany` to proxy through `Abacus::postMany`.
- [ ] Write Pest feature tests covering:
  - Multi-stream atomic commits across different ledger types.
  - Multi-stream rollback when an invariant fails on the second ledger.
  - Correct running aggregate calculation for multiple entries targeting the same stream.
  - Deadlock-free concurrent bundle writes.
  - Proper correlation ID assignment across multi-ledger batches.
