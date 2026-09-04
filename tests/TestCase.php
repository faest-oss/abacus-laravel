<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests;

use Faest\Abacus\AbacusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', env('DB_CONN', 'sqlite'));
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '54322',
            'database' => 'abacus',
            'username' => 'user',
            'password' => 'password',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ]);

        // Clone the pgsql connection for concurrency testing
        $app['config']->set(
            'database.connections.pgsql2',
            $app['config']->get('database.connections.pgsql'),
        );
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            AbacusServiceProvider::class,
        ];
    }
}
