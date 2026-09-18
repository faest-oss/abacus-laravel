<?php

declare(strict_types=1);

namespace Faest\Abacus\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LedgerTransactionFactory;
use Faest\Abacus\Exceptions\LedgerImmutableException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ledger_type
 * @property string $ledger_id
 * @property int $stream_version
 * @property string $operation_id
 * @property int $operation_position
 * @property string $payload_type
 * @property array<mixed> $payload
 * @property string $reason
 * @property ?string $reverses_transaction_id
 * @property ?string $adjusts_transaction_id
 * @property ?string $replaces_transaction_id
 * @property ?string $correlation_id
 * @property CarbonImmutable $event_date
 * @property CarbonImmutable $system_date
 * @property CarbonImmutable $accounting_date
 * @property string $actor
 * @property-read LedgerOperation $operation
 */
class LedgerTransaction extends Model
{
    /** @use HasFactory<LedgerTransactionFactory> */
    use HasFactory;

    use HasUlids;

    public const CREATED_AT = null; // The ledger records its system time in system_date.

    public const UPDATED_AT = null; // Disable standard updated_at.

    protected $table = 'ledger_transaction';

    protected $guarded = ['id', 'system_date', 'actor'];

    protected function casts(): array
    {
        return [
            'system_date' => 'immutable_datetime',
            'event_date' => 'immutable_datetime',
            'accounting_date' => 'immutable_datetime',
            'payload' => 'array',
            'operation_position' => 'integer',
            'stream_version' => 'integer',
        ];
    }

    /**
     * Preserve integral-looking floats in immutable payload JSON.
     */
    protected function getJsonCastFlags($key): int
    {
        return parent::getJsonCastFlags($key)
            | ($key === 'payload' ? JSON_PRESERVE_ZERO_FRACTION : 0);
    }

    /** @return BelongsTo<LedgerOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(LedgerOperation::class, 'operation_id');
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function replacedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_transaction_id');
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function adjustedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_transaction_id');
    }

    protected static function booted(): void
    {
        // Throw exceptions if any code attempts to mutate the past
        static::updating(fn () => throw new LedgerImmutableException('Ledger entries cannot be modified.'));
        static::deleting(fn () => throw new LedgerImmutableException('Ledger entries cannot be deleted.'));
    }
}
