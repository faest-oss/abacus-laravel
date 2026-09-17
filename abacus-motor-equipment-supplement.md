# Abacus Proposal Supplement: Lessons from Motor Equipment

Status: Supplemental architectural feedback  
Prepared: September 2, 2026

## Purpose

This document supplements the Abacus ledger toolkit proposal with lessons from
the Borough Portal Motor Equipment domain. It treats the Abacus proposal as the
intended direction of the package; it is not a review of the package's current,
fledgling implementation.

Motor Equipment is a useful design case because it combines two append-only
financial histories with effective-dated configuration, imports, corrections,
reporting, Accounts Payable settlement, and legacy-system integration. The goal
is to identify which of those concerns belong in a reusable ledger toolkit and
which should remain in the consuming domain.

## Conclusion

Abacus could materially simplify Motor Equipment's financial foundation, but
it would not by itself solve the primary orchestration complexity in the
domain.

Abacus should become the reusable append-only transaction kernel. It should own
the safe write protocol, immutable transaction metadata, transaction
relationships, atomic operations, concurrency control, idempotency, and temporal
query primitives. Motor Equipment should continue to own the meaning of each
operation: effective vehicle context, rate and account validation, correction
eligibility, Accounts Payable disposition, and report classification.

The intended separation is:

```text
Domain command
    -> resolve and validate domain context
    -> choose domain settlement policy
    -> append an atomic Abacus operation
    -> persist domain projections and workflow facts
    -> expose the audited result
```

Abacus should make the financial operation routine and trustworthy without
attempting to make the surrounding business process generic.

## Where Abacus Would Simplify Motor Equipment

| Motor Equipment concern | Potential benefit | Responsibility that remains in Motor Equipment |
| --- | --- | --- |
| Equity posting, reversal, adjustment, and immutability | High | Equity kinds, directions, descriptions, and report classification |
| Depreciation ledger | High | Rate schedules, book-value floor, and monthly posting policy |
| Vehicle-to-vehicle transfers | High | Authorization and transfer intent |
| Scheduled-rent posting | Moderate | Rate allocations, department segmentation, and proration |
| Hourly-use amendments and voids | Moderate | Original and replacement snapshots, correction validity, and AP disposition |
| Imports and source revisions | Moderate | Source precedence, canonicalization, and reconciliation rules |
| Reporting | Moderate when projections and temporal queries are available | Baselines, categories, grouping, and presentation |
| Accounts Payable settlement | Low | Open, ready, submitted, and cancelled batch behavior |
| Hourly-use context resolution | None | Rates, departments, work orders, accounts, timezone, and rounding |
| Frontend decomposition | None | Entirely an application concern |

Motor Equipment currently implements append, reversal, partial offset,
immutability, correlation, actor, and recorded-time behavior in its own equity
ledger. Depreciation independently repeats several of those mechanics. Equity
transfers also implement their own ordered locking, shared correlation ID, and
paired transaction. These are strong candidates for Abacus.

Scheduled rent and hourly use demonstrate the other side of the boundary. A
ledger can append their financial entries, but it cannot decide which
effective-dated department assignment applies, whether a work order and account
combination is permitted, or how a monthly allocation is prorated across a
mid-month department change.

## Corrections and Accounts Payable

An hourly-use amendment illustrates why ledger infrastructure alone does not
remove the domain's main complexity. The operation must:

1. Resolve the accepted rate, department, work order, and account for the
   replacement use.
2. Determine whether any operational or Accounts Payable-visible fact changed.
3. Reverse the original equity entry and, for an amendment, append its
   replacement.
4. Determine whether the original is unclaimed or belongs to an open, ready,
   or submitted Accounts Payable batch.
5. Replace or remove an open claim, or expose an immutable follow-up settlement
   for a frozen claim.
6. Retain the correction type, original and replacement snapshots, reason,
   actor, time, and chosen AP disposition.

Abacus can own step 3 and make it atomically safe. Steps 1, 2, 4, 5, and the
domain meaning of step 6 belong to Motor Equipment.

A unified Motor Equipment correction aggregate and a dedicated AP settlement
policy therefore remain valuable even when Abacus is used. The correction
aggregate records why the domain performed an operation and what its workflow
consequences were; the ledger records the immutable financial consequences.

Correlation is useful evidence that entries were created by one operation, but
it should not automatically imply a downstream settlement policy. Motor
Equipment should persist which correlated entries must be selected together by
Accounts Payable rather than asking Abacus to understand AP claims.

## Recommended Abacus Capabilities

### 1. First-Class Atomic Operations

Provide an operation API that supports posting one or more entries
across one or more ledger streams. An operation should:

- Generate or accept a correlation or operation ID.
- Resolve all affected ledger streams before writing.
- Lock those streams in a deterministic global order.
- Append every entry atomically.
- Return all created transaction IDs and, where supplied by the domain, their
  operation roles.
- Allow the consuming domain to write its projections and workflow records in
  the same database transaction.

This is more useful than a transfer-specific abstraction alone. The same
primitive supports transfers, reversal-and-replacement corrections, source
revision replacement, and a scheduled process that creates several related
postings.

Convenient operations may include:

- `post` for one transaction;
- `postMany` for several entries in one stream;
- `reverse` for an exact opposing transaction;
- `replace` for an atomic reversal and replacement; and
- `operation` with `OperationBuilder` for arbitrary multi-stream operations.

### 2. Safe Stream Locking and Versioning

Do not rely on locking existing transaction rows to serialize a ledger. A new
stream has no row to lock, and querying an empty history may not prevent a
concurrent first append.

Maintain a lockable stream or head record identified by
`(ledger_type, ledger_id)`. The stream record can also retain a monotonically
increasing version. This provides:

- Safe first-entry concurrency.
- Deterministic multi-stream locking.
- Optimistic concurrency when useful to a caller.
- A foundation for snapshots without making them authoritative.
- A stable target for database-level relationships and operational inspection.

Stream-head creation must itself be race-safe, and operation locking should sort
by ledger type and ledger ID before acquiring locks.

### 3. Explicit Posting Context

Each append should accept an explicit context containing:

- Event date: when the domain event occurred.
- Accounting date: when the event counts financially.
- Actor identifier.
- Reason.
- Correlation or operation identifier when applicable.
- Domain source or idempotency key when applicable.

The toolkit should exclusively assign `recorded_at`. Prefer a database-assigned
value where supported, with a clearly defined portable fallback.

The actor should be explicit in the core API. An authenticated-user resolver
can be a convenience default, but it cannot be the only mechanism: imports,
queues, scheduled processes, integration endpoints, and system users also
create legitimate ledger entries.

Requiring the domain to supply event and accounting dates is appropriate.
Resolving accounting dates and determining whether periods are open remain
domain policies that Abacus can invoke through a contract such as
`PeriodManager`.

### 4. Idempotency and Source Identity

Idempotency should be part of the write protocol rather than reimplemented by
each scheduled process or importer.

Allow a domain-supplied idempotency key with a documented uniqueness scope. A
useful default is uniqueness within ledger type and operation, although the API
should allow a ledger to choose whether the key is stream-scoped or
ledger-type-scoped.

The result of repeating the same request should be explicit: either return the
previous committed result or report a typed idempotency conflict when the same
key is presented with different content. It should never silently append a
second financial entry.

This would directly support repeat-safe scheduled rent, depreciation, imports,
and lost-response retries.

### 5. Precise Correction Semantics

Define distinct transaction relationships rather than treating reversal,
replacement, and adjustment as interchangeable:

- **Reversal:** an exact opposing entry that cancels a prior entry. A ledger may
  permit only one full reversal.
- **Replacement:** an operation containing a reversal and a new intended entry.
- **Adjustment or offset:** a delta associated with a prior entry. Depending on
  domain policy, more than one adjustment may be legitimate.

Abacus should validate that a related transaction exists and belongs to a
compatible ledger type and stream. Constraints that must be race-safe, such as
one full reversal, should be enforced by the database as well as by application
validation.

The package should not impose "only one adjustment" universally. It should
provide policy hooks through which an individual ledger can restrict full
reversals, cumulative adjustments, and replacement chains.

### 6. Typed Payloads and Synchronous Projections

A polymorphic JSON transaction table is a useful authoritative store, but it
must not force consuming applications to perform financial joins, filtering,
and reporting against untyped JSON.

Support a payload type registry that can serialize and deserialize domain
payload objects deterministically. Money payloads should preserve amount and
currency without relying on floating-point values.

Also support synchronous transactional projectors. A projector should receive
the immutable transaction and write a domain-friendly relational representation
inside the same transaction. If a required projection fails, the append should
roll back.

Motor Equipment would continue to retain records such as:

- Hourly-use snapshots containing work order, account, rate, hours, and use
  date.
- Scheduled-rent details containing schedule version, account allocation,
  department assignment, and calendar period.
- Correction aggregates containing original and replacement use plus AP
  disposition.
- Accounts Payable settlement membership.

These are not duplicated ledger implementations. They are constrained domain
facts and query models linked to authoritative Abacus transactions.

If Abacus supports replayable read projections, distinguish them from required
inline projections. Rebuilding a report projection later is different from
committing the hourly-use snapshot that defines what was actually charged.

### 7. Temporal Queries and Aggregates

The proposal's temporal promises should become concrete query APIs. At minimum,
support querying a stream by:

- Event date.
- Accounting date.
- Recorded date, for facts known to the system at a historical point.
- Correlation or operation ID.
- Reversal or adjustment relationship.
- Current reversed or unreversed status.

Define how event and accounting timelines interact with later-recorded
retrospective corrections. A caller should be able to distinguish:

- The operational or accounting result using everything known now.
- What the system knew as of an earlier recorded time.

Aggregate reconstruction should use the same ordering and filtering semantics
as invariant enforcement. For long streams, support optional snapshots tied to
a stream version while keeping transactions authoritative.

### 8. Storage and Database Configurability

Allow consuming applications to configure the connection, table or schema
names, and model integration points. Abacus should publish a supported database
matrix and test payload storage, ULIDs, timestamps, uniqueness, and locking on
each supported platform.

For Borough Portal adoption, DB2 and SQLite compatibility are important. In
particular, JSON storage and cross-schema foreign keys may differ from MySQL or
PostgreSQL. A portable text-backed JSON option may be preferable when a native
JSON type is unavailable, provided serialization remains canonical.

The single polymorphic transaction table remains attractive because it
centralizes append-only permissions and reduces migration overhead. That
benefit should not require applications to give up their schema conventions,
database connection choices, or relational projections.

## Suggested Motor Equipment Adoption Shape

Motor Equipment would define two separate Abacus ledger types keyed by vehicle:

1. `VehicleEquityLedger`, containing opening balances, rent, hourly rent,
   Fleetio operating expenses, journal entries, transfers, and corrections.
2. `VehicleDepreciationLedger`, containing scheduled depreciation and
   reason-required corrections.

Keeping these ledgers distinct is important. Depreciation reduces book value
and contributes to operating expense, but it does not change vehicle equity.
A common toolkit should not imply a common balance.

The application would continue to own:

- Effective-dated rent and depreciation schedules.
- Vehicle finance profiles and opening baselines.
- Hourly-use and scheduled-rent detail.
- Source observations and import precedence.
- Unified hourly-use corrections.
- Accounts Payable request batches and settlement policy.
- Equity and earnings-and-expense report classification.

### Representative Hourly-Use Amendment

```text
Motor Equipment
    resolves replacement rate, department, work order, and account
    validates that the latest unreversed use may be amended
    determines the current AP claim and settlement plan

Abacus operation
    locks the vehicle equity stream
    appends the exact reversal of the original credit
    appends the replacement credit
    assigns one operation/correlation ID

Same database transaction
    records the replacement hourly-use snapshot
    records the unified correction aggregate
    applies an open-batch mutation when required
    records frozen follow-up settlement membership when required
```

### Representative Transfer

```text
Motor Equipment
    authorizes and describes the transfer

Abacus operation
    locks both vehicle equity streams in deterministic order
    appends the source debit and destination credit
    assigns one operation/correlation ID
    commits both entries or neither
```

### Representative Scheduled Process

```text
Motor Equipment
    resolves the applicable schedule and effective context
    calculates domain amounts and source keys

Abacus operation
    enforces idempotency
    validates ledger invariants
    appends one or more transactions

Same database transaction
    writes required scheduled-rent or depreciation detail projections
```

## Non-Goals for Abacus

The Motor Equipment use case does not justify adding the following to the
toolkit:

- A generic Accounts Payable settlement state machine.
- Effective-dated vehicle, rate, department, account, or work-order resolution.
- A universal domain-correction aggregate.
- Motor Equipment report categories or financial presentation rules.
- Import precedence and reconciliation policies for external systems.
- A general event-sourcing framework that requires every application aggregate
  to be reconstructed from Abacus.
- Automatically generated mutation routes for workflows whose authorization
  and validation extend beyond a simple ledger append.

Generic route helpers may remain useful for genuinely simple ledgers, but they
should be optional conveniences built on the same explicit write protocol.

## Prioritization

For Abacus to provide material benefit to Motor Equipment and similarly complex
domains, prioritize:

1. [Safe stream heads, locking, and versioning](safe-stream-heads-developer-guide.md).
2. Explicit posting context and toolkit-owned recorded time.
3. [Atomic multi-entry and multi-stream operations](multi-entry-multi-stream-operations.md).
4. Idempotency and source identity.
5. [Exact reversal and replacement semantics](correction-semantics-developer-guide.md).
6. [Typed payloads and required synchronous projections](typed-payloads-and-synchronous-projections-developer-guide.md).
7. Temporal query and aggregate APIs.
8. Period management, snapshots, optional routes, and other conveniences.

This ordering builds a trustworthy write protocol before expanding developer
convenience. Once those capabilities exist, Abacus can replace substantial
ledger infrastructure in Motor Equipment without absorbing Motor Equipment's
business policies or making its relational workflows harder to query.
