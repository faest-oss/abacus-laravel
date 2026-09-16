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
opposing-payload calculation, and payload deserialization.

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

## Rules, References, and Templates

Read before executing:

- no additional resource files for this skill

## Examples

```php
$abacus = app(\Faest\Abacus\Abacus::class);
$abacus->registerLedger(new VehicleEquityLedger);

$transaction = $abacus->post(
    VehicleEquityLedger::class,
    (string) $vehicle->id,
    new RentPosted(amount: 50000),
    $context,
);
```

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not use `postMany()` for multiple streams; compose those entries with
  `operation()`
- do not construct reversal payloads manually; use the correction APIs so
  Abacus computes and validates their relationships
