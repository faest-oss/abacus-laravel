<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use BWICompanies\DB2Driver\DB2ServiceProvider;
use Closure;
use Orchestra\Testbench\Foundation\Process\ProcessDecorator;
use Orchestra\Testbench\Foundation\Process\RemoteCommand;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use RuntimeException;

final class TwoProcessHarness
{
    /**
     * @param  Closure(): mixed  $first
     * @param  Closure(): mixed  $second
     * @return array{mixed, mixed}
     */
    public static function run(Closure $first, Closure $second, int $timeout = 10): array
    {
        $environment = self::databaseEnvironment();
        $processes = [
            self::remote(self::withDatabaseConfiguration(self::withoutTestScope($first)), $environment),
            self::remote(self::withDatabaseConfiguration(self::withoutTestScope($second)), $environment),
        ];

        try {
            foreach ($processes as $process) {
                $process->setTimeout($timeout);
                $process->start();
            }

            foreach ($processes as $process) {
                $process->wait();
            }

            return [
                self::result($processes[0], 1),
                self::result($processes[1], 2),
            ];
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.1);
                }
            }
        }
    }

    private static function withoutTestScope(Closure $task): Closure
    {
        return Closure::bind($task, null, null)
            ?? throw new RuntimeException('Unable to remove the Pest test scope from concurrent task');
    }

    /**
     * @param  array<string, string>  $environment
     */
    private static function remote(Closure $task, array $environment): ProcessDecorator
    {
        $packagePath = dirname(__DIR__, 2);
        $testbenchFile = (new ReflectionClass(TestCase::class))->getFileName();

        if ($testbenchFile === false) {
            throw new RuntimeException('Unable to locate the Testbench installation');
        }

        $testbench = dirname($testbenchFile, 4).'/bin/testbench';
        $environment['TESTBENCH_WORKING_PATH'] = $packagePath;

        return (new RemoteCommand($packagePath, $environment))->handle($testbench, $task);
    }

    private static function withDatabaseConfiguration(Closure $task): Closure
    {
        $connectionName = (string) config('database.default');
        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$connectionName}");
        $harnessAutoload = dirname(__DIR__, 2).'/.db2i/vendor/autoload.php';

        return static function () use ($connection, $connectionName, $harnessAutoload, $task): mixed {
            if ($connection['driver'] === 'db2') {
                require_once $harnessAutoload;
                app()->register(DB2ServiceProvider::class);
            }

            config()->set('database.default', $connectionName);
            config()->set("database.connections.{$connectionName}", $connection);

            return $task();
        };
    }

    /**
     * @return array<string, string>
     */
    private static function databaseEnvironment(): array
    {
        $connectionName = (string) config('database.default');
        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$connectionName}");

        return [
            'DB_CONNECTION' => (string) $connection['driver'],
            'DB_HOST' => (string) ($connection['host'] ?? ''),
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        ];
    }

    private static function result(ProcessDecorator $process, int $number): mixed
    {
        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Concurrent process {$number} failed: {$process->getErrorOutput()}",
            );
        }

        /** @var array{successful: bool, result?: string, exception?: string, message?: string} $payload */
        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (! $payload['successful']) {
            $exception = $payload['exception'] ?? 'unknown exception';
            $message = $payload['message'] ?? 'no message';

            throw new RuntimeException(
                "Concurrent process {$number} threw {$exception}: {$message}",
            );
        }

        return unserialize($payload['result'] ?? 'N;');
    }
}
