<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$script = file_get_contents($root . '/maintenance/staging_preflight.php');
$db = file_get_contents($root . '/core/database.php');
$checks = [
    'preflight is CLI-only' => str_contains($script, "PHP_SAPI !== 'cli'"),
    'explicit staging marker required' => str_contains($script, "getenv('APP_ENV')") && str_contains($script, '$env ==='),
    'database name must be staging/test' => str_contains($script, '(staging|stage|test)'),
    'database connection gated by safe prerequisites' => str_contains($script, "extension_loaded('pdo_mysql')") && str_contains($script, "preg_match('/(staging|stage|test)/i'"),
    'preflight performs read-only database queries' => str_contains($script, 'SELECT DATABASE()') && !preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $script),
    'DB config reads host from environment' => str_contains($db, "getenv('LFAMS_DB_HOST')"),
    'DB config reads database name from environment' => str_contains($db, "getenv('LFAMS_DB_NAME')"),
    'DB config reads credentials from environment' => str_contains($db, "getenv('LFAMS_DB_USER')") && str_contains($db, "getenv('LFAMS_DB_PASS')"),
];
$failed = 0;
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $failed++; }
echo count($checks) . ' checks; ' . $failed . " failures\n";
exit($failed ? 1 : 0);
