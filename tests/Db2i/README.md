# Local DB2 for i test harness

This harness exercises Abacus against DB2 for i through the sibling
`../db2-driver` checkout. It is an opt-in development lane, not a declaration
that DB2 for i is part of Abacus's supported database contract.

The driver is installed only in `.db2i/vendor`; it is not a dependency in
Abacus's root Composer package metadata. Connection details live only in the
ignored `.env.db2i` file.

## Run

1. Create a dedicated DB2 for i schema for this suite. The harness qualifies
   cleanup operations with that schema and drops only the named foreign keys
   on its known Abacus tables, followed by the known Abacus and test-fixture
   tables; it does not use `dropAllTables()` or a schema-wide constraint toggle.
2. Copy `.env.db2i.example` to `.env.db2i`, enter the connection details, and
   set `DB2I_ALLOW_DESTRUCTIVE_TESTS=true`.
3. Run `composer test:db2i`.

Pass normal Pest arguments after `--`, for example:

```shell
composer test:db2i -- --filter="posts a transaction"
```

The host, credentials, database, and schema are never stored in tracked files.
