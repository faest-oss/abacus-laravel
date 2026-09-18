# Implementation Guide: Temporal Queries and Aggregates

Status: Implemented.

## 1. Purpose and agreed semantics

This guide defines priority #7 from the
[Motor Equipment supplement](abacus-motor-equipment-supplement.md): temporal
transaction queries, temporal aggregate reconstruction, and optional aggregate
snapshots.

Abacus records three independent timelines on every transaction:

| Timeline | Stored column | Question answered |
| --- | --- | --- |
| Operational | `event_date` | When did the domain event take effect? |
| Accounting | `accounting_date` | When did the transaction count financially? |
| Recorded | `system_date` | When did Abacus know about the transaction? |

A temporal view applies inclusive upper bounds to those timelines. It does not
change transaction order. Abacus always reduces selected entries in
`stream_version ASC` order, which is the same order used by current aggregate
reconstruction and invariant enforcement.

The following views are intentionally different:

- **Operational result known now:** apply an event-date cutoff without a
  recorded-time cutoff.
- **Accounting result known now:** apply an accounting-date cutoff without a
  recorded-time cutoff.
- **What was known then:** add a recorded-time cutoff to either view.
- **Current state:** apply no temporal cutoff.

For example, an hourly-use charge may have an event date of January 15, be
recorded on January 20, and be corrected on February 10 with a backdated event
date of January 15. An event view through January 31 using everything known now
includes the correction. The same event view known as of January 31 excludes
the February 10 correction.

All supplied cutoffs combine with `AND`. A transaction is visible only when it
satisfies every non-null cutoff:

```text
(eventThrough is null OR event_date <= eventThrough)
AND (accountingThrough is null OR accounting_date <= accountingThrough)
AND (recordedThrough is null OR system_date <= recordedThrough)
```

Cutoffs do not infer ordering from dates or ULIDs. Two operations may share the
same recorded timestamp. An inclusive recorded cutoff includes both when both
timestamps equal the cutoff. Callers needing an exact stream boundary use
`getAggregateAtOperation()`.

### 1.1 Correction status is view-relative

A visible non-reversal transaction is reversed in a temporal view only when a
visible transaction has `reverses_transaction_id` pointing to it. A reversal
recorded after the view's recorded cutoff does not make the original appear
reversed in that earlier view.

Reversal entries are correction artifacts rather than unreversed originals.
They are excluded from both reversal-status values and remain discoverable
through `CorrectionRelationship::Reversal`.

Replacement operations already contain a separate reversal entry, so their
targets become reversed through that relationship. Adjustments and replacement
entries do not independently mark their targets reversed.

Abacus does not add dependency closure when applying date filters. If a domain
assigns a correction an event or accounting date that makes the correction
visible while its target is not visible, the view contains only the entries
selected by its cutoffs. Domains that prohibit that result enforce compatible
dates in their ledger or correction policy.

### 1.2 Completed-operation boundaries

All entries in one operation receive the same posting context and toolkit-owned
recorded time. A temporal cutoff therefore cannot split entries from one
operation by event, accounting, or recorded date.

Within a stream, an operation may append more than one entry. Only the final
stream version contributed by that operation is a supported explicit aggregate
or snapshot boundary. Intermediate stream versions remain available for audit
inspection but not as public aggregate boundaries.

### 1.3 V1 boundaries

V1 deliberately excludes:

- Lower-bound date ranges and arbitrary temporal predicates in aggregate APIs.
- One aggregate spanning multiple streams or ledger types.
- Period closing policy or automatic accounting-date resolution.
- Automatic snapshot creation during ordinary writes.
- Snapshot queues, distributed snapshot workers, or snapshot retention policy.
- HTTP routes or serialized API response formats for temporal reads.
- Claims of database support beyond the package's verified matrix.

Transaction queries remain Eloquent builders, so applications may add ordinary
query constraints for reporting. Only the package-defined criteria participate
in view-relative reversal status and aggregate semantics.

---

## 2. Temporal value objects and transaction queries

### 2.1 Temporal view

Use one immutable value object for temporal cutoffs shared by transaction
queries, aggregate reconstruction, and snapshot selection:

```php
namespace Faest\Abacus\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class TemporalView
{
    public function __construct(
        public ?CarbonImmutable $eventThrough = null,
        public ?CarbonImmutable $accountingThrough = null,
        public ?CarbonImmutable $recordedThrough = null,
    ) {}

    public static function current(): self
    {
        return new self;
    }

    public static function eventAsOf(
        CarbonInterface $eventThrough,
        ?CarbonInterface $knownAt = null,
    ): self {
        return new self(
            eventThrough: CarbonImmutable::instance($eventThrough),
            recordedThrough: $knownAt === null
                ? null
                : CarbonImmutable::instance($knownAt),
        );
    }

    public static function accountingAsOf(
        CarbonInterface $accountingThrough,
        ?CarbonInterface $knownAt = null,
    ): self {
        return new self(
            accountingThrough: CarbonImmutable::instance($accountingThrough),
            recordedThrough: $knownAt === null
                ? null
                : CarbonImmutable::instance($knownAt),
        );
    }

    public function knownAt(CarbonInterface $recordedThrough): self
    {
        return new self(
            eventThrough: $this->eventThrough,
            accountingThrough: $this->accountingThrough,
            recordedThrough: CarbonImmutable::instance($recordedThrough),
        );
    }
}
```

Named constructors convert inputs to `CarbonImmutable`. The public constructor
allows a caller to combine event and accounting cutoffs when both are required.
`current()` is equivalent to an instance containing three null cutoffs.

Dates retain their supplied instant. Abacus does not infer a domain timezone,
truncate to a calendar day, or expand a date to end-of-day. Callers choose the
exact inclusive timestamp appropriate to their domain.

### 2.2 Transaction criteria

Transaction-only filters are composed around the shared temporal view:

```php
namespace Faest\Abacus\Enums;

enum CorrectionRelationship: string
{
    case Reversal = 'reversal';
    case Replacement = 'replacement';
    case Adjustment = 'adjustment';
}

enum ReversalStatus: string
{
    case Reversed = 'reversed';
    case Unreversed = 'unreversed';
}
```

```php
namespace Faest\Abacus\Data;

use Faest\Abacus\Enums\CorrectionRelationship;

final readonly class CorrectionFilter
{
    public function __construct(
        public CorrectionRelationship $relationship,
        public ?string $targetTransactionId = null,
    ) {}
}
```

```php
namespace Faest\Abacus\Data;

use Faest\Abacus\Enums\ReversalStatus;

final readonly class TransactionCriteria
{
    public function __construct(
        public TemporalView $view = new TemporalView,
        public ?string $operationId = null,
        public ?string $correlationId = null,
        public ?CorrectionFilter $correction = null,
        public ?ReversalStatus $reversalStatus = null,
    ) {}
}
```

An empty operation ID, correlation ID, or correction target is invalid. A null
correction target means “all entries with this relationship.” A supplied target
filters the relationship's corresponding target column exactly.

### 2.3 Stream transaction query

Add the coordinator and facade API:

```php
/** @return Builder<LedgerTransaction> */
public function transactionsForStream(
    string $ledgerType,
    string $ledgerId,
    ?TransactionCriteria $criteria = null,
): Builder;
```

The method resolves the canonical ledger type, restricts results to that
stream, applies the criteria, and returns an Eloquent builder ordered by
`stream_version ASC`. It does not create or lock a stream head.

Criteria map as follows:

| Criterion | Query behavior |
| --- | --- |
| Temporal view | Inclusive `<=` predicates for each non-null cutoff. |
| Operation ID | Exact `operation_id` match. |
| Correlation ID | Exact transaction `correlation_id` match. |
| Reversal relationship | `reverses_transaction_id` is non-null or equals the target. |
| Replacement relationship | `replaces_transaction_id` is non-null or equals the target. |
| Adjustment relationship | `adjusts_transaction_id` is non-null or equals the target. |
| Reversed status | A qualifying reversal exists inside the same stream and temporal view. |
| Unreversed status | The row is not a reversal entry and no qualifying reversal exists inside the same stream and temporal view. |

The reversal-status subquery applies the same temporal predicates as the outer
query. Operation, correlation, and correction filters restrict returned rows;
they do not restrict which reversal rows establish status.

`findOperation()` and `operationsForStream()` keep their existing meanings.
Callers use `findOperation()` when they need complete cross-stream membership
for a known operation rather than a stream-local transaction view.

---

## 3. Temporal aggregate reconstruction

Extend the existing aggregate API with a backward-compatible optional view:

```php
/** @return array<mixed>|JsonSerializable */
public function getAggregate(
    string $ledgerType,
    string $ledgerId,
    ?TemporalView $view = null,
): array|JsonSerializable;
```

Null and `TemporalView::current()` both reconstruct current state. The
coordinator:

1. Resolves the canonical ledger type.
2. Captures the stream's committed head version without creating or locking a
   missing head.
3. Selects an eligible snapshot when the ledger supports snapshots.
4. Queries visible transactions after the chosen starting version and no later
   than the captured head.
5. Orders them by `stream_version ASC`.
6. Hydrates each payload through `PayloadRegistry` and applies the ledger
   reducer.

Capturing the head gives one explicit upper version boundary. A writer that
commits afterward belongs to a later read. A writer that committed before the
head was read has its operation and final head visible atomically.

Temporal aggregate reads do not run completed-aggregate invariants. Historical
views may legitimately represent a state that is no longer current, and a
domain's present-day invariant cannot retroactively invalidate it.

The same internal ordered replay helper serves current reads and invariant
reconstruction. The write path calls it under the stream-head lock using the
locked version. Temporal filtering is applied only when a view requests it.

`getAggregateAtOperation()` remains unchanged. It resolves the operation's
final version in the requested stream and replays through that exact version.
It may use an eligible snapshot but does not combine an operation boundary with
date cutoffs.

### 3.1 Backdated correction example

Assume a vehicle-equity stream contains:

| Version | Entry | Event date | Accounting date | Recorded date |
| ---: | --- | --- | --- | --- |
| 1 | Hourly-use charge `+100` | Jan 15 | Jan 31 | Jan 20 |
| 2 | Exact reversal `-100` | Jan 15 | Jan 31 | Feb 10 |
| 3 | Replacement charge `+120` | Jan 15 | Jan 31 | Feb 10 |

The expected aggregates are:

| View | Result |
| --- | ---: |
| Current | `120` |
| Event through Jan 31, known now | `120` |
| Accounting through Jan 31, known now | `120` |
| Event through Jan 31, known Jan 31 | `100` |
| Accounting through Jan 31, known Jan 31 | `100` |
| Recorded through Jan 19 | Empty aggregate |

The replacement operation's two entries remain adjacent because reduction is
by stream version, not payload date.

---

## 4. Optional aggregate snapshots

Snapshots are disposable acceleration data. Transactions, operations, and
stream heads remain authoritative. Deleting every snapshot changes performance
and availability during reconstruction, not ledger meaning.

Snapshots are distinct from the required and replayable projections in the
[typed payload and projection guide](typed-payloads-and-synchronous-projections-developer-guide.md).
They contain reducer state for one stream and exist only to shorten aggregate
replay.

### 4.1 Ledger contract

Only ledgers that opt in can create or consume snapshots:

```php
namespace Faest\Abacus\Contracts;

use JsonSerializable;

interface SnapshotsAggregate
{
    /** Positive version for the consuming ledger's snapshot format. */
    public function snapshotVersion(): int;

    /**
     * @param array<mixed>|JsonSerializable $aggregate
     * @return array<mixed>
     */
    public function serializeAggregateSnapshot(
        array|JsonSerializable $aggregate,
    ): array;

    /**
     * @param array<mixed> $snapshot
     * @return array<mixed>|JsonSerializable
     */
    public function hydrateAggregateSnapshot(
        array $snapshot,
    ): array|JsonSerializable;
}
```

The format version must be greater than zero. Increment it whenever deployed
code can no longer hydrate the previous serialized representation. Abacus uses
only rows matching the ledger's current format version; older rows remain
disposable and are ignored.

Serialization must be deterministic and contain only supported canonical JSON
values. Hydration must not consult mutable domain state, the current clock,
random values, or external services.

### 4.2 Storage

Add a package-owned `ledger_snapshot` table containing:

- Toolkit-generated ULID primary key.
- Canonical `ledger_type` and `ledger_id`.
- Final `stream_version` represented by the snapshot.
- The operation ID whose final entry establishes that stream boundary.
- Positive snapshot format version.
- Canonically serialized aggregate JSON.
- Maximum `event_date`, `accounting_date`, and `system_date` folded into the
  snapshot.
- Toolkit-assigned snapshot creation timestamp used only for operations and
  cleanup, never temporal semantics.

Require uniqueness across
`(ledger_type, ledger_id, stream_version, snapshot_version)`. Add restrictive
relationships to the stream head and operation. Snapshot rows may be deleted;
ledger transactions and operations may not cascade through them.

Add composite transaction indexes supporting each stream-local temporal query:

- `(ledger_type, ledger_id, event_date, stream_version)`
- `(ledger_type, ledger_id, accounting_date, stream_version)`
- `(ledger_type, ledger_id, system_date, stream_version)`

Also index correlation and non-unique adjustment targets where the existing
constraints do not already provide an adequate index:

- `(ledger_type, ledger_id, correlation_id, stream_version)`
- `(adjusts_transaction_id)`
- `(ledger_type, ledger_id, snapshot_version, stream_version)` on snapshots
  for compatible-snapshot selection

Use Laravel schema APIs that behave consistently on SQLite and PostgreSQL.

### 4.3 Explicit snapshot creation

Expose:

```php
public function createAggregateSnapshot(
    string $ledgerType,
    string $ledgerId,
): int;
```

The method returns the represented stream version. It rejects ledgers that do
not implement `SnapshotsAggregate`. For a missing or empty stream it returns
`0` without creating a stream head or snapshot row.

For a nonempty stream, the coordinator uses the selected Abacus connection and
one database transaction to:

1. Lock the existing stream head.
2. Capture its current version.
3. Reconstruct the current aggregate through that version, using an existing
   compatible snapshot when available.
4. Canonically serialize the aggregate.
5. Calculate the maximum event, accounting, and recorded dates through the
   represented version.
6. Insert the snapshot for the operation's final stream boundary.

If the same format and stream version already exist, return that version
without rewriting the row. Serialization or insertion failure propagates and
leaves no partial snapshot. Ordinary ledger writes never create snapshots and
never fail because a snapshot is absent.

Applications may invoke this API from their own scheduled maintenance command.
V1 does not add an Abacus snapshot command, cadence configuration, or retention
automation.

### 4.4 Snapshot eligibility

Choose the highest-version snapshot that satisfies every applicable rule:

1. Its ledger type and ID match the requested stream.
2. Its format version equals `SnapshotsAggregate::snapshotVersion()`.
3. Its stream version does not exceed the captured head or explicit operation
   boundary.
4. When `eventThrough` is present, `max_event_date <= eventThrough`.
5. When `accountingThrough` is present,
   `max_accounting_date <= accountingThrough`.
6. When `recordedThrough` is present,
   `max_system_date <= recordedThrough`.

The maximum-date checks prove that every entry already folded into the snapshot
would also be visible in the requested temporal view. A later entry may still
carry an earlier domain date; Abacus evaluates all post-snapshot entries
normally and includes it when it satisfies the view.

If no snapshot is eligible, start from `initializeAggregate()` and replay the
authoritative history. Unsupported format versions are ignored. A hydration or
canonical-decoding failure propagates rather than silently returning a state
whose correctness cannot be established; deleting the damaged snapshot
restores full replay.

---

## 5. Motor Equipment usage

An operational report through June 30 using all corrections known now uses:

```php
$aggregate = $abacus->getAggregate(
    VehicleEquityLedger::class,
    (string) $vehicle->id,
    TemporalView::eventAsOf(CarbonImmutable::parse('2026-06-30 23:59:59')),
);
```

The same operational view as it was known when the report was issued uses:

```php
$view = TemporalView::eventAsOf(
    eventThrough: CarbonImmutable::parse('2026-06-30 23:59:59'),
    knownAt: CarbonImmutable::parse('2026-07-05 12:00:00'),
);
```

An accounting view uses `accountingAsOf()` instead. Motor Equipment remains
responsible for selecting the correct timezone and period boundary.

To inspect unreversed hourly-use entries as they appeared in that view:

```php
$transactions = $abacus->transactionsForStream(
    VehicleEquityLedger::class,
    (string) $vehicle->id,
    new TransactionCriteria(
        view: $view,
        reversalStatus: ReversalStatus::Unreversed,
    ),
)->get();
```

This package query does not replace Motor Equipment's domain projections or
report classifications. Those models remain appropriate for relational joins,
grouping, presentation, and Accounts Payable workflow.

---

## 6. Implementation sequence

Implement the specification in these phases:

1. **Temporal values and query predicates**
   - Add the immutable view, transaction criteria, relationship filter, and
     enums.
   - Centralize inclusive temporal predicates so outer queries and reversal
     status subqueries cannot diverge.
   - Add `transactionsForStream()` and facade annotations.
2. **Temporal aggregates**
   - Extend `getAggregate()` with the optional view.
   - Capture a head boundary and share one stream-version replay path with
     current reads, operation-boundary reads, and invariant reconstruction.
   - Continue hydrating every replayed payload through `PayloadRegistry`.
3. **Snapshot capability**
   - Add the opt-in ledger contract, baseline snapshot schema, model, and
     indexes.
   - Implement eligibility selection and explicit locked creation.
   - Keep writes independent from snapshot availability.
4. **Publish the capability**
   - Update facade annotations, README temporal examples, and the bundled Boost
     skill.
   - Mark this guide implemented only after every acceptance test passes.

Use the pre-release approach already established by earlier guides: update the
baseline package migration and public contracts directly. Do not add legacy
backfills or compatibility adapters. Add no dependency solely for temporal
query construction or snapshot storage.

---

## 7. Acceptance criteria

### Temporal queries

- Current, event, accounting, and recorded views apply inclusive cutoffs and
  combine multiple cutoffs with `AND`.
- Results contain only the selected canonical stream and remain ordered by
  stream version regardless of date order or ULID order.
- Operation, correlation, and correction relationship filters select the
  documented rows, including exact correction targets.
- Reversed and unreversed filters are evaluated against qualifying reversals in
  the same temporal view, and reversal entries themselves match neither
  status.
- Unknown streams return an empty builder result without creating a stream
  head.

### Temporal aggregates

- A backdated correction appears in a historical event or accounting view
  using knowledge available now and disappears when the recorded cutoff
  predates the correction.
- Current aggregate behavior is unchanged when the view is null or current.
- Selected entries are hydrated centrally and reduced in stream-version order.
- Aggregate reads capture a committed head boundary and never expose an
  intermediate version within an operation.
- `getAggregateAtOperation()` remains an exact final-operation boundary and
  rejects unrelated operations.
- Historical reads do not execute present-day aggregate invariants.

### Snapshots

- A snapshot can be created only for an opted-in ledger and represents the
  locked final head version of a nonempty stream.
- Snapshot-backed current and temporal aggregates exactly equal full replay,
  including after backdated corrections appended after the snapshot.
- Event, accounting, and recorded cutoffs reject any snapshot whose maximum
  folded date exceeds the corresponding cutoff.
- Unsupported snapshot format versions are ignored and full replay succeeds.
- Hydration, serialization, or insert failure propagates without changing
  ledger history or leaving a partial snapshot.
- Repeating creation at the same stream version and snapshot format version is
  idempotent.
- Ordinary appends perform no snapshot-specific writes.
- A PostgreSQL concurrency test proves snapshot creation locks the stream head
  and yields either the complete state before or after a competing operation,
  never a partial operation.

Final validation uses `composer test`, `composer test:pg`, and `composer build`.
Preserve the existing PHP 8.3+, Laravel 12/13, SQLite, PostgreSQL, and Windows
compatibility matrix. Do not extend DB2 or other database-support claims
without platform-specific tests.
