<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Support;

use BWICompanies\DB2Driver\DB2ServiceProvider as DriverServiceProvider;
use Illuminate\Support\ServiceProvider;
use PDO;
use RuntimeException;

final class Db2iServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->usesDb2i()) {
            return;
        }

        if (! class_exists(DriverServiceProvider::class)) {
            throw new RuntimeException(
                'The local DB2 for i driver is unavailable. Run the suite with composer test:db2i.',
            );
        }

        $this->app->register(DriverServiceProvider::class);

        $this->app['config']->set('database.connections.db2', $this->connection());
    }

    private function usesDb2i(): bool
    {
        return env('DB_CONN', env('DB_CONNECTION')) === 'db2';
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        return [
            'driver' => 'db2',
            'driverName' => env('DB2I_ODBC_DRIVER', '{IBM i Access ODBC Driver}'),
            'host' => env('DB2I_HOST'),
            'port' => env('DB2I_PORT', 50000),
            'database' => env('DB2I_DATABASE'),
            'username' => env('DB2I_USERNAME'),
            'password' => env('DB2I_PASSWORD'),
            'prefix' => '',
            'schema' => env('DB2I_SCHEMA'),
            'date_format' => 'Y-m-d H:i:s',
            'offset_compatibility_mode' => env('DB2I_OFFSET_COMPATIBILITY_MODE', false),
            'odbc_keywords' => [
                'SIGNON' => 3,
                'SSL' => env('DB2I_SSL', 0),
                'CommitMode' => 1,
                'ConnectionType' => 0,
                'DefaultLibraries' => env('DB2I_SCHEMA'),
                'Naming' => 1,
                'UNICODESQL' => 0,
                'DateFormat' => 5,
                'DateSeperator' => 0,
                'Decimal' => 0,
                'TimeFormat' => 0,
                'TimeSeparator' => 0,
                'TimestampFormat' => 0,
                'ConvertDateTimeToChar' => 0,
                'BLOCKFETCH' => 1,
                'BlockSizeKB' => 32,
                'AllowDataCompression' => 1,
                'CONCURRENCY' => 0,
                'LAZYCLOSE' => 0,
                'MaxFieldLength' => 15360,
                'PREFETCH' => 0,
                'QUERYTIMEOUT' => 1,
                'DefaultPkgLibrary' => 'QGPL',
                'DefaultPackage' => 'A /DEFAULT(IBM),2,0,1,0',
                'ExtendedDynamic' => 0,
                'QAQQINILibrary' => '',
                'SQDIAGCODE' => '',
                'LANGUAGEID' => 'ENU',
                'SORTTABLE' => '',
                'SortSequence' => 0,
                'SORTWEIGHT' => 0,
                'AllowUnsupportedChar' => 0,
                'CCSID' => 1208,
                'GRAPHIC' => 0,
                'ForceTranslation' => 0,
                'ALLOWPROCCALLS' => 0,
                'DB2SQLSTATES' => 0,
                'DEBUG' => 0,
                'TRUEAUTOCOMMIT' => 0,
                'CATALOGOPTIONS' => 3,
                'LibraryView' => 0,
                'ODBCRemarks' => 0,
                'SEARCHPATTERN' => 1,
                'TranslationDLL' => '',
                'TranslationOption' => 0,
                'MAXTRACESIZE' => 0,
                'MultipleTraceFiles' => 1,
                'TRACE' => 0,
                'TRACEFILENAME' => '',
                'ExtendedColInfo' => 0,
            ],
            'options' => [
                PDO::ATTR_CASE => PDO::CASE_LOWER,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        ];
    }
}
