# Implementation Guide: Typed Payloads and Synchronous Projections

Status: Implementation specification

## 1. Purpose and agreed semantics

This guide defines the implementation of priority #6 from the
[Motor Equipment supplement](abacus-motor-equipment-supplement.md): typed
payloads and required synchronous projections.

Abacus uses a single polymorphic table (`ledger_transaction`) to store immutable
financial transactions across streams. While this design minimizes database
migration overhead and centralizes write-once permissions, consuming
applications must not be forced to query, filter, aggregate, or join against
untyped JSON.

Equally, consuming domains should not be forced to duplicate ledger
infrastructure (locks, head versions, operation grouping, immutability) just to
populate relational tables.

Abacus solves this tension through two coordinated capabilities:

1. **Typed Payloads and Registry:** Deterministic serialization and
   deserialization of strongly typed payload objects, with explicit preservation
   of integer or minor-unit money values without floating-point hazards.
2. **Synchronous Transactional Projections:** Domain-defined projectors that
   receive newly persisted ledger transactions and write relational query models
   **within the same database transaction**. If a required projection fails, the
   entire Abacus operation rolls back.

```text
Domain Command
    │
    ▼
Abacus Write Coordinator
    │── 1. Acquire canonical stream head locks
    │── 2. Validate payload types and schema rules
    │── 3. Apply entries to in-memory aggregates & assert invariants
    │── 4. Persist ledger_operation and ledger_transaction records
    │── 5. Run synchronous domain projectors (same DB transaction)
    │       ├── Write domain relational snapshots (e.g. hourly_use_snapshots)
    │       └── Record domain workflow facts (e.g. AP settlement claims)
    │── 6. Update stream head versions
    ▼
Commit (or rollback everything on invariant, constraint, or projector failure)
```

| Concern | Abacus responsibility | Consuming domain responsibility |
| --- | --- | --- |
| Transaction storage | Authoritative immutable JSON event log, operation grouping, sequence ordering | Domain schema, migrations, and storage for relational projections |
| Payload types | Payload registry, serialization contracts, deterministic JSON formatting | Domain payload classes, business attributes, rate/account structures |
| Money handling | Minor-unit integer conventions, exact opposing calculation | Currency formatting, exchange rates, rounding rules |
| Projections | Synchronous transactional execution hook, replay orchestrator | Relational projection models, SQL indices, join optimization |
| Projection failure | Roll back the entire database transaction, leaving zero version gaps | Handle domain error reporting and validation feedback |

---

## 2. Typed payloads and payload registry

### 2.1 The typed payload contract

All event payloads passed to Abacus implement `LedgerPayload`. In addition to
`payloadType()` and `jsonSerialize()`, typed payloads must support deterministic
hydration from raw database JSON arrays.

```php
namespace Faest\Abacus\Contracts;

use JsonSerializable;

interface LedgerPayload extends JsonSerializable
{
    /**
     * The unique, stable type identifier stored in ledger_transaction.payload_type.
     */
    public function payloadType(): string;

    /**
     * Deterministic serialization into an associative array for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array;
}
```

To support instantiation without reflection overhead, typed payload classes
implement `DeserializablePayload`:

```php
namespace Faest\Abacus\Contracts;

interface DeserializablePayload extends LedgerPayload
{
    /**
     * Hydrate a typed payload instance from stored JSON data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromPayload(array $data): static;
}
```

When a payload does not implement `DeserializablePayload`, the ledger's
`deserialize()` method serves as the fallback factory. If no custom mapping is
registered, Abacus falls back to `GenericPayload` for compatibility.

### 2.2 Payload registry

Abacus introduces a centralized `PayloadRegistry` to map string type identifiers
to typed PHP classes.

```php
namespace Faest\Abacus;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use InvalidArgumentException;

final class PayloadRegistry
{
    /** @var array<string, class-string<LedgerPayload>> */
    private array $registry = [];

    /**
     * Register a mapping from a payload type string to a payload class.
     *
     * @param class-string<LedgerPayload> $payloadClass
     */
    public function register(string $type, string $payloadClass): self
    {
        if (! is_subclass_of($payloadClass, LedgerPayload::class)) {
            throw new InvalidArgumentException("Class {$payloadClass} must implement LedgerPayload.");
        }

        $this->registry[$type] = $payloadClass;

        return $this;
    }

    /**
     * Resolve and hydrate a payload from stored JSON.
     *
     * @param array<string, mixed> $data
     */
    public function deserialize(string $type, array $data): LedgerPayload
    {
        if (isset($this->registry[$type])) {
            $class = $this->registry[$type];

            if (is_subclass_of($class, DeserializablePayload::class)) {
                return $class::fromPayload($data);
            }
        }

        return GenericPayload::make($type, $data);
    }
}
```

Registration is available:
- Globally via `Abacus::registerPayload(string $type, string $class)` or the
  `abacus.php` configuration file.
- Per-ledger via an optional `payloads(): array<string, class-string<LedgerPayload>>`
  method on the ledger definition.

### 2.3 Strict money and financial precision

Financial ledgers must never store or compute currency amounts using IEEE 754
floating-point numbers (`float`). Rounding discrepancies and representation
errors compromise exact balancing and invariant validation.

Abacus enforces the following rules for financial payloads:

1. **Integer minor units:** Monetary amounts must be represented as integers
   denoting minor units (e.g., cents: `1000` represents `$10.00 USD`).
2. **Explicit currency:** Any monetary payload must explicitly include its
   three-letter ISO 4217 currency code (e.g., `'USD'`, `'CAD'`, `'EUR'`).
3. **Deterministic opposition:** Opposing payloads produced for reversals
   (`computeOpposing()`) must negate the integer amount directly:
   `-$originalAmount`.
4. **No implicit currency conversion:** Abacus streams are single-currency or
   explicitly multi-currency aware at the aggregate level. Cross-currency
   conversions must happen in the domain before posting.

```php
namespace Faest\Abacus\Contracts;

interface HasMoneyAmount
{
    /**
     * Amount in integer minor units (e.g., cents).
     */
    public function amount(): int;

    /**
     * ISO 4217 currency code.
     */
    public function currency(): string;
}
```

### 2.4 Deterministic JSON serialization

Stored payloads are used to generate idempotency fingerprints and verify
immutable history. Serialization must be canonical:
- Associative array keys must be recursively sorted in ascending alphabetical
  order before JSON encoding.
- Scalar data types must be strictly preserved (`int` remains integer, `bool`
  remains boolean, `null` is preserved).
- Timestamps must be serialized in ISO 8601 / RFC 3339 format with explicit UTC
  offsets.

---

## 3. Synchronous transactional projections

### 3.1 Why synchronous projections are required

In event-sourced and ledger architectures, projections are often implemented as
asynchronous queue listeners. While asynchronous eventual consistency is
acceptable for analytics or non-critical views, it is unacceptable for
financial operations like Motor Equipment hourly-use billing:

- When an hourly-use amendment completes, the Accounts Payable (AP) claim
  eligibility must be immediately visible to the subsequent request in the same
  HTTP lifecycle.
- When an equipment rental is posted, the relational snapshot containing the
  work order, rate tier, and department allocation must exist before returning a
  success response to the user.
- If writing the domain snapshot fails (e.g., unique key violation, invalid
  foreign key reference), the ledger transaction must **not** remain committed in
  isolation.

Therefore, required domain projections must execute **synchronously inside the
same database transaction** as the Abacus append.

### 3.2 Projector contracts

Abacus defines two projector contracts to accommodate entry-level and
operation-level projection requirements:

#### Entry Projector

Runs once for each `LedgerTransaction` appended to a stream:

```php
namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerTransaction;

interface Projector
{
    /**
     * Project an individual ledger transaction into a domain query model.
     *
     * Runs inside the active Abacus database transaction.
     */
    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
    ): void;
}
```

#### Operation Projector

Runs once per completed `LedgerOperation`, receiving all newly created
transactions and the operation record. This is essential for operations that
record cross-entry relationships, such as vehicle-to-vehicle equity transfers or
reversal-and-replacement correction aggregates:

```php
namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Eloquent\Collection;

interface OperationProjector
{
    /**
     * Project an entire completed operation into domain query models.
     *
     * @param Collection<int, LedgerTransaction> $transactions
     */
    public function projectOperation(
        LedgerOperation $operation,
        Collection $transactions,
        PostingContext $context,
    ): void;
}
```

### 3.3 Execution pipeline integration

Synchronous projectors run immediately after transactions and operations are
persisted, but **before** the stream head versions are committed and before the
database transaction closes.

The modified coordinator sequence in `Abacus::executeOperation()`:

```text
1. Begin database transaction (or join existing outer transaction)
2. Claim operation idempotency key
3. Resolve affected streams & canonicalize lock order
4. Prepare missing stream heads
5. Lock stream heads (SELECT ... FOR UPDATE)
6. Assert expected stream versions
7. Rebuild in-memory aggregates & validate entry payloads
8. Assert aggregate invariants
9. Assert correction policies
10. Persist LedgerOperation row
11. Persist LedgerTransaction rows (assigning IDs, stream_versions, timestamps)
12. EXECUTE SYNCHRONOUS PROJECTORS:
      a. For each persisted transaction -> invoke matching Projector::project()
      b. For the operation -> invoke matching OperationProjector::projectOperation()
13. Update ledger_stream_head versions to match final committed entries
14. Commit database transaction (returns OperationResult)
```

If any projector throws an unhandled exception:
1. Laravel's `DB::transaction()` rolls back the entire database transaction.
2. All `ledger_transaction` and `ledger_operation` inserts are reverted.
3. Stream head version increments are reverted; no version gaps are left.
4. Any partial domain projection rows written prior to the exception are
   reverted.
5. The original exception is propagated to the caller.

### 3.4 Projector registration

Projectors are registered per-ledger type or globally through Abacus:

```php
// In a service provider or bootstrap configuration:
Abacus::registerProjector(
    ledgerType: VehicleEquityLedger::class,
    projector: HourlyUseSnapshotProjector::class,
);

Abacus::registerOperationProjector(
    ledgerType: VehicleEquityLedger::class,
    projector: VehicleCorrectionAggregateProjector::class,
);
```

Ledgers may also declare their own projectors by implementing
`HasProjectors`:

```php
namespace Faest\Abacus\Contracts;

interface HasProjectors
{
    /**
     * @return list<class-string<Projector|OperationProjector>|Projector|OperationProjector>
     */
    public function projectors(): array;
}
```

Projectors are resolved via Laravel's service container (`app($projectorClass)`),
allowing full dependency injection of domain repositories, models, or loggers.

---

## 4. Replayable read projections and replay tooling

### 4.1 Required inline vs. replayable read projections

It is vital to distinguish between two categories of projections:

| Feature | Required Inline Projections | Replayable Read Projections |
| --- | --- | --- |
| Purpose | Immediate workflow facts, snapshots, AP claims | Materialized views, reports, historical summaries |
| Timing | Synchronous, blocking inside write transaction | Replayable on-demand or during database migrations |
| Failure handling | Abort write and rollback transaction | Log failure, retry, or catch up in background |
| Rebuildable? | **No** (they are authoritative domain records) | **Yes** (derived entirely from transaction history) |
| Example | `hourly_use_snapshots`, `ap_voucher_claims` | `vehicle_monthly_equity_summary`, `fleet_utilization_stats` |

> [!WARNING]
> Never attempt to replay or rebuild required inline workflow records (such as
> AP claims or audit snapshots). Once created, those rows represent operational
> history. Replay tooling is strictly for disposable read models and reporting
> projections.

### 4.2 Replay contract

Projectors intended to support historical rebuilding implement `ReplayableProjector`:

```php
namespace Faest\Abacus\Contracts;

interface ReplayableProjector extends Projector
{
    /**
     * Reset the projection table before replay begins (e.g. TRUNCATE or DELETE).
     */
    public function resetProjection(): void;
}
```

### 4.3 Replay coordinator

Abacus provides programmatic and CLI commands to stream historical transactions
through a projector in exact canonical order:

```php
Abacus::replay(
    projector: app(VehicleMonthlyEquitySummaryProjector::class),
    ledgerType: VehicleEquityLedger::class,
    chunkSize: 1000,
);
```

#### Replay mechanics:
1. Call `resetProjection()` on the projector if supported.
2. Query `ledger_transaction` ordered strictly by:
   `system_date ASC, operation_id ASC, operation_position ASC`.
3. Process transactions in memory-efficient chunks (`chunkById()`).
4. Deserialize typed payloads using the `PayloadRegistry`.
5. Invoke `project()` on each transaction.
6. Record replay checkpoint state.

#### Artisan command:

```bash
php artisan abacus:project "App\Projectors\VehicleMonthlyEquitySummaryProjector" --ledger="vehicle-equity" --chunk=500
```

---

## 5. Motor Equipment reference implementation

This reference demonstrates how the Borough Portal Motor Equipment domain
implements item #6.

### 5.1 Typed payload: `HourlyUseRecorded`

```php
namespace App\Ledgers\VehicleEquity\Payloads;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\HasMoneyAmount;

final readonly class HourlyUseRecorded implements DeserializablePayload, HasMoneyAmount
{
    public const string TYPE = 'vehicle:hourly-use';

    public function __construct(
        public int $amountInCents,
        public string $currency,
        public float $hours,
        public string $rateCode,
        public string $workOrderId,
        public string $accountId,
        public string $useDate,
    ) {}

    public function payloadType(): string
    {
        return self::TYPE;
    }

    public function amount(): int
    {
        return $this->amountInCents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function jsonSerialize(): array
    {
        return [
            'account_id' => $this->accountId,
            'amount_in_cents' => $this->amountInCents,
            'currency' => $this->currency,
            'hours' => $this->hours,
            'rate_code' => $this->rateCode,
            'use_date' => $this->useDate,
            'work_order_id' => $this->workOrderId,
        ];
    }

    public static function fromPayload(array $data): static
    {
        return new self(
            amountInCents: (int) $data['amount_in_cents'],
            currency: (string) ($data['currency'] ?? 'USD'),
            hours: (float) $data['hours'],
            rateCode: (string) $data['rate_code'],
            workOrderId: (string) $data['work_order_id'],
            accountId: (string) $data['account_id'],
            useDate: (string) $data['use_date'],
        );
    }
}
```

### 5.2 Required synchronous projector: `HourlyUseSnapshotProjector`

This projector creates a domain relational record in `hourly_use_snapshots` whenever
an hourly-use transaction is posted. This table supports indexed joins for billing:

```php
namespace App\Projectors;

use App\Ledgers\VehicleEquity\Payloads\HourlyUseRecorded;
use App\Models\HourlyUseSnapshot;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\Projector;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerTransaction;

final class HourlyUseSnapshotProjector implements Projector
{
    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
    ): void {
        if (! $payload instanceof HourlyUseRecorded) {
            return;
        }

        HourlyUseSnapshot::create([
            'transaction_id' => $transaction->id,
            'operation_id' => $transaction->operation_id,
            'vehicle_id' => $transaction->ledger_id,
            'work_order_id' => $payload->workOrderId,
            'account_id' => $payload->accountId,
            'rate_code' => $payload->rateCode,
            'hours' => $payload->hours,
            'amount_cents' => $payload->amountInCents,
            'currency' => $payload->currency,
            'use_date' => $payload->useDate,
            'recorded_at' => $transaction->system_date,
            'actor' => $context->actor,
        ]);
    }
}
```

### 5.3 Operation projector: `CorrectionAggregateProjector`

When a replacement operation occurs, this projector records a unified domain
correction aggregate linking the original and replacement transactions:

```php
namespace App\Projectors;

use App\Models\VehicleCorrectionAggregate;
use Faest\Abacus\Contracts\OperationProjector;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Models\LedgerOperation;
use Illuminate\Database\Eloquent\Collection;

final class CorrectionAggregateProjector implements OperationProjector
{
    public function projectOperation(
        LedgerOperation $operation,
        Collection $transactions,
        PostingContext $context,
    ): void {
        if ($operation->kind !== OperationKind::Replacement) {
            return;
        }

        $reversal = $transactions->firstWhere('reverses_transaction_id', '!==', null);
        $replacement = $transactions->firstWhere('replaces_transaction_id', '!==', null);

        if (! $reversal || ! $replacement) {
            return;
        }

        VehicleCorrectionAggregate::create([
            'operation_id' => $operation->id,
            'original_transaction_id' => $reversal->reverses_transaction_id,
            'reversal_transaction_id' => $reversal->id,
            'replacement_transaction_id' => $replacement->id,
            'reason' => $context->reason,
            'actor' => $context->actor,
            'ap_disposition' => $context->metadata['ap_disposition'] ?? 'unclaimed',
        ]);
    }
}
```

### 5.4 Consuming domain query

Because the projection ran synchronously, downstream code immediately queries
the relational table using normal Eloquent features:

```php
// Finding unbilled work order charges without parsing JSON:
$charges = HourlyUseSnapshot::query()
    ->where('work_order_id', 'WO-9821')
    ->where('use_date', '>=', '2026-09-01')
    ->orderBy('use_date')
    ->get();

// Totaling billable hours directly in SQL:
$totalCents = HourlyUseSnapshot::query()
    ->where('vehicle_id', $vehicle->id)
    ->whereBetween('use_date', [$startDate, $endDate])
    ->sum('amount_cents');
```

---

## 6. Implementation sequence and acceptance

### 6.1 Implementation sequence

Implement this capability in five focused phases:

1. **Payload Registry & Contracts:**
   - Define `DeserializablePayload` and `HasMoneyAmount` contracts in `Faest\Abacus\Contracts`.
   - Implement `PayloadRegistry` with support for registration, type checks, and fallback to `GenericPayload`.
   - Bind `PayloadRegistry` as a singleton in `AbacusServiceProvider`.
   - Add helper methods to the `Abacus` coordinator: `registerPayload()`, `deserializePayload()`.
2. **Synchronous Projector Execution:**
   - Define `Projector` and `OperationProjector` contracts.
   - Add projector registry to `Abacus` coordinator (`registerProjector()`, `registerOperationProjector()`).
   - Add `HasProjectors` contract for ledgers to declare their own projectors.
   - In `Abacus::persistAppends()`, invoke registered transaction projectors after saving each `LedgerTransaction`.
   - Invoke registered operation projectors after saving all transactions in the operation.
3. **Rollback & Failure Guarantees:**
   - Ensure exceptions thrown inside projectors abort the transaction and bubble up cleanly.
   - Verify that rollbacks leave zero orphan projection rows, zero transaction records, and zero head version increments.
4. **Replay Engine & Artisan Command:**
   - Define `ReplayableProjector` contract.
   - Implement `Abacus::replay()` method with chunking and ordered traversal.
   - Create `php artisan abacus:project` console command.
5. **Documentation & Boost Skill:**
   - Update `README.md` and the bundled Boost skill under `resources/boost/skills`.
   - Link this guide in `abacus-motor-equipment-supplement.md`.

### 6.2 Acceptance criteria

Acceptance tests must verify:

- [ ] **Typed deserialization:** A registered payload class hydrates correctly from stored JSON via `PayloadRegistry`.
- [ ] **Unregistered fallback:** Unregistered payload types deserialize into `GenericPayload` without throwing exceptions.
- [ ] **Strict money handling:** Payloads enforcing integer minor units reject floating-point inputs during validation.
- [ ] **Deterministic serialization:** Key sorting and scalar normalization ensure identical JSON encoding across executions.
- [ ] **Synchronous projection write:** Appending a transaction synchronously creates the projected domain row inside the same database transaction.
- [ ] **Atomic projection rollback:** If a projector throws an exception (e.g. database error, validation rule), the entire Abacus operation rolls back, leaving no `ledger_transaction`, no `ledger_operation`, no projection rows, and no stream head advancement.
- [ ] **Operation projection:** Multi-entry and replacement operations invoke `OperationProjector` with the complete set of ordered transactions.
- [ ] **Replay fidelity:** Replaying historical transactions through a `ReplayableProjector` produces an identical relational dataset to synchronous execution.
- [ ] **Multi-database validation:** Projection atomicity and rollbacks pass under both SQLite and PostgreSQL harnesses (`composer test` and `composer test:pg`).
