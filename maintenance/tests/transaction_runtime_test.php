<?php
/**
 * Shared transaction runtime tests.
 * Run from the htdocs root: php maintenance/tests/transaction_runtime_test.php
 * Uses a PDO test double; it does not connect to or modify a database.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/shared_runtime.php';

final class FamsTransactionPdoDouble extends PDO {
    public bool $active = false;
    public array $calls = [];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active = true; $this->calls[] = 'BEGIN'; return true; }
    public function commit(): bool { $this->active = false; $this->calls[] = 'COMMIT'; return true; }
    public function rollBack(): bool { $this->active = false; $this->calls[] = 'ROLLBACK'; return true; }
    public function exec(string $statement): int|false { $this->calls[] = $statement; return 0; }
}

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS: {$message}\n"; }
    else { $failed++; fwrite(STDERR, "FAIL: {$message}\n"); }
};

$db = new FamsTransactionPdoDouble();
$result = fams_shared_transaction($db, static fn(): string => 'committed');
$assert($result === 'committed' && !$db->active && $db->calls === ['BEGIN','COMMIT'], 'top-level transaction commits and returns callback result');

$db = new FamsTransactionPdoDouble();
try { fams_shared_transaction($db, static function (): void { throw new RuntimeException('expected'); }); }
catch (RuntimeException $e) { /* expected */ }
$assert(!$db->active && $db->calls === ['BEGIN','ROLLBACK'], 'top-level transaction rolls back when callback throws');

$db = new FamsTransactionPdoDouble();
$db->active = true;
$result = fams_shared_transaction($db, static fn(): int => 7);
$assert($result === 7 && $db->active && $db->calls === ['SAVEPOINT fams_sp_3','RELEASE SAVEPOINT fams_sp_3'], 'nested transaction releases savepoint without committing outer transaction');

$db = new FamsTransactionPdoDouble();
$db->active = true;
try { fams_shared_transaction($db, static function (): void { throw new RuntimeException('expected'); }); }
catch (RuntimeException $e) { /* expected */ }
$assert($db->active && $db->calls === ['SAVEPOINT fams_sp_4','ROLLBACK TO SAVEPOINT fams_sp_4','RELEASE SAVEPOINT fams_sp_4'], 'nested transaction rolls back to savepoint and preserves outer transaction');

printf("\nShared transaction tests: %d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
