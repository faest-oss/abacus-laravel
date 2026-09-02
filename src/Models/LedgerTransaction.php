<?php

declare(strict_types=1);

namespace Faest\Abacus\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LedgerTransactionFactory;
use Faest\Abacus\Exceptions\LedgerImmutableException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $ledger_type
 * @property string $ledger_id
 * @property string $payload_type
 * @property array<mixed> $payload
 * @property string $reason
 * @property ?string $reverses_transaction_id
 * @property ?string $adjusts_transaction_id
 * @property ?string $correlation_id
 * @property CarbonImmutable $effective_at
 * @property CarbonImmutable $recorded_at
 * @property string $entered_by_user_id
 */
class LedgerTransaction extends Model
{
    /** @use HasFactory<LedgerTransactionFactory> */
    use HasFactory;

    use HasUlids;

    public const CREATED_AT = null; // The ledger records its system time in recorded_at.

    public const UPDATED_AT = null; // Disable standard updated_at.

    protected $table = 'ledger_transaction';

    protected $guarded = ['id', 'recorded_at', 'entered_by_user_id'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Throw exceptions if any code attempts to mutate the past
        static::updating(fn () => throw new LedgerImmutableException('Ledger entries cannot be modified.'));
        static::deleting(fn () => throw new LedgerImmutableException('Ledger entries cannot be deleted.'));
    }
}
