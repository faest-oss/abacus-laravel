# Implementation Guide: Corrections and Operation Views

Status: Implemented specification

## 1. Purpose and agreed semantics

This guide defines the implementation of priority #5, exact reversal and
replacement semantics, from the [Motor Equipment supplement](abacus-motor-equipment-supplement.md).
It describes the package's implemented correction and operation behavior.

Abacus will provide explicit reversal, replacement, and adjustment
relationships, backed by atomic operations. Consumers can inspect either a
higher-level operation or its individual ledger entries.

| Concept | Required behavior |
| --- | --- |
| Reversal | Append the ledger-defined exact opposing payload, linked to its target. |
| Replacement | Atomically reverse an entry and append its replacement in the same ledger type and stream. |
| Adjustment | Append a domain-supplied delta linked to an existing entry. Multiple adjustments are allowed. |
| Operation | An immutable grouping of entries written and validated together. |
| Correlation | An optional association spanning one or more operations; it does not define an atomic boundary. |

The following decisions are fixed:

- An entry can have at most one full reversal.
- Reversal entries cannot be reversed or replaced.
- Reversing an original entry leaves its adjustments untouched.
- A replacement entry can subsequently be replaced, forming an explicit chain.
- Aggregate invariants apply to each affected stream's completed operation.
- Entry validity and relationship validity apply to every individual entry.
- Idempotency keys identify whole operations and are unique across the Abacus
  store on the selected connection.
- Motor Equipment owns correction eligibility, AP disposition, and domain
  workflow records.

## 2. Storage and public interfaces

### Operation records

Add an immutable `ledger_operation` table containing:

- Toolkit-generated ULID primary key.
- Operation kind, derived from the staged actions: posting, reversal,
  replacement, adjustment, transfer, operation reversal, or composite.
- Actor, reason, event date, accounting date, optional correlation ID, and
  context metadata.
- Toolkit-assigned `system_date`, retaining the existing name for recorded time.
- Nullable, unique idempotency key and a versioned request fingerprint.
- Nullable `reverses_operation_id`, identifying the original operation when
  using `reverseOperation()`.

Insert the operation and its entries in the same database transaction. Do not
maintain a separately committed pending operation or mutable completion status.

Use one toolkit-assigned recorded timestamp for the operation and all its
entries. This is recorded time, not a claim about exact database commit order.

### Entry records

Extend `ledger_transaction` with:

- Required `operation_id`, referencing its operation.
- Required, one-based `operation_position`, defining entry order across all
  participating streams.
- Nullable `replaces_transaction_id`.
- The existing reversal and adjustment columns, fully wired into the write
  protocol.

Require unique `(operation_id, operation_position)`, unique non-null reversal
targets, and unique non-null replacement targets. Keep the existing
stream-version constraint.

Add restrictive foreign keys for operation membership and correction targets.
Validate ledger-type and stream compatibility in the locked write pipeline. Do
not cascade deletion through immutable history.

Each entry carries at most one correction relationship: reversal, replacement,
or adjustment. A replacement operation contains separate reversal and
replacement entries pointing to the same original.

Keep existing entry-level posting metadata for compatibility with entry
inspection, but assign it exclusively from the shared operation context. Move
idempotency storage and uniqueness from entries to operations.

### Public write APIs

`post()` accepts one ledger type, ledger ID, and payload. `postMany()` accepts a
nonempty payload list for one ledger type and ledger ID. Both create a posting
operation while returning transaction results for convenience.

Use `operation()` with `OperationBuilder` for arbitrary multi-stream work and
correction workflows:

```php
$result = Abacus::operation($context, function (OperationBuilder $operation) {
    $operation->reverse($originalId);
    $operation->post(VehicleEquityLedger::class, $vehicleId, $replacement);
});
```

`operation()` returns `OperationResult`, containing the operation ID, derived
`OperationKind`, and transactions in operation order. `OperationBuilder`
supports `post`, `postMany`, `reverse`, `replace`, `adjust`, and `transfer`.

The operation kind describes ledger semantics rather than which public method
was called:

- One or more ordinary postings produce `OperationKind::Posting`.
- One reversal, replacement, adjustment, or transfer produces its matching
  kind.
- Multiple semantic actions, or a correction mixed with ancillary postings,
  produce `OperationKind::Composite`.
- `reverseOperation()` produces `OperationKind::OperationReversal`.

Extend the `Transaction` result with `operationId` and `operationPosition`.
Extend the transfer result with `operationId`.

Add:

```php
replace(
    string $transactionId,
    LedgerPayload $replacement,
    ?PostingContext $context = null,
    ?int $expectedVersion = null,
): LedgerReplacementResult;

adjust(
    string $transactionId,
    LedgerPayload $delta,
    ?PostingContext $context = null,
    ?int $expectedVersion = null,
): Transaction;
```

`LedgerReplacementResult` exposes `operationId`, `reversalTransaction`, and
`replacementTransaction`.

The builder stages semantic intent rather than caller-constructed transaction
relationships. Abacus resolves targets and creates relationship fields through
the shared coordinator. A replacement's reversal immediately precedes its new
entry.

`reverse()` accepts an optional trailing expected version. `Transaction`
exposes `operationId` and `operationPosition`; replacement and transfer results
also expose their operation ID.

Change `reverseOperation()` to accept an **operation ID**. It reverses every
entry of that operation together, or fails entirely. Reject an operation
containing reversal entries or any entry already reversed; never silently
select only an eligible subset.

An empty `postMany()` or `operation()` call throws `EmptyOperationException`
and creates no operation.

## 3. Validation and atomic write protocol

### Separate entry validation from aggregate invariants

Replace the current combined ledger hook with:

```php
public function assertValidPayload(LedgerPayload $payload): void;

public function assertAggregateInvariants(
    array|JsonSerializable $aggregate,
): void;
```

Retain `initializeAggregate()`, `applyToAggregate()`, `deserialize()`, and
`computeOpposing()`.

Payload validation checks individual entry requirements. Aggregate invariants
receive the final state after all proposed entries for that stream have been
applied.

Reducers must be deterministic and capable of representing intermediate states
that violate final balance rules. They must not enforce those balance rules
while reducing.

For example:

```text
Existing entries:       +100, −80
Current balance:              20
Replacement entries:    −100, +120
Intermediate balance:        −80
Completed balance:            40
```

This replacement succeeds when the ledger requires a nonnegative completed
balance. A replacement ending below zero fails atomically.

### Correction validation

Resolve all ledger identifiers to their canonical ledger type before grouping
streams, checking relationships, or fingerprinting requests.

Under the affected stream locks:

- Verify that each correction target exists and belongs to the same canonical
  ledger type and stream.
- Reject reversal or replacement of a reversal entry.
- Reject targets already reversed, including duplicates staged within the
  current operation.
- Compute reversal payloads through the target ledger's `computeOpposing()`.
- Require every replacement entry to have exactly one matching reversal in the
  same operation.
- Reject adjustment relationships targeting reversal entries.

Adjustment eligibility beyond these structural checks remains domain policy,
including whether adjustments to already reversed entries are permitted.

Correction targets must already exist before the operation. Arbitrary
references to other newly staged entries are outside this version.

Provide an optional `CorrectionPolicy` contract implemented by ledgers needing
additional restrictions. Its validation context includes the correction kind,
original transaction, proposed entries, posting context, and before/after
stream aggregates. Invoke it under the stream locks after planning. It may
reject an operation but cannot relax package guarantees or perform workflow
writes.

### Coordinator sequence

All public writers use one coordinator:

1. Normalize request intent, canonical ledger identities, and expected versions.
2. Begin or join the selected connection's database transaction.
3. Resolve operation idempotency before correction eligibility checks.
4. Resolve all affected streams, including correction targets; create missing
   heads safely.
5. Lock every head in canonical ledger-type and ledger-ID order.
6. Check expected versions against the heads at the start of the operation.
7. Rebuild each affected aggregate once.
8. Validate relationships and payloads, compute opposing entries, and stage all
   entries in memory.
9. Apply correction policies and check every final stream aggregate.
10. Persist operation membership, ordered entries, and final head versions.
11. Commit, or return control to the encompassing domain transaction.

Each entry still advances its stream version by one. Failed writes leave no
operation, entries, or advanced versions.

Two separate Abacus calls inside one outer Laravel transaction remain separate
operations with separate invariant checks. Use one `operation()` call when all
entries must participate in the same validation boundary.

Domain workflow records may be written in the same outer transaction and
connection. A returned result remains provisional until that outer transaction
commits.

### Operation idempotency

Fingerprint normalized request intent, including operation kind, ordered
actions, targets, supplied payloads, actor, dates, reason, metadata, and
caller-supplied correlation.

Exclude toolkit-generated identifiers, recorded time, generated correlation,
and expected-version preconditions. A successful replay returns the original
result even when the original version precondition is now stale.

Fingerprint reversal intent by its target; do not require recomputing an
opposing payload to recognize a previously committed request.

Use canonical serialization that sorts object keys while preserving list order
and scalar types.

Claim keyed operations through the database unique constraint before acquiring
stream locks. Perform an ordinary insertion within a savepoint; on a conflict
specifically involving the operation idempotency key, roll back that savepoint
and load the existing operation. Compare its fingerprint and either return its
ordered results or throw `IdempotencyConflictException`. Propagate unrelated
database failures.

This ordering also handles simultaneous requests using the same key but naming
different streams. A failed operation rolls back its key claim.

Update idempotency exceptions to identify the store-scoped key and existing
operation. Introduce typed correction failures with a reason code and target
ID; aggregate failures identify the stream and operation's proposed entries
rather than arbitrarily blaming one draft.

## 4. Operation and entry read views

These are package query interfaces and relationships; this change does not add
a frontend or HTTP routes.

Add:

- `findOperation($operationId)`: return the immutable operation with all entries
  ordered by operation position.
- `operationsForStream($ledgerType, $ledgerId)`: return an operation query
  filtered by stream membership, ordered by each operation's ending version in
  that stream. Paginate operations before loading their complete entry groups.
- `getAggregateAtOperation($ledgerType, $ledgerId, $operationId)`: replay the
  stream through that operation's final stream version.

The historical aggregate method requires the operation to touch the requested
stream. Reject an unknown or unrelated operation rather than infer a
cross-stream timeline.

Expose operation and correction relationships on transaction models so
existing entry queries can navigate to the higher-level view.

| Operation view | Entry view |
| --- | --- |
| Shows the operation kind, context, and complete membership. | Shows each payload, relationship, position, and stream version. |
| Presents a replacement as one correction. | Shows the exact opposing and replacement entries. |
| Uses completed operation boundaries for aggregate state. | Preserves intermediate steps for audit inspection. |

Do not introduce a generic monetary net-change column. Abacus payloads and
aggregates are arbitrary; consuming domains calculate and format balances,
currency, and descriptions.

For multi-stream operations, the stream query identifies relevant operations
while operation detail includes every participating stream. No global ordering
is inferred from ULIDs or recorded timestamps.

Entry versions identify sequence positions. Intermediate versions inside an
operation are not supported standalone aggregate or snapshot boundaries.
Future temporal queries and snapshots must retain this distinction.

Event/accounting-date filtering, cross-stream historical snapshots, and
snapshot storage remain priority #7 work. This guide establishes their
operation-boundary requirement without implementing those broader
capabilities.

## 5. Implementation sequence and acceptance

Use the agreed pre-release approach: update the baseline migration and ledger
contracts directly. Do not add legacy-data backfills or compatibility adapters.
Adoption requires recreating development schemas and updating ledger
implementations.

Implement in this order:

1. Operation schema, immutable model, entry membership, and operation-aware
   results.
2. Shared coordinator and operation-level idempotency.
3. Split ledger validation hooks and final-aggregate checks.
4. Correction relationships, helpers, policy hook, and typed failures.
5. Operation queries and historical operation-boundary aggregates.
6. Documentation, facade annotations, fixtures, and consumer examples.

Keep the existing service provider shape, `abacus-*` publish tags, Composer
constraints, and package namespace. Add no dependencies.

The earlier [stream-head](safe-stream-heads-developer-guide.md) and
[multi-entry](multi-entry-multi-stream-operations.md) guides describe per-entry
invariant checks and correlation-based grouping. Add explicit supersession
notes linking to this guide. README and the bundled Boost skill document the
implemented public integration surface.

Acceptance tests must demonstrate:

- Exact reversal succeeds; forged payloads, missing targets, incompatible
  streams, duplicate reversals, and reversal-of-reversal fail.
- Replacement produces two explicitly related entries in one operation,
  supports replacement chains, and rolls back both entries on failure.
- Reversing an adjusted original leaves its adjustments intact.
- Multiple adjustments succeed unless the ledger policy rejects them.
- The temporary-negative replacement example succeeds; an invalid final
  aggregate fails.
- Failure in any participating stream rolls back every entry, operation record,
  retry claim, and head advancement.
- All write paths, including dedicated helpers and composed operations, enforce
  identical correction rules.
- Identical keyed retries return the original operation and ordered entry IDs;
  changed targets, payloads, order, or context conflict.
- Concurrent reversals yield one committed reversal; concurrent replacements
  yield one complete replacement.
- Concurrent identical retry keys return one committed operation; conflicting
  requests using the same key never both commit.
- Operation pagination does not split groups, reused correlations do not merge
  operations, and multi-stream detail retains all members.
- Historical aggregates stop at the requested operation boundary.
- An outer domain rollback removes both Abacus and domain writes.

Use focused Pest/Testbench tests during implementation, followed by
`composer test`. Run real concurrency acceptance through the existing
PostgreSQL harness and `composer test:pg`; SQLite behavior tests alone do not
establish locking guarantees.

Preserve the repository's PHP/Laravel/Testbench and OS validation matrix. Do
not extend database-support claims, including DB2 support, without
platform-specific verification.
