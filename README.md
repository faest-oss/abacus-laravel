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

$abacus = app(Abacus::class);
```

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
