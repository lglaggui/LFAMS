<?php
// Media storage helpers for FAMS 0.911. This file is loaded by config.php.
declare(strict_types=1);

function fams_media_mime_for_file(string $path): ?string {
    $info = @getimagesize($path);
    $mime = strtolower((string)($info['mime'] ?? ''));
    return in_array($mime, ['image/jpeg','image/png','image/webp','image/svg+xml'], true) ? $mime : null;
}
function fams_system_media_seed(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $items = [
        'default_user_avatar' => [__DIR__.'/assets/avatars/default-user-silhouette.webp', 'image/webp'],
        'default_admin_avatar' => [__DIR__.'/assets/avatars/default-site-admin-avatar.webp', null],
        'lpgma_logo' => [__DIR__.'/assets/branding/lpgma-logo.webp', null],
        'simc_logo' => [__DIR__.'/assets/branding/simc-logo.webp', null],
        'cdh_logo' => [__DIR__.'/assets/branding/cdh-logo.webp', null],
        'cimc_logo' => [__DIR__.'/assets/branding/cimc-logo.webp', null],
        'cvmc_logo' => [__DIR__.'/assets/branding/cvmc-logo.webp', null],
    ];
    try {
        $check = $pdo->prepare('SELECT id, mime_type, OCTET_LENGTH(image_data) AS image_size FROM system_media WHERE media_key=? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO system_media(media_key,mime_type,image_data) VALUES(?,?,?)');
        $update = $pdo->prepare('UPDATE system_media SET mime_type=?, image_data=? WHERE id=?');
        foreach ($items as $key => [$path, $forcedMime]) {
            if (!is_file($path) || !is_readable($path)) continue;
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') continue;
            $mime = $forcedMime ?: fams_media_mime_for_file($path);
            if (!$mime) continue;
            $check->execute([$key]);
            $existing = $check->fetch();
            if (!$existing) {
                $insert->execute([$key, $mime, $raw]);
            } elseif ((string)$existing['mime_type'] !== $mime || (int)$existing['image_size'] !== strlen($raw)) {
                // Repair an older/badly seeded default without rewriting unchanged media.
                $update->execute([$mime, $raw, (int)$existing['id']]);
            }
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('MAIFIP default media seed deferred: '.$e->getMessage());
    }
}
function ensure_media_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    // 0.912: do not perform CREATE/ALTER/DROP operations on every web request.
    // Schema changes belong in the explicit upgrade SQL. Runtime DDL can leave a
    // PDO connection unusable when MySQL is configured with a small packet limit.
    try {
        $tables = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_media'")->fetchColumn();
        if ((int)$tables === 1) {
            $userCols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ('avatar_path','avatar_data','avatar_mime')")->fetchAll(PDO::FETCH_COLUMN);

            // Migrate legacy avatar files only when the new columns already exist.
            if (in_array('avatar_data', $userCols, true) && in_array('avatar_mime', $userCols, true) && in_array('avatar_path', $userCols, true)) {
                $rows = $pdo->query("SELECT id, avatar_path FROM users WHERE avatar_path IS NOT NULL AND avatar_path<>'' AND (avatar_data IS NULL OR OCTET_LENGTH(avatar_data)=0)")->fetchAll();
                $up = $pdo->prepare('UPDATE users SET avatar_data=?, avatar_mime=? WHERE id=?');
                foreach ($rows as $row) {
                    $path = (string)$row['avatar_path'];
                    if (!preg_match('#^uploads/avatars/[A-Za-z0-9._-]+$#', $path)) continue;
                    $full = __DIR__.'/'.$path;
                    if (!is_file($full) || !is_readable($full)) continue;
                    $raw = @file_get_contents($full);
                    $mime = fams_media_mime_for_file($full);
                    if ($raw !== false && $raw !== '' && $mime && in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                        $up->execute([$raw, $mime, (int)$row['id']]);
                    }
                }
                // Intentionally leave avatar_path in place until the bundled cleanup SQL
                // is run. This avoids destructive runtime DDL and preserves rollback safety.
            }

            fams_system_media_seed($pdo);
        }
        $done = true;
    } catch (Throwable $e) {
        // Optional media initialization remains non-blocking, but failures are visible
        // and the next request may retry. The explicit upgrade SQL owns schema creation.
        error_log('MAIFIP media initialization deferred: '.$e->getMessage());
    }
}
