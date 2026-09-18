# Implementation Guide: Typed Payloads and Projections

Status: Draft implementation specification. The payload contracts and registry
are partially implemented. Required projectors and read-projection rebuilds are
not implemented. The implementation sequence in this guide begins by
reconciling the partial payload work with the final contracts below.

## 1. Purpose and agreed semantics

This guide defines priority #6 from the
[Motor Equipment supplement](abacus-motor-equipment-supplement.md): typed
payloads, required synchronous projections, and rebuildable read projections.

Abacus stores immutable financial transactions in the polymorphic
`ledger_transaction` table. Consuming applications should not have to parse
untyped JSON for every domain operation or maintain a second implementation of
ledger locking, versioning, corrections, and operation grouping just to obtain
relational domain data.

Abacus therefore provides three related but distinct capabilities:

1. **Typed payloads:** A central registry deterministically serializes and
   hydrates domain payload objects.
2. **Required projections:** Domain records that are part of a successful
   ledger operation are written synchronously in the same database transaction.
3. **Replayable read projections:** Disposable reporting models can be reset
   and rebuilt explicitly from committed ledger history outside the write path.

The distinction between the two projection types is semantic, not merely an
execution preference:

| Property | Required projection | Replayable read projection |
| --- | --- | --- |
| Purpose | Workflow fact or authoritative snapshot | Report, summary, or materialized view |
| Normal execution | Inside the Abacus write transaction | Explicit rebuild command or API call |
| Append failure | Rolls back the complete operation | Cannot prevent an append from committing |
| Safe to delete and recreate | No | Yes, from ledger history alone |
| Example | `hourly_use_snapshots`, `ap_voucher_claims` | `vehicle_monthly_equity_summary` |

A required projection answers “what was committed as part of this business
operation?” A replayable projection answers “what view can be calculated from
the committed history?” Deleting a required projection is data loss. Deleting a
replayable projection is an availability problem that a rebuild can repair.

```text
Domain command
    │
    ▼
Abacus write transaction
    │── lock stream heads and validate the completed operation
    │── persist ledger_operation and ledger_transaction rows
    │── update affected stream heads
    │── execute required entry and operation projectors
    ▼
Commit everything, or roll everything back

Later, outside the write path and during a maintenance window:

Explicit projection rebuild
    │── reset one disposable read model
    │── stream committed transactions in canonical order
    │── hydrate each payload through the central registry
    ▼
Commit the complete rebuilt view, or restore the previous view
```

| Concern | Abacus responsibility | Consuming domain responsibility |
| --- | --- | --- |
| Ledger storage | Immutable operations, transactions, relationships, and stream order | Domain payload fields and ledger rules |
| Payload types | Central registration, canonicalization, hydration, and unknown fallback | Payload construction, field validation, and stable type names |
| Money | Invoke the money contract and reject malformed currency codes | Reject non-integer input before construction or hydration |
| Required projections | Registration, ordering, selected connection, and rollback boundary | Projection schema, indexes, and rollback-safe database writes |
| Read rebuilds | Ordered orchestration, atomic reset/replay, and CLI entry point | Disposable projection logic and maintenance-window coordination |

### 1.1 V1 boundaries

The following are deliberately outside this guide:

- Queue dispatch or automatic after-commit projection work.
- Incremental catch-up, durable projection checkpoints, or projection lag APIs.
- Live rebuilds while relevant ledger writes continue.
- Shadow-table construction and atomic table swaps.
- Cross-database projection atomicity.
- One rebuild spanning multiple ledger types; applications use a separate
  projector invocation for each type.

Those features require separate delivery, ordering, and operational designs.
V1 provides only required inline projection and explicit full rebuild semantics.

---

## 2. Typed payloads and the central registry

### 2.1 Payload contracts

Every payload accepted by Abacus implements `LedgerPayload` and serializes to an
associative array:

```php
namespace Faest\Abacus\Contracts;

use JsonSerializable;

interface LedgerPayload extends JsonSerializable
{
    /**
     * A globally unique, stable identifier stored in
     * ledger_transaction.payload_type.
     */
    public function payloadType(): string;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array;
}
```

Payloads that can be reconstructed directly from stored JSON implement
`DeserializablePayload`:

```php
namespace Faest\Abacus\Contracts;

interface DeserializablePayload extends LedgerPayload
{
    /** @param array<string, mixed> $data */
    public static function fromPayload(array $data): static;
}
```

Financial payloads expose integer minor units and a currency code through
`HasMoneyAmount`:

```php
namespace Faest\Abacus\Contracts;

interface HasMoneyAmount
{
    public function amount(): int;

    public function currency(): string;
}
```

`HasMoneyAmount` is an accessor contract; it cannot detect a float that a weakly
typed caller already coerced into an integer. Financial payload constructors and
`fromPayload()` implementations must therefore validate raw values with
`is_int()` before assignment and must never cast a float or numeric string to an
integer.

When Abacus accepts a `HasMoneyAmount` payload, it calls both accessors and
rejects a currency that does not match `^[A-Z]{3}$`. This validates the storage
format without making Abacus responsible for maintaining an ISO 4217 catalog.
The consuming domain remains responsible for deciding which currencies it
supports.

### 2.2 Registry ownership and registration

`PayloadRegistry` is the sole package-level authority for hydrating historical
payloads. Payload type identifiers are global across the selected Abacus store;
domains should namespace them, for example `vehicle:hourly-use`.

```php
namespace Faest\Abacus;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use InvalidArgumentException;

final class PayloadRegistry
{
    /** @var array<string, class-string<DeserializablePayload>> */
    private array $registry = [];

    /** @param class-string<DeserializablePayload> $payloadClass */
    public function register(string $type, string $payloadClass): self
    {
        if (trim($type) === '' || ! is_subclass_of($payloadClass, DeserializablePayload::class)) {
            throw new InvalidArgumentException('Payload registration is invalid.');
        }

        if (isset($this->registry[$type]) && $this->registry[$type] !== $payloadClass) {
            throw new InvalidArgumentException("Payload type {$type} is already registered.");
        }

        $this->registry[$type] = $payloadClass;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function deserialize(string $type, array $data): LedgerPayload
    {
        $class = $this->registry[$type] ?? null;

        return $class === null
            ? GenericPayload::make($type, $data)
            : $class::fromPayload($data);
    }
}
```

Registration follows these rules:

1. The type must be a non-empty string and the class must implement
   `DeserializablePayload`.
2. Registering the same type-to-class mapping more than once is idempotent.
3. Registering a different class for an existing type throws
   `InvalidArgumentException`; registration order never silently changes how
   history is interpreted.
4. An unregistered type hydrates as `GenericPayload`, preserving its type and
   raw associative data without throwing.

Applications may register mappings in configuration:

```php
// config/abacus.php
'payloads' => [
    'vehicle:hourly-use' => HourlyUseRecorded::class,
],
```

or imperatively during application boot:

```php
Abacus::registerPayload(
    HourlyUseRecorded::TYPE,
    HourlyUseRecorded::class,
);
```

The service provider binds `PayloadRegistry` as a singleton, loads configured
mappings, and injects the same registry into the `Abacus` singleton. The public
coordinator exposes:

```php
/** @param class-string<DeserializablePayload> $payloadClass */
public function registerPayload(string $type, string $payloadClass): self;

/** @param array<string, mixed> $data */
public function deserializePayload(string $type, array $data): LedgerPayload;
```

`Ledger::deserialize()` is removed. Aggregate reconstruction, correction logic,
and projection rebuilds all call the central registry. Existing ledgers migrate
their deserialization mappings to `config('abacus.payloads')` or
`registerPayload()`. A ledger that requires a specific typed payload may reject
`GenericPayload` in its normal payload validation or reducer; the generic
fallback primarily preserves forward-compatible inspection of unknown history.

### 2.3 Canonical serialization

Before persistence and before creating an idempotency fingerprint, Abacus
normalizes the array returned by `jsonSerialize()`:

- Sort associative-array keys recursively in ascending bytewise order.
- Preserve list order.
- Preserve `int`, `float`, `string`, `bool`, and `null` scalar types.
- Reject resources, closures, arbitrary nested objects, and non-finite floats.
- Encode with `JSON_THROW_ON_ERROR`.

Payload implementations serialize date-times as RFC 3339 strings with explicit
offsets. Abacus does not infer whether an arbitrary string is a date-time.

The normalized array is assigned to `LedgerTransaction::payload`; the same
normalizer is used by `PayloadFingerprint`. Canonical key ordering does not
permit scalar coercion: `100`, `100.0`, and `'100'` remain distinct inputs.

---

## 3. Required synchronous projections

### 3.1 Contracts

An entry projector runs once for each newly persisted transaction whose ledger
type matches its registration:

```php
namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;

interface Projector
{
    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
        Connection $connection,
    ): void;
}
```

An operation projector runs once for a newly created operation. It receives the
complete transaction collection, including entries from every participating
stream and ledger type, in `operation_position` order:

```php
namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;

interface OperationProjector
{
    /** @param Collection<int, LedgerTransaction> $transactions */
    public function projectOperation(
        LedgerOperation $operation,
        Collection $transactions,
        PostingContext $context,
        Connection $connection,
    ): void;
}
```

Passing the active `Connection` makes the atomicity boundary explicit.
Projectors using Eloquent must direct their model query to
`$connection->getName()`. Writes made through another connection, an external
API, or a non-transactional store are outside Abacus's rollback guarantee.

### 3.2 Registration and resolution

Projectors are registered for one ledger type through the coordinator:

```php
Abacus::registerProjector(
    ledgerType: VehicleEquityLedger::class,
    projector: HourlyUseSnapshotProjector::class,
);

Abacus::registerOperationProjector(
    ledgerType: VehicleEquityLedger::class,
    projector: CorrectionAggregateProjector::class,
);
```

The projector argument may be a container-resolvable class string or an
instance. Ledgers may instead declare their projectors with `HasProjectors`:

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

Explicit and ledger-declared registrations are combined in registration order.
The same projector class or object registered more than once for the same
ledger type is invoked once.

Entry projectors receive only matching transactions. An operation projector is
eligible when at least one operation entry has its registered ledger type, and
receives the complete ordered operation. If the same operation projector is
eligible through more than one participating ledger type, it is still invoked
only once.

### 3.3 Write-pipeline ordering

Required projectors execute only for a newly claimed operation. Returning an
existing operation for a matching idempotency key does not execute them again.

The write coordinator performs these steps inside one transaction on the
selected connection:

1. Claim the operation idempotency key.
2. Resolve and lock every affected stream head in canonical order.
3. Rebuild aggregates, validate entries, and assert completed-operation
   invariants and correction policies.
4. Persist the `LedgerOperation` and every `LedgerTransaction`.
5. Update each affected stream head to its final version.
6. Iterate transactions in `operation_position` order. Deserialize each payload
   through `PayloadRegistry`, then invoke its matching entry projectors in
   registration order.
7. Invoke eligible operation projectors in registration order with the complete
   ordered transaction collection.
8. Return the operation result and commit the encompassing transaction.

Projectors therefore observe all new operation rows and final stream-head
versions, even though none of those writes are externally committed yet.

If a projector throws, Abacus does not catch and downgrade the failure. The
selected connection rolls back:

- The operation and transaction inserts.
- Every stream-head update.
- Every projection row written on that connection.

The original exception is propagated to the caller. A projector must not send
notifications, call remote services, or dispatch work that can run before the
database commits. A consuming application may arrange separate after-commit
side effects, but those are not required projections.

---

## 4. Replayable read projections

### 4.1 Independent contract

A replayable projector is not a subtype of `Projector` and is never discovered
through `HasProjectors`. This prevents a disposable report from accidentally
becoming a required participant in every ledger write.

```php
namespace Faest\Abacus\Contracts;

use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;

interface ReplayableProjector
{
    /**
     * Remove rows owned by this projection and ledger type using
     * rollback-safe DML.
     */
    public function resetProjection(
        string $ledgerType,
        Connection $connection,
    ): void;

    public function projectHistorical(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        LedgerOperation $operation,
        Connection $connection,
    ): void;
}
```

The projector must be deterministic using only the supplied immutable history
and stable code/configuration. It must not consult mutable current-domain state,
the current clock, random values, or remote services. `resetProjection()` must
use transaction-safe operations such as `DELETE`; it must not use DDL that
implicitly commits on a supported database.

A required business record must never implement this contract. If replay could
duplicate a claim, notification, settlement, or other workflow action, the data
is not a replayable read projection.

### 4.2 Programmatic rebuild

The coordinator exposes an explicit rebuild API:

```php
/**
 * @param class-string<ReplayableProjector>|ReplayableProjector $projector
 * @return int Number of transactions supplied to the projector.
 */
public function rebuildProjection(
    string|ReplayableProjector $projector,
    string $ledgerType,
    int $chunkSize = 1000,
): int;
```

The ledger argument accepts the same canonical type or ledger class understood
by `resolveLedger()`. The chunk size must be greater than zero. A class string is
resolved through Laravel's container.

The caller must pause writes for the selected ledger type for the duration of
the rebuild. Abacus does not attempt to acquire a global maintenance lock in v1.

The method then:

1. Begins one database transaction on the selected Abacus connection.
2. Calls `resetProjection()` with the canonical ledger type.
3. Selects transactions for the canonical ledger type, eager-loading each
   owning operation.
4. Traverses them with bounded-memory keyset pagination ordered by
   `system_date ASC`, `operation_id ASC`, and `operation_position ASC`.
5. Deserializes each payload through `PayloadRegistry`.
6. Calls `projectHistorical()` and increments the processed count.
7. Commits the reset and complete rebuilt view together.

`chunkById()` must not be used: transaction IDs are not the declared canonical
order. Keyset pagination advances over the complete three-column ordering tuple
and relies on the unique `(operation_id, operation_position)` constraint to
break timestamp ties.

If reset, hydration, querying, or projection fails, the transaction rolls back
and the previously committed read model remains intact. The exception is
propagated and no checkpoint is recorded because v1 has no resumable or
incremental replay mode.

### 4.3 Artisan command

The same behavior is available through:

```bash
php artisan abacus:projection:rebuild \
    "App\Projectors\VehicleMonthlyEquitySummaryProjector" \
    --ledger="vehicle-equity" \
    --chunk=500
```

The command validates that the resolved class implements
`ReplayableProjector`, displays the processed count on success, and returns a
non-zero exit code without swallowing the original error on failure. In a
production environment it requests confirmation because the projection is
reset; `--force` suppresses that confirmation for an already coordinated
maintenance process. Neither confirmation nor `--force` pauses ledger writes.

---

## 5. Motor Equipment reference

### 5.1 Strict typed payload

This abbreviated payload uses integer cents and minutes. Its constructor accepts
raw values so it can reject coercible floats and numeric strings explicitly:

```php
namespace App\Ledgers\VehicleEquity\Payloads;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\HasMoneyAmount;
use InvalidArgumentException;

final readonly class HourlyUseRecorded implements DeserializablePayload, HasMoneyAmount
{
    public const string TYPE = 'vehicle:hourly-use';

    public int $amountInCents;
    public string $currency;
    public int $minutes;
    public string $workOrderId;
    public string $accountId;
    public string $useDate;

    public function __construct(
        mixed $amountInCents,
        mixed $currency,
        mixed $minutes,
        mixed $workOrderId,
        mixed $accountId,
        mixed $useDate,
    ) {
        if (! is_int($amountInCents) || ! is_int($minutes)) {
            throw new InvalidArgumentException('Amount and minutes must be integers.');
        }

        foreach (compact('currency', 'workOrderId', 'accountId', 'useDate') as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('Payload strings must be non-empty.');
            }
        }

        $this->amountInCents = $amountInCents;
        $this->currency = $currency;
        $this->minutes = $minutes;
        $this->workOrderId = $workOrderId;
        $this->accountId = $accountId;
        $this->useDate = $useDate;
    }

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
            'minutes' => $this->minutes,
            'use_date' => $this->useDate,
            'work_order_id' => $this->workOrderId,
        ];
    }

    public static function fromPayload(array $data): static
    {
        return new self(
            amountInCents: $data['amount_in_cents'] ?? null,
            currency: $data['currency'] ?? null,
            minutes: $data['minutes'] ?? null,
            workOrderId: $data['work_order_id'] ?? null,
            accountId: $data['account_id'] ?? null,
            useDate: $data['use_date'] ?? null,
        );
    }
}
```

### 5.2 Required snapshot projector

The hourly-use snapshot defines what was charged and is part of the successful
posting. It uses the supplied connection and is registered as a required entry
projector:

```php
namespace App\Projectors;

use App\Ledgers\VehicleEquity\Payloads\HourlyUseRecorded;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\Projector;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;

final class HourlyUseSnapshotProjector implements Projector
{
    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
        Connection $connection,
    ): void {
        if (! $payload instanceof HourlyUseRecorded) {
            return;
        }

        $connection->table('hourly_use_snapshots')->insert([
            'transaction_id' => $transaction->id,
            'operation_id' => $transaction->operation_id,
            'vehicle_id' => $transaction->ledger_id,
            'work_order_id' => $payload->workOrderId,
            'account_id' => $payload->accountId,
            'minutes' => $payload->minutes,
            'amount_cents' => $payload->amountInCents,
            'currency' => $payload->currency,
            'use_date' => $payload->useDate,
            'recorded_at' => $transaction->system_date,
            'actor' => $context->actor,
        ]);
    }
}
```

### 5.3 Replayable monthly summary

The monthly summary is disposable and derives every value from ledger history.
It is invoked only through `rebuildProjection()` or the Artisan command:

```php
namespace App\Projectors;

use App\Ledgers\VehicleEquity\Payloads\HourlyUseRecorded;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\ReplayableProjector;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;

final class VehicleMonthlyEquitySummaryProjector implements ReplayableProjector
{
    public function resetProjection(
        string $ledgerType,
        Connection $connection,
    ): void {
        $connection->table('vehicle_monthly_equity_summary')
            ->where('ledger_type', $ledgerType)
            ->delete();
    }

    public function projectHistorical(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        LedgerOperation $operation,
        Connection $connection,
    ): void {
        if (! $payload instanceof HourlyUseRecorded) {
            return;
        }

        $key = [
            'ledger_type' => $transaction->ledger_type,
            'vehicle_id' => $transaction->ledger_id,
            'month' => substr($payload->useDate, 0, 7),
        ];

        $connection->table('vehicle_monthly_equity_summary')
            ->updateOrInsert($key, ['amount_cents' => 0]);

        $connection->table('vehicle_monthly_equity_summary')
            ->where($key)
            ->increment('amount_cents', $payload->amountInCents);
    }
}
```

The unused operation parameter remains available for projectors whose output
depends on operation kind or metadata. Implementations must not mutate the
operation or transaction models.

---

## 6. Implementation sequence

Implement the specification in these phases:

1. **Reconcile the partial payload implementation**
   - Inject the `PayloadRegistry` singleton correctly into `Abacus` and update
     direct construction in tests.
   - Tighten `LedgerPayload::jsonSerialize()` to its documented array return,
     update `GenericPayload`, and require registry classes to implement
     `DeserializablePayload`.
   - Load the `payloads` configuration and enforce idempotent/conflicting
     registration behavior.
   - Rename the coordinator helper to `deserializePayload()` and update the
     facade annotations.
   - Remove `Ledger::deserialize()` and migrate fixtures to registry mappings.
2. **Complete serialization and financial validation**
   - Share one canonical normalizer between persistence and fingerprints.
   - Validate `HasMoneyAmount` currency format and reject unsupported serialized
     values without coercion.
3. **Add required projections**
   - Add the three synchronous projection contracts and per-ledger registries.
   - Return persisted transaction models from the persistence step, update final
     heads, then invoke projectors in the specified order.
   - Preserve exceptions and the existing outer-transaction behavior.
4. **Add full read-projection rebuilds**
   - Add the independent `ReplayableProjector` contract.
   - Implement transactional reset and canonical keyset traversal.
   - Add `rebuildProjection()` and `abacus:projection:rebuild` with production
     confirmation and `--force` support.
5. **Publish the completed capability**
   - Update the README and the bundled Boost skill.
   - Mark this guide implemented only after all acceptance tests pass.

---

## 7. Acceptance criteria

### Typed payloads

- A configured or imperatively registered type hydrates through the singleton
  registry in aggregate reads, corrections, and rebuilds.
- Repeating an identical registration succeeds; a conflicting registration
  fails without replacing the original mapping.
- An unknown type becomes `GenericPayload` with its type and raw values intact.
- Canonical persistence and fingerprinting produce identical JSON for
  semantically identical associative arrays while retaining list order and
  scalar types.
- Financial hydration rejects float and numeric-string amounts. Abacus rejects
  lowercase, malformed, or missing currency codes for `HasMoneyAmount`.

### Required projections

- A successful append writes its required projection rows before returning.
- Entry projectors run in operation order and operation projectors receive the
  complete ordered collection, including mixed-stream operations.
- Projectors observe every new transaction and the final affected stream-head
  versions.
- A projector exception leaves no new operation, transactions, head advances,
  or projection rows.
- A matching idempotent request returns the existing result without invoking
  projectors again.
- Registration through an instance, container class string, and
  `HasProjectors` works without duplicate invocation.
- A test using an alternate configured connection proves projection writes and
  ledger writes share the same rollback boundary.

### Replayable projections

- A rebuild resets the read model and processes only the selected canonical
  ledger type in `system_date`, `operation_id`, `operation_position` order.
- Small chunk sizes produce the same result as a single large chunk and do not
  change ordering.
- A successful rebuild returns the number of transactions presented to the
  projector and produces the expected relational dataset.
- Failure during reset, hydration, or historical projection restores the
  previously committed read model and propagates the exception.
- The command validates arguments and contract implementation, reports success,
  returns non-zero on failure, confirms destructive work in production, and
  honors `--force`.
- No ordinary append invokes a `ReplayableProjector` or performs replay-specific
  work.

All behavior must pass the package's SQLite suite and PostgreSQL suite. Final
validation uses `composer test`, `composer test:pg`, and `composer build`.
