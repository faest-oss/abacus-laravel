<?php

declare(strict_types=1);

return [

    'placeholder' => 'default',

    'connection' => env('ABACUS_DB_CONNECTION'),

    'schema' => env('ABACUS_DB_SCHEMA'),

    'tables' => [
        'ledger_stream_head' => 'ledger_stream_head',
        'ledger_operation' => 'ledger_operation',
        'ledger_transaction' => 'ledger_transaction',
        'ledger_snapshot' => 'ledger_snapshot',
    ],

    'ledgers' => [],

    'payloads' => [],

];
