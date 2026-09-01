Ledger Toolkit for Laravel
---------------------------

A ledger is an append-only, authoritative record of facts or transactions where previously recorded entries are never modified or removed; fixing past entries requires recording new entries.

The key value add of a ledger is the ability to audit and understand how the present system state came to be. Ledgers allow you to recreate the state of the system at arbitrary points in time using bi-temporal timelines.

## Requirements

### Auditing

The following fields must be associated with every entry in a ledger:
- Effective date: The canonical event associated with this event - determined by the *domain*
- Recorded at: The date the event was recorded into the ledger - determined by the *toolkit* - this must not be modified by the domain, otherwise the ledger risks becoming corrupted.
- entered_by_user_id: An identifier for the user that effectuated this entry. The system must be able to answer *who* did caused a change.
- reason: A text field capturing why this event is being recorded.

The above fields will enable the system to answer WHO, WHEN and WHY. Using two date fields allows WHEN to be answered along the *domain* timeline or the *system* timeline. The rest of the event will answer the WHAT.

### Developer Experience

There are so, so many uses cases in my domain that are conveniently solved by ledgers. My domain is a municipal government with many sub-domains such as utility billing, payroll/pension tracking, vehicle equity tracking, depreciation tracking, and so, so many more.

I want to encourage the use of ledgers whenever they are the right tool for the job. Because of the shear volume ledgers dealt with, they should be standardized and have low cognitive load for the developer.

- A ledger can be implemented with just a single class. Additional dtos/popos/models for representing domain ledger entries are supported, but not required. Type hinted associative array payloads are equally valid from the library's perspective - the "right" choice is for the domain to decide.
- The library should handle the write protocol for the domain
- By default is for a single, polymorphic ledger_transaction table. The library will support optional projections/projectors that allow the domain to project the ledger into a domain friendly table. A single ledger_transaction table minimizes the amount of tables that must be managed with migrations and the number of tables that must be configured with the correct permissions (ensuring that insert is allowed, but update and delete is not).
- Ledgers often have invariants that must be maintained, for example some ledgers may not allow a balance to become negative. To assist with this we should provide an ```aggregate``` pattern from event sourcing.
- A ledger can be configure routes that allow the ledger to be exposed as a simple API - post, reverse, adjust and provides hooks for authorizing each of those functions. More advanced needs can be handled by the domain wrapping the ledger with a dedicated controller/service.

## Schema

### ledger_transaction

| Name | Type | Description |
| ---  | :---:| ---         |
| id | ulid | Primary key, ulid enables chronological sorting |
| ledger_type | string | The polymorphic morph column (ex: App\Domains\Payroll\Pension\Ledgers\ServiceYearLedger) |
| ledger_id | string/int | Polymorphic id (ex: pension plan id or account id) - defines which transactions are grouped together |
| payload | json | The "WHAT" provided by the domain |
| effective_date | timestamp | The domain timeline - when did this event happen |
| recorded_at | timestamp | The system timeline. Ideally chosen automatically by the db engine at insert time |
| entered_by_user_id | unsigned big int | string | The "WHO" |
| reason | string/text | The "WHY" (ex: "Billing route 56 cycle 2026-03" or "Award 1 service year on anniversary") |

## Code snippets

To help guide implementation, here are some snippets of code. These are not set in stone, merely to provide guidance.

### LedgerTransaction Eloquent Model

Enforces immutability at the application/framework layer. Ideally this should be enforced at the DB level as well.

```php
// TODO: @property type hints
class LedgerTransaction extends Model
{
    public const UPDATED_AT = null; // Disable standard updated_at

    protected static function booted(): void
    {
        // Throw exceptions if any code attempts to mutate the past
        static::updating(fn () => throw new LedgerImmutableException("Ledger entries cannot be modified."));
        static::deleting(fn () => throw new LedgerImmutableException("Ledger entries cannot be deleted."));
    }
}
```


### Example Ledger

```php
class UtilityBillingLedger extends BaseLedger
{
    // Optional: Define allowed transaction types
    protected array $allowedTypes = ['charge', 'payment', 'adjustment'];

    /**
     * The Aggregate Pattern: Run before committing a new entry.
     * Throws an exception if the new event violates business rules.
     */
    public function assertInvariants(Collection $historicalEntries, array $newPayload): void
    {
        $currentBalance = $historicalEntries->sum('payload.amount');
        $newBalance = $currentBalance + $newPayload['amount'];

        if ($newBalance < 0 && $newPayload['type'] === 'charge') {
            throw new InvariantViolationException("Utility balances cannot drop below zero from a charge.");
        }
    }
}
```

### Exposing ledger as an api

Using a ```ledger``` route macro.

```php
// In routes/api.php
Route::ledger('utilities/{account}', UtilityBillingLedger::class)
    ->middleware('can:manage-utilities'); 

// This automatically registers:
// POST /utilities/{account}/post
// POST /utilities/{account}/reverse
// POST /utilities/{account}/adjust
```


