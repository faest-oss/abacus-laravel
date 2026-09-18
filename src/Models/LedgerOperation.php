<?php

declare(strict_types=1);

namespace Faest\Abacus\Models;

use Carbon\CarbonImmutable;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Exceptions\LedgerImmutableException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property OperationKind $kind
 * @property string $actor
 * @property string $reason
 * @property CarbonImmutable $event_date
 * @property CarbonImmutable $accounting_date
 * @property CarbonImmutable $system_date
 * @property ?string $correlation_id
 * @property array<mixed> $metadata
 * @property ?string $idempotency_key
 * @property int $request_fingerprint_version
 * @property string $request_fingerprint
 * @property ?string $reverses_operation_id
 */
class LedgerOperation extends AbacusModel
{
    use HasUlids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'system_date'];

    protected function storageTable(): string
    {
        return 'ledger_operation';
    }

    protected function casts(): array
    {
        return [
            'kind' => OperationKind::class,
            'event_date' => 'immutable_datetime',
            'accounting_date' => 'immutable_datetime',
            'system_date' => 'immutable_datetime',
            'metadata' => 'array',
            'request_fingerprint_version' => 'integer',
        ];
    }

    /** @return HasMany<LedgerTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class, 'operation_id')->orderBy('operation_position');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LedgerImmutableException('Ledger operations cannot be modified.'));
        static::deleting(fn () => throw new LedgerImmutableException('Ledger operations cannot be deleted.'));
    }
}
