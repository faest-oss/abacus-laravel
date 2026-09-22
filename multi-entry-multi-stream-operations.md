# Developer Guide: Multi-Entry and Multi-Stream Operations

## Purpose

This guide describes Abacus's atomic operation APIs. Every write belongs to an
immutable `ledger_operation`, including a single `post()`. The API used to
construct a write controls its convenience and scope; it does not change the
transaction, locking, or invariant guarantees.

For correction relationships and operation read views, see the
[correction semantics guide](correction-semantics-developer-guide.md).

## Public API boundaries

Use `post()` for one ordinary entry:

```php
$transaction = Abacus::post(
    VehicleEquityLedger::class,
    $vehicle->id,
    new RentPosted(amount: 50000),
    $context,
    expectedVersion: 12,
);
```

Use `postMany()` for several ordinary entries in one stream. The explicit
ledger type and ID prevent a batch from silently becoming a cross-stream
operation:

```php
$transactions = Abacus::postMany(
    VehicleEquityLedger::class,
    $vehicle->id,
    [$rentEntry, $hourlyUseEntry],
    $context,
    expectedVersion: 12,
);
```

Use `operation()` with `OperationBuilder` for multiple streams or semantic
actions:

```php
use Faest\Abacus\OperationBuilder;

$result = Abacus::operation($context, function (OperationBuilder $operation) use ($source, $destination) {
    $operation->post(
        VehicleEquityLedger::class,
        $source->id,
        new TransferDebit(amount: 50000),
    );

    $operation->post(
        VehicleEquityLedger::class,
        $destination->id,
        new TransferCredit(amount: 50000),
    );
});
```

`OperationBuilder` supports `post`, `postMany`, `reverse`, `replace`, `adjust`,
`transfer`, and `transferBetween`. The latter names source and destination
ledger types separately while preserving the same atomic transfer semantics.
Builder methods stage intent only. The coordinator resolves
correction targets, computes opposing payloads, and assigns relationships under
the operation's write protocol.

An empty `postMany()` or `operation()` call throws `EmptyOperationException`.

## Required guarantees

Abacus guarantees that an operation:

1. Creates one immutable operation record and one or more ordered entries.
2. Locks every affected `(ledger_type, ledger_id)` stream in canonical order.
3. Checks optimistic versions against each stream at operation start.
4. Applies every proposed entry to in-memory aggregates before persistence.
5. Runs each affected ledger's operation policy against the locked stream and
   its existing plus proposed accounting history.
6. Validates each affected stream's completed aggregate.
7. Commits all operation records, entries, relationships, and head versions
   together, or rolls them all back.
8. Assigns one toolkit-owned recorded time and posting context to every entry.

Each entry advances its own stream version. `operation_position` records order
across the complete operation, while `stream_version` records order within one
stream.

## Operation kinds and results

`operation()` returns an `OperationResult` with the operation ID,
`OperationKind`, and ordered transactions. `post()` and `postMany()` return
transaction results directly for convenience; those transactions still expose
their operation ID and position.

Abacus derives kind from staged actions:

| Actions | Kind |
| --- | --- |
| One or more ordinary posts | `posting` |
| One reversal | `reversal` |
| One replacement | `replacement` |
| One adjustment | `adjustment` |
| One transfer | `transfer` |
| Reversal of a prior operation | `operation_reversal` |
| Multiple semantic actions or mixed correction and posting work | `composite` |

Kind represents financial protocol semantics. It is independent of whether
ordinary entries were supplied through `postMany()` or `operation()`.

## Execution pipeline

The coordinator performs these steps inside one database transaction:

```text
normalize actions and canonical ledger types
    -> claim or replay operation idempotency
    -> resolve affected streams
    -> create and lock stream heads in canonical order
    -> check starting versions
    -> rebuild each aggregate once
    -> validate and apply all entries in memory
    -> run locked operation policies
    -> validate completed stream aggregates
    -> persist operation, entries, and final head versions
```

Multiple expected versions for the same stream must agree. The value always
describes the stream head at the beginning of the operation, including when
the operation adds several entries to that stream.

Two Abacus calls inside one outer Laravel transaction remain two separate
operation and invariant boundaries. Use one `operation()` callback when entries must be
validated together. An outer transaction can include domain projections and
workflow records on the same connection; rolling it back also removes the
Abacus operation.

## Idempotency

An idempotency key identifies one operation across the selected Abacus store.
The request fingerprint includes ordered actions, targets, payloads, actor,
dates, reason, metadata, and caller-supplied correlation. It excludes generated
IDs, recorded time, generated correlation, and optimistic version
preconditions.

An identical retry returns the original operation and transaction IDs even if
its original expected version is now stale. Reusing the key with changed intent
throws `IdempotencyConflictException`.

## Validation

Use SQLite feature tests for schema, ordering, aggregate, rollback, and API
behavior. Use the PostgreSQL concurrency suite for row locks, concurrent first
writes, competing corrections, and idempotency claims. Run `composer test` for
the complete PHPStan, formatting, type coverage, and Pest validation set.
