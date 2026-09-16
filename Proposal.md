Ledger Toolkit for Laravel
---------------------------

> **Status:** This is the original design proposal. The current write APIs and
> correction semantics are documented in
> [multi-entry-multi-stream-operations.md](multi-entry-multi-stream-operations.md)
> and [correction-semantics-developer-guide.md](correction-semantics-developer-guide.md).

A ledger is an append-only, authoritative record of facts or transactions where previously recorded entries are never modified or removed; fixing past entries requires recording new entries.

The key value add of a ledger is the ability to audit and understand how the present system state came to be. Ledgers allow you to recreate the state of the system at arbitrary points in time using bi-temporal timelines.

## Requirements

### Auditing

The following fields must be associated with every entry in a ledger:
- Event date: When did this event actually happen - provided by the *domain*
- Accounting date: When does this event count *financially* - also provided by the domain. The period closing rules vary by domain and this cannot be determined in the toolkit.
- Recorded at: The date the event was recorded into the ledger - determined by the *toolkit* - this must not be modified by the domain, otherwise the ledger risks becoming corrupted.
- entered_by_user_id: An identifier for the user that effectuated this entry. The system must be able to answer *who* did caused a change.
- reason: A text field capturing why this event is being recorded.
- reversal of - if a transaction represents the reversal/void of a former - it should record what transaction it is reversing.
- adjustment of - if a transaction represents the adjustment of a former - it should likewise records its target.
- correlation id - if a single logical event requires multiple transactions, they are tied together using a correlation id. For example a transfer from one ledger to another requires at least two transactions - because a transaction is always tied to a single ledger id, a transfer must be represented as two transactions, one for the source ledger and one for the destination ledger.

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
| event_date | timestamp | The operational domain timeline - when did this event actually happen |
| accounting_date | timestamp | The accounting timeline - when does the event count for |
| recorded_at | timestamp | The system timeline - when did the system receive this event. Ideally chosen automatically by the db engine at insert time |
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

    public function assertValidPayload(LedgerPayload $payload): void
    {
        if (! is_numeric($payload->jsonSerialize()['amount'] ?? null)) {
            throw new InvalidPayloadException('Utility entries require a numeric amount.');
        }
    }

    /**
     * Runs against the final aggregate produced by the complete operation.
     */
    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void
    {
        if ($aggregate['balance'] < 0) {
            throw new InvariantViolationException('Utility balances cannot be negative.');
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


### Multi ledger orchestration


```php
class LedgerOrchestrator
{
    /**
     * @param AbstractLedger[] $ledgers
     */
    public static function operation(array $ledgers, Closure $actions)
    {
        return DB::transaction(function () use ($ledgers, $actions) {
            $correlationId = (string) Str::uuid();

            // 1. Sort globally to prevent cross-table/cross-row deadlocks
            usort($ledgers, function ($a, $b) {
                if ($a->ledgerType === $b->ledgerType) {
                    return strcmp((string)$a->ledgerId, (string)$b->ledgerId);
                }
                return strcmp($a->ledgerType, $b->ledgerType);
            });

            // 2. Lock all involved histories
            foreach ($ledgers as $ledger) {
                LedgerTransaction::where('ledger_type', $ledger->ledgerType)
                    ->where('ledger_id', $ledger->ledgerId)
                    ->lockForUpdate()
                    ->get();
            }

            // 3. Execute the user's closure, passing the shared correlation ID
            return $actions($correlationId);
        });
    }
}
```
```

```php
class WalkInPaymentService
{
    public function receivePayment(string $drawerId, string $arAccountId, float $amount, int $userId): void
    {
        $drawer = new CashDrawerLedger($drawerId);
        $ar = new AccountsReceivableLedger($arAccountId);

        LedgerOrchestrator::operation([$drawer, $ar], function (string $correlationId) use (...) {
            
            // Leg 1: Cash increases in the physical drawer
            $drawer->post(
                payload: new CashReceipt($amount),
                effectiveDate: now(),
                reason: "Walk-in payment for AR Acct $arAccountId",
                userId: $userId,
                correlationId: $correlationId
            );

            // Leg 2: The AR balance decreases
            $ar->post(
                payload: new ArCredit($amount), 
                effectiveDate: now(),
                reason: "Paid via Cash Drawer $drawerId",
                userId: $userId,
                correlationId: $correlationId
            );

        });
    }
}
```


```php

// Contract
interface PeriodManager
{
    /**
     * Toolkit Requirement: Throws if the exact date is closed.
     */
    public function assertDateIsOpen(CarbonInterface $date, string $ledgerType): void;

    /**
     * Domain Helper: Calculates the correct open accounting date based on an event.
     */
    public function resolveAccountingDate(CarbonInterface $eventDate, string $ledgerType): CarbonInterface;
}

```

The abstract ledger implementations can resolve the PeriodManager provided by the domain and reject posting into a closed period.

```php
app(PeriodManager::class)->assertDateIsOpen($accountingDate, $this->ledgerType);
```
