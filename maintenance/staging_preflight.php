<?php
declare(strict_types=1);

/**
 * FAMS staging-only, read-only deployment preflight.
 * Run from CLI: php maintenance/staging_preflight.php
 * Requires APP_ENV=staging and a database name containing staging or test.
 * This script performs no schema changes and writes no database rows.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

$root = dirname(__DIR__);
$checks = [];
$fail = static function (string $name, string $detail) use (&$checks): void { $checks[] = ['FAIL', $name, $detail]; };
$pass = static function (string $name, string $detail) use (&$checks): void { $checks[] = ['PASS', $name, $detail]; };
$warn = static function (string $name, string $detail) use (&$checks): void { $checks[] = ['WARN', $name, $detail]; };

$env = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
if ($env === 'staging') $pass('Environment marker', 'APP_ENV=staging');
else $fail('Environment marker', 'Set APP_ENV=staging. No database connection attempted until staging is explicitly selected.');

if (PHP_VERSION_ID >= 80100) $pass('PHP version', PHP_VERSION);
else $fail('PHP version', PHP_VERSION . ' detected; PHP 8.1 or newer is required.');

foreach (['pdo', 'pdo_mysql', 'fileinfo', 'mbstring'] as $ext) {
    extension_loaded($ext) ? $pass('PHP extension: ' . $ext, 'Loaded') : $fail('PHP extension: ' . $ext, 'Missing; enable it for both CLI and web PHP.');
}
foreach (['zip', 'gd', 'openssl'] as $ext) {
    extension_loaded($ext) ? $pass('PHP extension: ' . $ext, 'Loaded') : $warn('PHP extension: ' . $ext, 'Not loaded; confirm whether enabled features require it.');
}

$ini = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'date.timezone' => ini_get('date.timezone'),
];
foreach ($ini as $key => $value) $pass('PHP setting: ' . $key, (string)$value);

foreach (['core/database.php', 'aics/config.php', 'maifip/config.php', 'maintenance/endpoint-registry.json'] as $rel) {
    is_file($root . '/' . $rel) ? $pass('Required file: ' . $rel, 'Present') : $fail('Required file: ' . $rel, 'Missing from deployment package.');
}

$registryPath = $root . '/maintenance/endpoint-registry.json';
if (is_file($registryPath)) {
    $registry = json_decode((string)file_get_contents($registryPath), true);
    if (is_array($registry) && isset($registry['routes']) && is_array($registry['routes'])) {
        $pass('Endpoint registry', count($registry['routes']) . ' routes; JSON parsed');
    } else $fail('Endpoint registry', 'Invalid JSON or missing routes array.');
}

foreach (['aics/assets/avatars', 'maifip/assets/avatars'] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_dir($path)) $warn('Writable directory: ' . $rel, 'Directory does not exist; verify whether deployment needs it created.');
    elseif (is_writable($path)) $pass('Writable directory: ' . $rel, 'Writable by CLI user; confirm web-server user has equivalent access.');
    else $fail('Writable directory: ' . $rel, 'Not writable by CLI user.');
}

$dbName = (string)(getenv('LFAMS_DB_NAME') ?: '');
if ($dbName !== '' && preg_match('/(staging|stage|test)/i', $dbName)) $pass('Database name safety marker', 'Name indicates a staging/test database.');
else $fail('Database name safety marker', 'Set LFAMS_DB_NAME to a dedicated name containing staging or test; production-like names are refused.');

if ($env === 'staging' && extension_loaded('pdo_mysql') && $dbName !== '' && preg_match('/(staging|stage|test)/i', $dbName)) {
    require_once $root . '/core/database.php';
    try {
        $pdo = lfams_pdo();
        $row = $pdo->query('SELECT DATABASE() AS db_name, VERSION() AS db_version')->fetch(PDO::FETCH_ASSOC);
        if (strtolower((string)($row['db_name'] ?? '')) !== strtolower($dbName)) {
            $fail('Database identity', 'Connected database name does not match LFAMS_DB_NAME.');
        } else {
            $pass('Database connection', 'Connected to the configured staging database.');
            $pass('Database server', (string)($row['db_version'] ?? 'Version unavailable'));
            $engine = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine = 'InnoDB'")->fetchColumn();
            $pass('InnoDB tables', (string)$engine . ' InnoDB tables detected; compare with the approved schema inventory.');
            $charset = $pdo->query('SELECT @@character_set_database')->fetchColumn();
            if (strtolower((string)$charset) === 'utf8mb4') $pass('Database character set', 'utf8mb4');
            else $warn('Database character set', 'Detected ' . (string)$charset . '; verify schema/table collations are utf8mb4.');
        }
    } catch (Throwable $e) {
        $fail('Database connection', get_class($e) . ': ' . $e->getMessage());
    }
} else {
    $warn('Database connection', 'Skipped because staging marker, pdo_mysql, or safe database name is missing.');
}

$failed = count(array_filter($checks, static fn($c) => $c[0] === 'FAIL'));
$warnings = count(array_filter($checks, static fn($c) => $c[0] === 'WARN'));
echo "FAMS STAGING PREFLIGHT — READ ONLY\n";
echo "PHP SAPI: " . PHP_SAPI . " | PHP: " . PHP_VERSION . " | Root: " . $root . "\n\n";
foreach ($checks as [$status, $name, $detail]) echo sprintf("[%s] %s — %s\n", $status, $name, $detail);
echo "\nSummary: " . count($checks) . " checks; {$failed} failures; {$warnings} warnings.\n";
echo $failed === 0 ? "Preflight status: PASS (this is not a security certification).\n" : "Preflight status: BLOCKED — resolve failures before staging tests.\n";
exit($failed === 0 ? 0 : 1);
