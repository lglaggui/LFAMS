<?php
declare(strict_types=1);

/**
 * LFAMS central database configuration and connection.
 * Configure the hosting database credentials here once for both workspaces.
 */
// Prefer deployment-provided environment variables; retain legacy defaults for existing installs.
// Never commit real credentials. Staging and production should use separate DB users.
if (!defined('LFAMS_DB_HOST')) define('LFAMS_DB_HOST', (string)(getenv('LFAMS_DB_HOST') ?: 'localhost'));
if (!defined('LFAMS_DB_NAME')) define('LFAMS_DB_NAME', (string)(getenv('LFAMS_DB_NAME') ?: 'lfams_unified'));
if (!defined('LFAMS_DB_USER')) define('LFAMS_DB_USER', (string)(getenv('LFAMS_DB_USER') ?: 'root'));
if (!defined('LFAMS_DB_PASS')) define('LFAMS_DB_PASS', (string)(getenv('LFAMS_DB_PASS') !== false ? getenv('LFAMS_DB_PASS') : ''));


function lfams_pdo(): PDO {
    static $connection = null;
    if ($connection instanceof PDO) return $connection;
    $connection = new PDO(
        'mysql:host=' . LFAMS_DB_HOST . ';dbname=' . LFAMS_DB_NAME . ';charset=utf8mb4',
        LFAMS_DB_USER,
        LFAMS_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $connection;
}
