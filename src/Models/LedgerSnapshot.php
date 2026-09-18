<?php

declare(strict_types=1);

namespace Faest\Abacus\Models;

use Carbon\CarbonImmutable;
use Faest\Abacus\Exceptions\LedgerImmutableException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * @property string $id
 * @property string $ledger_type
 * @property string $ledger_id
 * @property int $stream_version
 * @property string $operation_id
 * @property int $snapshot_version
 * @property mixed $aggregate
 * @property CarbonImmutable $max_event_date
 * @property CarbonImmutable $max_accounting_date
 * @property CarbonImmutable $max_system_date
 * @property CarbonImmutable $created_at
 */
class LedgerSnapshot extends AbacusModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'created_at'];

    protected function storageTable(): string
    {
        return 'ledger_snapshot';
    }

    protected function casts(): array
    {
        return [
            'stream_version' => 'integer',
            'snapshot_version' => 'integer',
            'aggregate' => 'array',
            'max_event_date' => 'immutable_datetime',
            'max_accounting_date' => 'immutable_datetime',
            'max_system_date' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected function getJsonCastFlags($key): int
    {
        return parent::getJsonCastFlags($key)
            | ($key === 'aggregate' ? JSON_PRESERVE_ZERO_FRACTION : 0);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LedgerImmutableException('Ledger snapshots cannot be modified.'));
    }
}
