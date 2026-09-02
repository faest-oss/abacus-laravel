# Developer Guide: Safe Stream Heads, Locking, and Versioning

## Purpose

This guide defines the write protocol for item 1 in the Motor Equipment
supplement: safe stream heads, locking, and versioning.

Abacus already has the foundation for this capability. The
`ledger_transaction_type_id` table gives every `(ledger_type, ledger_id)` pair
a row that can be locked even before its first transaction exists. The
implementation should retain that mechanism, rename the table to
`ledger_stream_head`, and make the row an explicit, versioned representation
of a stream head.

This is package infrastructure. A consuming ledger should not need to create
lock rows, choose lock order, assign versions, or retry invariant checks
itself.

## Required Guarantees

For each stream identified by `(ledger_type, ledger_id)`, Abacus must guarantee:

1. At most one stream-head row exists.
2. A stream with no committed transactions has version `0`.
3. Each committed transaction advances the version by exactly one.
4. Each transaction records the stream version assigned to it.
5. The head version equals the greatest committed transaction version.
6. Versions never decrease or get reused after a commit.
7. A rollback restores both the head and transaction state, so a failed write
   does not leave a version gap.
8. Invariants are evaluated only after the relevant stream heads are locked.
9. Every multi-stream writer acquires locks in the same global order.

A version is the position of a transaction in one stream. It is not an
operation version, an aggregate version, or a count shared across correlated
streams. A reversal, adjustment, or transfer leg is a new transaction and
therefore consumes a version in its own stream.

Stream-head rows are permanent coordination records. Abacus must not expose an
API that deletes them, and foreign keys must not cascade their deletion.

## Schema

Because Abacus is still pre-release, update the baseline package migration
rather than introduce an upgrade migration. The head table must be created
before the transaction table so the transaction table can reference it.

```php
Schema::create('ledger_stream_head', function (Blueprint $table) {
    $table->string('ledger_type');
    $table->string('ledger_id');
    $table->bigInteger('version')->default(0);

    $table->primary(['ledger_type', 'ledger_id']);
});

Schema::create('ledger_transaction', function (Blueprint $table) {
    // Existing transaction columns...
    $table->bigInteger('stream_version');

    $table->unique([
        'ledger_type',
        'ledger_id',
        'stream_version',
    ]);

    $table->foreign(['ledger_type', 'ledger_id'])
        ->references(['ledger_type', 'ledger_id'])
        ->on('ledger_stream_head')
        ->restrictOnDelete();
});
```

Use a signed `bigInteger` for portability. Application code must reject
negative expected versions, and only Abacus may assign `stream_version`.

The unique transaction constraint is required even though the head lock should
make duplicates impossible. It converts an implementation error into a failed
transaction instead of a corrupted stream.

## Write Protocol

All posting paths, including `post`, `void`, and `transfer`, must use the same
protocol.

### 1. Begin or join the transaction

Head creation, locking, invariant evaluation, transaction inserts, head
updates, and required domain writes must occur in one database transaction.
This allows a consumer to wrap an Abacus write and its projections or workflow
records in a larger Laravel `DB::transaction()` call.

Do not create heads before the encompassing transaction. An unsuccessful
operation should not leave a head behind.

### 2. Canonicalize the affected streams

Represent every affected stream as a `(ledger_type, ledger_id)` tuple. Remove
duplicates, then sort tuples with `strcmp` by:

1. `ledger_type`;
2. `ledger_id` when the types are equal.

Do not sort only by ledger ID. That works for the current same-type transfer
but does not provide a global order for future cross-type bundles.

### 3. Create missing heads safely

For each sorted tuple, issue a database-native insert-or-conflict operation
inside the transaction. Insert only the identity columns and allow `version`
to take its default of `0`. On a conflict, the operation may perform a no-op
update of an identity column, but it must not update `version`.

The current call to Laravel's two-argument `upsert` cannot simply be extended
with a `version` value. With no explicit update-column list, Laravel uses every
inserted column as an update target; a duplicate head could therefore be reset
to version `0`.

Create heads one at a time in canonical order. A unique or primary constraint
on `(ledger_type, ledger_id)` makes concurrent first-stream creation race-safe,
while the common order prevents two multi-stream operations from creating
their missing heads in opposite orders.

### 4. Lock all heads

Select every head with `lockForUpdate()` in the same canonical order. Verify
that every requested head was returned. Do not read history, evaluate an
invariant, or append any transaction until all required heads are locked.

An operation may call an internal append routine more than once after locking
its complete stream set. That routine must not acquire locks in a new order.

### 5. Check optimistic concurrency

An expected version is optional:

- `null` means "serialize this write but accept the current version".
- `0` means "write only if this stream is empty".
- A positive integer means "write only if this is the current head version".

Compare expected versions after acquiring every lock and before evaluating
invariants or inserting transactions. If any stream differs, throw
`UnexpectedStreamVersionException` and roll back the complete operation. The
exception must expose the ledger type, ledger ID, expected version, and actual
version.

Reject negative expected versions before performing database work.

### 6. Rebuild and validate under the lock

Load transactions in ascending `stream_version` order. This ordering must be
used by both public aggregate reads and the internal aggregate used for
invariant enforcement. ULID order is no longer the authoritative stream order.

The aggregate read used by a writer must occur after locking. A public,
read-only aggregate query does not need to lock, but it represents a snapshot
that may become stale immediately after it returns.

### 7. Append and advance

For each new transaction in a stream:

1. Calculate `nextVersion = currentVersion + 1` from the locked head.
2. Assign `nextVersion` to `ledger_transaction.stream_version`.
3. Insert the immutable transaction.
4. Set the head version to `nextVersion`.
5. Use `nextVersion` as the current version for any further entry appended to
   that stream by the same operation.

The transaction insert and head update are part of the same database
transaction. A multi-entry operation may update each head once to its final
version, provided it assigns consecutive transaction versions in append order
and keeps the head locks until commit.

Do not automatically retry deadlocks or lock timeouts. A safe general retry
policy requires the idempotency support described by item 4 of the supplement.

## Public API

Expose optimistic concurrency without requiring it for ordinary writes. Add
optional arguments at the end of the existing method signatures:

```php
public function post(
    string $ledgerId,
    LedgerPayload $payload,
    string $reason,
    CarbonImmutable $effectiveAt,
    ?int $expectedVersion = null,
): Transaction;

public function void(
    string $id,
    string $reason,
    ?CarbonImmutable $effectiveAt = null,
    ?int $expectedVersion = null,
): Transaction;

public function transfer(
    LedgerPayload $payload,
    string $sourceLedgerId,
    string $destinationLedgerId,
    CarbonImmutable $effectiveAt,
    string $reason,
    ?int $expectedSourceVersion = null,
    ?int $expectedDestinationVersion = null,
): LedgerTransferResult;

public function streamVersion(string $ledgerId): int;
```

`streamVersion()` returns `0` when no head exists and must not create a head as
a side effect. Its result is suitable for a later optimistic write, but another
writer may advance the stream before that write begins.

Add the assigned version to the existing result DTO:

```php
final readonly class Transaction
{
    public function __construct(
        public string $id,
        public int $streamVersion,
    ) {}
}
```

`LedgerTransferResult` already contains the source and destination
`Transaction` values, so their independent versions require no additional
top-level fields.

## Implementation Shape

Keep the protocol in package-owned code and use Laravel's database APIs. A
ledger implementation supplies identities, payload behavior, and invariants;
it must not manipulate stream heads directly.

Initially, focused private methods on `AbstractLedger` are sufficient for:

- normalizing stream tuples;
- ensuring heads exist;
- locking heads;
- validating expected versions; and
- assigning the next version.

Extract a dedicated internal coordinator only when the cross-ledger bundle API
from item 3 needs to share this behavior. Do not introduce a public repository
or driver abstraction solely for this feature.

Remove the duplicate history query currently performed before
`getAggregate()`. The invariant path should perform one replay, under the head
lock, ordered by `stream_version`.

## Failure Semantics

- A version mismatch throws `UnexpectedStreamVersionException`; it does not
  append, increment, or partially commit another stream in the operation.
- An invariant failure leaves all head versions and histories unchanged.
- A duplicate stream-version constraint failure is an internal consistency
  error and rolls back the operation.
- Database deadlocks and lock timeouts remain database exceptions until an
  idempotent retry protocol exists.
- Two entries written to the same stream in one operation receive consecutive
  versions in the operation's append order.

## Test Plan

### Schema and sequential behavior

- The first append creates a head, writes transaction version `1`, and leaves
  the head at version `1`.
- Sequential appends receive versions `1`, `2`, and `3`.
- `streamVersion()` returns `0` for an unknown stream without creating a row.
- Aggregate replay uses `stream_version`, not insertion time or ULID order.
- The database rejects a duplicate `(ledger_type, ledger_id, stream_version)`.

### Optimistic concurrency and rollback

- Expected version `0` succeeds for a new stream.
- A stale expected version throws the typed exception with all four context
  values and performs no write.
- A `null` expected version appends against the locked current version.
- An invariant failure leaves the previous head version unchanged.
- Rolling back an outer domain transaction removes its transactions and head
  advancement; a newly created head is also rolled back.
- A reversal receives the next version in the original transaction's stream.

### Multi-stream behavior

- A transfer advances source and destination versions independently.
- A failure or expected-version mismatch on either transfer stream rolls back
  both legs and both head updates.
- Duplicate stream inputs are locked once.
- Operations receiving streams in opposite caller order acquire them in the
  same canonical order.

### Real concurrency

Use independent processes or database connections for concurrency tests. A
first-write test should define an invariant that permits only one initial
entry, release two writers simultaneously with expected version `0`, and
assert that exactly one commits while the other receives a version mismatch.

A second test should start opposing multi-stream operations and assert that
they complete without acquiring the streams in opposite order.

The current in-memory SQLite suite can verify schema, version assignment,
rollback, and API behavior, but it cannot prove row-level locking. SQLite
serializes writes coarsely and Laravel's `lockForUpdate()` does not provide the
same semantics there as on a row-locking database. Run the concurrency suite
against each database Abacus claims to support. DB2 acceptance is required
before advertising DB2-safe locking; its driver-specific conflict SQL and lock
behavior must be verified rather than inferred from SQLite.

## Completion Criteria

Item 1 is complete when every write path uses this protocol, versions are
observable through the public results, stale writers fail predictably, and the
first-entry race is covered by a real concurrent database test. The stream
head then becomes the foundation for later atomic bundles, idempotency, and
snapshots without making any of those later capabilities part of this change.
