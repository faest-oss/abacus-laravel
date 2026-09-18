---
name: abacus-development
description: >
  Configure and apply the Abacus package in Laravel applications.
license: MIT
metadata:
  author: FAEST OSS
---

# Abacus

Use this skill when a Laravel application needs to integrate the Abacus package.

## Primary Goal

- apply the `faest-oss/abacus` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project
- inspect the target code paths where the package should be applied

### 2. Apply the package's public API

Install the package with `composer require faest-oss/abacus`. Laravel discovers
`Faest\Abacus\AbacusServiceProvider` automatically. Use the package class through
`Faest\Abacus\Abacus` or its `Abacus` facade alias.

Publish all resources with `php artisan vendor:publish --tag=abacus`, or use an
individual `abacus-*` tag such as `abacus-config` or `abacus-migrations`.

Register each ledger implementation with `Abacus::registerLedger()`. A ledger
implements `Faest\Abacus\Contracts\Ledger`, including deterministic payload
reduction, payload validation, completed-aggregate invariant validation,
and opposing-payload calculation.

Implement payloads with `LedgerPayload`. Historical typed payloads also
implement `DeserializablePayload`; register their stable global type names in
`config('abacus.payloads')` or with `Abacus::registerPayload()`. Implement
`HasMoneyAmount` for financial payloads using integer minor units and uppercase
three-letter currency codes.

Use `PostingContext::forUser()`, `forProcess()`, or `forImport()` to supply the
actor, event date, accounting date, reason, and optional operation idempotency
key.

Choose the narrowest write API:

- `post()` for one ordinary entry;
- `postMany()` for multiple ordinary entries in one stream;
- `operation()` with `OperationBuilder` for multi-stream or mixed semantic
  actions;
- `reverse()`, `replace()`, `adjust()`, and `transfer()` for one named action.

Every write creates an immutable operation. Use the transaction result's
`operationId` to retrieve it with `findOperation()`.

Register required synchronous `Projector` or `OperationProjector`
implementations against a ledger type. They run inside the Abacus write
transaction and must use the supplied connection for rollback-safe writes.
Ledgers may alternatively declare them through `HasProjectors`.

Use `ReplayableProjector` only for disposable reporting views. Pause writes for
the selected ledger, then call `Abacus::rebuildProjection()` or run:

```bash
php artisan abacus:projection:rebuild \
    "App\Projectors\VehicleMonthlyEquitySummaryProjector" \
    --ledger="vehicle-equity" \
    --chunk=500
```

Production rebuilds require confirmation unless `--force` is supplied.

Use `TemporalView::eventAsOf()`, `accountingAsOf()`, or `knownAt()` with
`getAggregate()` when a workflow needs state through inclusive domain or
recorded-time cutoffs. Use `transactionsForStream()` with
`TransactionCriteria` for stream-local audit queries, including exact
operation, correlation, correction-relationship, and view-relative reversal
status filters.

Implement `SnapshotsAggregate` only when a ledger benefits from accelerated
aggregate replay. Give each incompatible serialized format a new positive
snapshot version, keep serialization and hydration deterministic, and invoke
`createAggregateSnapshot()` explicitly from application maintenance code.
Snapshots are disposable; ordinary Abacus writes do not create them.

## Rules, References, and Templates

Read before executing:

- no additional resource files for this skill

## Examples

```php
$abacus = app(\Faest\Abacus\Abacus::class);
$abacus->registerLedger(new VehicleEquityLedger);
$abacus->registerPayload(HourlyUseRecorded::TYPE, HourlyUseRecorded::class);
$abacus->registerProjector(
    VehicleEquityLedger::class,
    HourlyUseSnapshotProjector::class,
);

$transaction = $abacus->post(
    VehicleEquityLedger::class,
    (string) $vehicle->id,
    new RentPosted(amount: 50000),
    $context,
);

$view = \Faest\Abacus\Data\TemporalView::accountingAsOf(
    now()->endOfMonth(),
    knownAt: now(),
);

$aggregate = $abacus->getAggregate(
    VehicleEquityLedger::class,
    (string) $vehicle->id,
    $view,
);
```

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not use `postMany()` for multiple streams; compose those entries with
  `operation()`
- do not construct reversal payloads manually; use the correction APIs so
  Abacus computes and validates their relationships
- do not perform remote calls or dispatch pre-commit work from required
  projectors
- do not use replayable projections for authoritative workflow records, and do
  not rebuild while writes for the selected ledger continue
- do not treat event, accounting, and recorded timestamps as interchangeable;
  choose the timeline that answers the domain question
- do not create snapshots inside ordinary write workflows or treat them as
  authoritative ledger history
