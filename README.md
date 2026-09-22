<div align="center">
    <h1>Abacus</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/faest-oss/abacus"><img src="https://img.shields.io/packagist/v/faest-oss/abacus.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/faest-oss/abacus"><img src="https://img.shields.io/packagist/php-v/faest-oss/abacus.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/faest-oss/abacus"><img src="https://badge.laravel.cloud/badge/faest-oss/abacus?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/faest-oss/abacus/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/faest-oss/abacus/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/faest-oss/abacus"><img src="https://img.shields.io/packagist/dt/faest-oss/abacus.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Easy to use immutable event ledgers

## Installation

You can install the package via Composer:

```bash
composer require faest-oss/abacus
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="abacus"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="abacus-config"
```

Configure Abacus storage before running its migrations. By default, Abacus uses
Laravel's default database connection and the current database schema:

```php
// config/abacus.php
'connection' => env('ABACUS_DB_CONNECTION'),
'schema' => env('ABACUS_DB_SCHEMA'),

'tables' => [
    'ledger_stream_head' => 'ledger_stream_head',
    'ledger_operation' => 'ledger_operation',
    'ledger_transaction' => 'ledger_transaction',
    'ledger_snapshot' => 'ledger_snapshot',
],
```

Set `ABACUS_DB_CONNECTION` to use a dedicated configured Laravel connection.
Set `ABACUS_DB_SCHEMA` for a schema such as `accounting`; the schema must
already exist. Table values are independently configurable, unqualified names.
For SQLite, `main` may be used as the schema.

`Abacus::overrideConnection()` remains available for an individual coordinator
instance and takes precedence over `abacus.connection`. It does not change the
configured schema or table names.

Changing storage names does not rename or move existing data. Applications
with an existing Abacus installation must migrate their data before switching
the configuration.

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="abacus-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="abacus-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="abacus-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="abacus-assets"
```

## Usage

```php
use Faest\Abacus\Abacus;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\OperationBuilder;

$abacus = app(Abacus::class);
$abacus->registerLedger(new VehicleEquityLedger);

$context = PostingContext::forProcess(
    processName: 'monthly-rent',
    eventDate: now(),
    accountingDate: now(),
    reason: 'Post monthly vehicle rent',
    idempotencyKey: 'monthly-rent:2026-09:vehicle-101',
);

$transaction = $abacus->post(
    VehicleEquityLedger::class,
    'vehicle-101',
    new RentPosted(amount: 50000),
    $context,
);
```

Ledgers may instead be resolved from Laravel's container at coordinator
construction time:

```php
// config/abacus.php
'ledgers' => [
    VehicleEquityLedger::class,
    DepartmentEquityLedger::class,
],
```

Transfers may cross ledger types while retaining one atomic operation:

```php
$transfer = $abacus->transferBetween(
    new EquityTransferred(amount: -50000),
    VehicleEquityLedger::class,
    $vehicle->id,
    DepartmentEquityLedger::class,
    $department->id,
    $context,
);
```

Use `postMany()` for several ordinary entries in one stream. Use
`operation()` when one atomic write spans streams or combines posting,
reversal, replacement, adjustment, or transfer actions:

```php
$operation = $abacus->operation($context, function (OperationBuilder $operation) use ($original, $replacement) {
    $operation->replace($original->id, $replacement);
    $operation->post(GeneralLedger::class, 'fleet-expense', new ExpensePosted(amount: 50000));
});
```

Every write creates an immutable operation record. Transaction results expose
their operation ID, operation position, and stream version. See the
[operation guide](multi-entry-multi-stream-operations.md) and
[correction guide](correction-semantics-developer-guide.md) for the complete
write protocol.

### Typed Payloads

Payloads implement `LedgerPayload` and return an associative array from
`jsonSerialize()`. Payloads that can be reconstructed from history also
implement `DeserializablePayload` and may be registered in `config/abacus.php`:

```php
'payloads' => [
    HourlyUseRecorded::TYPE => HourlyUseRecorded::class,
],
```

Mappings may also be registered during application boot:

```php
$abacus->registerPayload(HourlyUseRecorded::TYPE, HourlyUseRecorded::class);
```

A ledger may implement `HasPayloadTypes` to register its payload mappings when
the ledger itself is registered. This keeps a domain's ledger, payload, and
projector declarations together.

Unregistered history is returned as `GenericPayload`. Financial payloads may
implement `HasMoneyAmount`; Abacus then requires integer minor units through
the contract and a three-letter uppercase currency code.

### Required Projections

Use a `Projector` for each new transaction or an `OperationProjector` when the
complete operation is required. Register container-resolvable classes or
instances against one ledger type:

```php
$abacus->registerProjector(
    VehicleEquityLedger::class,
    HourlyUseSnapshotProjector::class,
);

$abacus->registerOperationProjector(
    VehicleEquityLedger::class,
    CorrectionAggregateProjector::class,
);
```

Ledgers may declare the same required projectors through `HasProjectors`.
Required projectors run on the selected Abacus connection before commit. An
exception rolls back the operation, its stream heads, and projection writes
made through the supplied connection.

### Locked Operation Policies

Implement `OperationPolicy` on a ledger when a write rule must be evaluated
after every affected stream has been locked. The policy receives an
`OperationValidationContext` containing the operation kind, posting context,
locked starting version, proposed payloads, current aggregates before and
after, and the stream's existing plus proposed accounting entries. Matching
idempotent retries return before policies run.

Use a policy for authoritative stream rules such as temporal solvency. Keep
request validation and rules involving entities outside the ledger in the
application domain.

### Money Balance Ledgers

`MoneyBalanceLedger` is an optional base class for ledgers whose aggregate is
the sum of signed minor-unit payload amounts. Payloads must implement both
`HasMoneyAmount` and `OpposablePayload`. The base class validates currency,
computes opposing entries, maintains `balance_minor`, and evaluates balance
rules at every accounting boundary affected by a backdated operation.

Subclasses identify accepted payloads and currency and may override
`allowsNegativeBalance()` and `negativeBalanceException()` for their domain.

### Replayable Projections

Disposable reporting models implement `ReplayableProjector` and rebuild only
when explicitly requested:

```php
$count = $abacus->rebuildProjection(
    VehicleMonthlyEquitySummaryProjector::class,
    VehicleEquityLedger::class,
    chunkSize: 500,
);
```

The equivalent Artisan command is:

```bash
php artisan abacus:projection:rebuild \
    "App\Projectors\VehicleMonthlyEquitySummaryProjector" \
    --ledger="vehicle-equity" \
    --chunk=500
```

The reset and complete replay are one transaction. Pause writes for the
selected ledger during a rebuild; `--force` only suppresses the production
confirmation and does not coordinate maintenance mode.

### Temporal Queries and Aggregates

Use `TemporalView` to reconstruct a stream by event, accounting, or recorded
time. Cutoffs are inclusive, combine with `AND`, and never change stream-version
ordering:

```php
use Carbon\CarbonImmutable;
use Faest\Abacus\Data\TemporalView;

$view = TemporalView::eventAsOf(
    eventThrough: CarbonImmutable::parse('2026-06-30 23:59:59'),
    knownAt: CarbonImmutable::parse('2026-07-05 12:00:00'),
);

$aggregate = $abacus->getAggregate(
    VehicleEquityLedger::class,
    'vehicle-101',
    $view,
);
```

For stream-local audit queries, compose the view with `TransactionCriteria`:

```php
use Faest\Abacus\Data\TransactionCriteria;
use Faest\Abacus\Enums\ReversalStatus;

$transactions = $abacus->transactionsForStream(
    VehicleEquityLedger::class,
    'vehicle-101',
    new TransactionCriteria(
        view: $view,
        reversalStatus: ReversalStatus::Unreversed,
    ),
)->get();
```

Reversal status is relative to the selected view. A reversal recorded after
the view's recorded cutoff does not mark its original as reversed in that
earlier view. Use `CorrectionFilter` and `CorrectionRelationship` to select
reversal, replacement, or adjustment entries directly.

### Aggregate Snapshots

Ledgers may implement `SnapshotsAggregate` to opt into disposable aggregate
snapshots. The ledger owns its versioned serialization and hydration format:

```php
use Faest\Abacus\Contracts\SnapshotsAggregate;

final class VehicleEquityLedger implements Ledger, SnapshotsAggregate
{
    public function snapshotVersion(): int
    {
        return 1;
    }

    public function serializeAggregateSnapshot(array|JsonSerializable $aggregate): array
    {
        if (! is_array($aggregate)) {
            throw new LogicException('Unexpected aggregate type.');
        }

        return $aggregate;
    }

    public function hydrateAggregateSnapshot(array $snapshot): array|JsonSerializable
    {
        return $snapshot;
    }

    // Ledger methods...
}
```

Create snapshots explicitly from application maintenance code:

```php
$version = $abacus->createAggregateSnapshot(
    VehicleEquityLedger::class,
    'vehicle-101',
);
```

Creation locks the existing stream head and is idempotent for the ledger's
current snapshot format and stream version. Empty streams return `0`. Ordinary
writes never create snapshots, and deleting snapshots only causes full replay.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Abacus! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [FAEST OSS](https://github.com/faest-oss)
- [All Contributors](../../contributors)

## License

Abacus is open-sourced software licensed under the [MIT license](LICENSE.md).
