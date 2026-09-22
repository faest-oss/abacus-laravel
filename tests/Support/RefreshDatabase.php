<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase as LaravelRefreshDatabase;

trait RefreshDatabase
{
    use LaravelRefreshDatabase {
        refreshDatabase as refreshDatabaseUsingLaravel;
    }

    public function refreshDatabase(): void
    {
        if (config('database.default') !== 'db2') {
            $this->refreshDatabaseUsingLaravel();

            return;
        }

        $this->beforeRefreshingDatabase();
        $database = $this->app->make('db');
        $connectionName = (string) config('database.default');

        $this->beforeApplicationDestroyed(
            static fn () => $database->purge($connectionName),
        );

        Db2iDatabase::reset();
        $this->afterRefreshingDatabase();
    }
}
