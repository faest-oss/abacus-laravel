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
