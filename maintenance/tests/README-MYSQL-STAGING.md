# FAMS MySQL staging tests

The staging harness is `mysql_concurrency_test.php`. It tests the shared idempotency ledger against MySQL/InnoDB:

- business write + idempotency claim rollback together;
- a committed request key suppresses a sequential retry with an identical payload;
- reusing the same key with a different payload raises a payload conflict;
- optimistic record-version compare-and-swap rejects a stale update;
- two independent PHP processes contend for the same unique request key and only one can claim it.

It is deliberately blocked unless the explicit safety flag is supplied and the database name contains `test` or `staging`. Do not point it at production.

## Windows / XAMPP PowerShell

```powershell
$env:FAMS_ALLOW_DB_TESTS = "1"
$env:FAMS_TEST_DSN = "mysql:host=127.0.0.1;port=3306;dbname=fams_staging;charset=utf8mb4"
$env:FAMS_TEST_DB_USER = "root"
$env:FAMS_TEST_DB_PASS = ""
php maintenance/tests/mysql_concurrency_test.php
```

The PHP CLI used for this command must have `pdo_mysql` enabled. The simultaneous-request subtest uses `proc_open` to start an independent PHP CLI worker, so it can run on Windows/XAMPP as well as Linux when process execution is permitted.

The test creates a uniquely named temporary InnoDB table and unique test-scope rows in `fams_idempotency_keys`, then removes them. The shared idempotency table itself is retained, as it is part of the FAMS schema.
