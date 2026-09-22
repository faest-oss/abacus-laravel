<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Faest\Abacus\Console\Commands\RebuildProjectionCommand;
use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Support\StorageConfiguration;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AbacusServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/abacus.php', 'abacus');

        $this->app->singleton(StorageConfiguration::class, function (Application $app) {
            return new StorageConfiguration($app->make(Repository::class));
        });

        $this->app->singleton(PayloadRegistry::class, function (Application $app) {
            $registry = new PayloadRegistry;

            /** @var array<string, class-string<DeserializablePayload>> $payloads */
            $payloads = $app->make(Repository::class)->get('abacus.payloads', []);

            foreach ($payloads as $type => $payloadClass) {
                $registry->register($type, $payloadClass);
            }

            return $registry;
        });

        $this->app->singleton(Abacus::class, function (Application $app) {
            $abacus = new Abacus(
                $app->make(PayloadRegistry::class),
                $app->make(StorageConfiguration::class),
            );

            /** @var mixed $configuredLedgers */
            $configuredLedgers = $app->make(Repository::class)->get('abacus.ledgers', []);

            if (! is_array($configuredLedgers)) {
                throw new InvalidArgumentException('Configured Abacus ledgers must be an array.');
            }

            foreach ($configuredLedgers as $ledgerClass) {
                if (! is_string($ledgerClass) || ! is_subclass_of($ledgerClass, Ledger::class)) {
                    throw new InvalidArgumentException('Configured Abacus ledgers must implement Ledger.');
                }

                $abacus->registerLedger($app->make($ledgerClass));
            }

            return $abacus;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/abacus.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'abacus');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'abacus');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/abacus.php' => config_path('abacus.php'),
        ], ['abacus', 'abacus-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/abacus'),
        ], ['abacus', 'abacus-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/abacus'),
        ], ['abacus', 'abacus-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/abacus'),
        ], ['abacus', 'abacus-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['abacus', 'abacus-migrations']);

        $this->commands([
            RebuildProjectionCommand::class,
        ]);
    }
}
