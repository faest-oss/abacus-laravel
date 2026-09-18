<?php

declare(strict_types=1);

namespace Faest\Abacus\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final class StorageConfiguration
{
    /** @var array<string, string> */
    private const array DEFAULT_TABLES = [
        'ledger_stream_head' => 'ledger_stream_head',
        'ledger_operation' => 'ledger_operation',
        'ledger_transaction' => 'ledger_transaction',
        'ledger_snapshot' => 'ledger_snapshot',
    ];

    public function __construct(
        private Repository $config,
    ) {
        //
    }

    public function connection(?string $override = null): ?string
    {
        $connection = $override ?? $this->config->get('abacus.connection');

        if ($connection === null) {
            return null;
        }

        if (! is_string($connection) || trim($connection) === '') {
            throw new InvalidArgumentException('Abacus connection must be null or a non-empty string.');
        }

        return $connection;
    }

    public function schema(): ?string
    {
        $schema = $this->config->get('abacus.schema');

        if ($schema === null) {
            return null;
        }

        return $this->identifier($schema, 'schema');
    }

    public function table(string $key, bool $qualified = true): string
    {
        if (! isset(self::DEFAULT_TABLES[$key])) {
            throw new InvalidArgumentException("Unknown Abacus table: {$key}.");
        }

        $table = $this->identifier(
            $this->config->get("abacus.tables.{$key}", self::DEFAULT_TABLES[$key]),
            "tables.{$key}",
        );
        $schema = $qualified ? $this->schema() : null;

        return $schema === null ? $table : "{$schema}.{$table}";
    }

    private function identifier(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '' || str_contains($value, '.')) {
            throw new InvalidArgumentException(
                "Abacus {$key} must be a non-empty, unqualified database identifier.",
            );
        }

        return $value;
    }
}
