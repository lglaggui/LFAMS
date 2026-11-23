<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

$userId = (int)($_GET['user'] ?? 0);
if ($userId <= 0) { http_response_code(404); exit; }

function fams_avatar_fallback_file(string $role): array {
    if ($role === 'admin') {
        return [__DIR__.'/assets/avatars/default-site-admin-avatar.webp', 'image/webp', 'default_admin_avatar'];
    }
    return [__DIR__.'/assets/avatars/default-user-silhouette.webp', 'image/webp', 'default_user_avatar'];
}

try {
    // This endpoint intentionally decides whether to serve the user's uploaded
    // avatar OR the correct shared default. Pages can therefore use one stable
    // avatar URL without first having to know whether the user uploaded a photo.
    $st = $pdo->prepare('SELECT id, username, role, avatar_mime, avatar_data, avatar_filename, updated_at FROM users WHERE id=? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit; }

    $mime = strtolower((string)($row['avatar_mime'] ?? ''));
    $data = $row['avatar_data'] ?? null;
    $isCustom = is_string($data) && $data !== '' && in_array($mime, ['image/jpeg','image/png','image/webp'], true);

    if ($isCustom) {
        $filename=(string)($row['avatar_filename'] ?? '');
        if($filename===''){ $filename=fams_avatar_filename((string)($row['username']??'user'),$userId,$mime); }
        $etag = '"fams-avatar-'.sha1((string)$userId.'|custom|'.(string)($row['updated_at'] ?? '').'|'.sha1($data)).'"';
        header('ETag: '.$etag);
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Content-Type: '.$mime);
        header('Content-Disposition: inline; filename="'.preg_replace('/[^A-Za-z0-9._-]+/','_', $filename).'"');
        header('X-FAMS-Avatar-User: '.$userId);
        header('X-FAMS-Avatar-Type: custom');
        header('X-Content-Type-Options: nosniff');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
        header('Content-Length: '.strlen($data));
        echo $data;
        exit;
    }

    $role = strtolower((string)($row['role'] ?? ''));
    $fallback = fams_avatar_fallback_file($role);
    $defaultKey = $fallback[2];

    // Database-backed defaults are authoritative. The filesystem copy is only a
    // migration-safe fallback if the administrator has not run the media SQL yet.
    $defaultData = null;
    $defaultMime = null;
    $defaultUpdated = '';
    $media = $pdo->prepare('SELECT mime_type, image_data, updated_at FROM system_media WHERE media_key=? LIMIT 1');
    $media->execute([$defaultKey]);
    $mediaRow = $media->fetch();
    if ($mediaRow && $mediaRow['image_data'] !== null && $mediaRow['image_data'] !== '') {
        $candidateMime = strtolower((string)$mediaRow['mime_type']);
        if (in_array($candidateMime, ['image/jpeg','image/png','image/webp','image/svg+xml'], true)) {
            $defaultData = (string)$mediaRow['image_data'];
            $defaultMime = $candidateMime;
            $defaultUpdated = (string)($mediaRow['updated_at'] ?? '');
        }
    }
    if ($defaultData === null && is_file($fallback[0]) && is_readable($fallback[0])) {
        $raw = @file_get_contents($fallback[0]);
        if ($raw !== false && $raw !== '') {
            $defaultData = $raw;
            $defaultMime = $fallback[1];
            $defaultUpdated = (string)@filemtime($fallback[0]);
        }
    }
    if ($defaultData === null || $defaultMime === null) { http_response_code(404); exit; }

    $etag = '"fams-avatar-default-'.sha1($defaultKey.'|'.$defaultUpdated.'|'.sha1($defaultData)).'"';
    header('ETag: '.$etag);
    header('Cache-Control: public, max-age=604800, stale-while-revalidate=2592000');
    header('Content-Type: '.$defaultMime);
    header('Content-Disposition: inline; filename="'.($role==='admin'?'default-site-admin-avatar.webp':'default-user-silhouette.webp').'"');
    header('X-FAMS-Avatar-User: '.$userId);
    header('X-FAMS-Avatar-Type: default');
    header('X-FAMS-Avatar-Role: '.$role);
    header('X-Content-Type-Options: nosniff');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    header('Content-Length: '.strlen($defaultData));
    echo $defaultData;
} catch (Throwable $e) {
    http_response_code(404);
}
