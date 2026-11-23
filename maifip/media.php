<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$key = trim((string)($_GET['key'] ?? ''));
$publicMediaKeys = ['default_user_avatar','default_admin_avatar','lpgma_logo','simc_logo','cdh_logo','cimc_logo','cvmc_logo'];
if ($key === '' || (!in_array($key,$publicMediaKeys,true) && !preg_match('/^facility_logo_[a-f0-9]{20}$/i',$key)) || !preg_match('/^[a-z0-9_]+$/', $key)) {
    http_response_code(404); exit;
}
try {
    $st = $pdo->prepare('SELECT mime_type, image_data, updated_at FROM system_media WHERE media_key=? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch();
    if ($row && $row['image_data'] !== null && $row['image_data'] !== '') {
        $etag='"fams-media-'.sha1((string)$key.'|'.(string)($row['updated_at'] ?? '').'|'.sha1((string)$row['image_data'])).'"';
        header('ETag: '.$etag);
        header('Cache-Control: public, max-age=604800, stale-while-revalidate=2592000');
        header('Content-Type: '.(string)$row['mime_type']);
        header('X-Content-Type-Options: nosniff');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
        echo $row['image_data'];
        exit;
    }

    // Resilient fallback for installations where a system_media row has not
    // yet been seeded. The database remains the primary source; these bundled
    // files prevent broken logos/avatars during an upgrade or recovery.
    $fallbacks = [
        'default_user_avatar' => [__DIR__.'/assets/avatars/default-user-silhouette.webp', 'image/webp'],
        'default_admin_avatar' => [__DIR__.'/assets/avatars/default-site-admin-avatar.webp', 'image/webp'],
        'lpgma_logo' => [__DIR__.'/assets/branding/lpgma-logo.webp', 'image/webp'],
        'simc_logo' => [__DIR__.'/assets/branding/simc-logo.webp', 'image/webp'],
        'cdh_logo' => [__DIR__.'/assets/branding/cdh-logo.webp', 'image/webp'],
        'cimc_logo' => [__DIR__.'/assets/branding/cimc-logo.webp', 'image/webp'],
        'cvmc_logo' => [__DIR__.'/assets/branding/cvmc-logo.webp', 'image/webp'],
    ];
    if (isset($fallbacks[$key]) && is_file($fallbacks[$key][0]) && is_readable($fallbacks[$key][0])) {
        $fallbackRaw=@file_get_contents($fallbacks[$key][0]);
        if ($fallbackRaw !== false && $fallbackRaw !== '') {
            $etag='"fams-fallback-'.sha1($key.'|'.sha1($fallbackRaw)).'"';
            header('ETag: '.$etag);
            header('Content-Type: '.$fallbacks[$key][1]);
            header('Cache-Control: public, max-age=604800, stale-while-revalidate=2592000');
            header('X-Content-Type-Options: nosniff');
            if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
            header('Content-Length: '.strlen($fallbackRaw));
            echo $fallbackRaw;
            exit;
        }
    }
    http_response_code(404); exit;
} catch (Throwable $e) {
    http_response_code(404);
}
