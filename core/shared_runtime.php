<?php
declare(strict_types=1);

/** Cross-workspace primitives. Business rules and workspace data remain in their adapters. */
function fams_shared_escape(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fams_shared_redirect(string $url): never { header('Location: '.$url); exit; }
function fams_shared_logged_in(): bool { return isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] > 0; }
function fams_shared_current_user(): ?array { return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null; }
function fams_shared_csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function fams_shared_verify_csrf(?string $token = null, bool $json = false): void {
    $token = $token ?? (string)($_POST['csrf_token'] ?? '');
    $valid = $token !== '' && isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
    if ($valid) return;
    http_response_code(419);
    if ($json) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'Invalid or expired request token.'], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
    exit('Invalid or expired request token.');
}
function fams_shared_flash_set(string $type, string $message): void { $_SESSION['flash']=['type'=>$type,'message'=>$message]; }
function fams_shared_flash_take(): ?array { $flash=$_SESSION['flash']??null; unset($_SESSION['flash']); return is_array($flash)?$flash:null; }
/** Shared HTTP request contract primitives; workspace adapters still decide capabilities and ownership. */
function fams_shared_request_method(): string { return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')); }
function fams_shared_require_method(array $allowed): void {
    $allowed=array_values(array_unique(array_map(static fn($m)=>strtoupper((string)$m),$allowed)));
    if(!in_array(fams_shared_request_method(),$allowed,true)){
        header('Allow: '.implode(', ',$allowed));
        fams_shared_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    }
}
function fams_shared_request_data(): array {
    $method=fams_shared_request_method();
    if($method==='GET') return $_GET;
    if($method==='POST') return $_POST;
    $type=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
    if(str_contains($type,'application/json')){
        $raw=file_get_contents('php://input');$decoded=is_string($raw)?json_decode($raw,true):null;
        return is_array($decoded)?$decoded:[];
    }
    return [];
}
function fams_shared_json_success(array $data = [], int $status = 200): never { fams_shared_json(['ok'=>true,'data'=>$data],$status); }
function fams_shared_json_error(string $message, int $status = 400, array $details = []): never { fams_shared_json(['ok'=>false,'error'=>$message] + ($details ? ['details'=>$details] : []),$status); }

function fams_shared_json(array $payload, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
/** Execute a unit of work atomically; nested calls use savepoints without committing the caller's transaction. */
function fams_shared_transaction(PDO $pdo, callable $work): mixed {
    static $savepointSequence = 0;
    $ownsTransaction = !$pdo->inTransaction();
    $savepoint = 'fams_sp_' . (++$savepointSequence);
    if ($ownsTransaction) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $result = $work($pdo);
        if ($ownsTransaction) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        elseif ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $error;
    }
}

/** Retry only a top-level, database-only transaction after a recognized transient InnoDB conflict.
 * The callback may execute more than once: do not perform email, file, HTTP, or other external side effects in it.
 */
function fams_shared_transaction_retry(PDO $pdo, callable $work, int $maxAttempts = 3): mixed {
    if ($pdo->inTransaction()) return fams_shared_transaction($pdo, $work);
    $maxAttempts = max(1, min(5, $maxAttempts));
    for ($attempt = 1; ; $attempt++) {
        try { return fams_shared_transaction($pdo, $work); }
        catch (Throwable $e) {
            $vendor=(int)($e instanceof PDOException ? ($e->errorInfo[1]??0) : 0);
            $sqlState=(string)$e->getCode();
            $retryable=($sqlState==='40001' || $vendor===1213 || $vendor===1205);
            if (!$retryable || $attempt >= $maxAttempts) throw $e;
            // Bounded exponential backoff with jitter; never retry validation/constraint errors.
            usleep((int)(25000 * (2 ** ($attempt - 1)) + random_int(0, 20000)));
        }
    }
}

/** Return a safe message for a database failure without leaking SQL, schema, or credentials. */
function fams_shared_user_error(Throwable $error, string $fallback = 'The operation could not be completed. Please try again.'): string {
    if ($error instanceof InvalidArgumentException || $error instanceof FamsIdempotencyConflict) return $error->getMessage();
    if ($error instanceof PDOException) {
        $vendor=(int)($error->errorInfo[1]??0);
        if ($vendor===1213 || $vendor===1205 || (string)$error->getCode()==='40001') return 'The record is being updated by another request. Please try again.';
        if ($vendor===1062 || (string)$error->getCode()==='23000') return 'This information conflicts with an existing record. Refresh the page and check for duplicates.';
        error_log('FAMS database operation failed: '.$error->getMessage());
        return $fallback;
    }
    if ($error instanceof RuntimeException || $error instanceof LogicException) return $error->getMessage();
    error_log('FAMS operation failed: '.$error->getMessage());
    return $fallback;
}

/** Generate a one-use form key. The key is bound to user + operation in the database. */
function fams_shared_idempotency_key(): string { return bin2hex(random_bytes(24)); }
/** Canonicalize request data before hashing so equivalent associative payloads produce the same digest. */
function fams_shared_payload_fingerprint(?array $payload = null): string {
    $payload = $payload ?? (is_array($_POST ?? null) ? $_POST : []);
    $excluded = ['csrf_token','idempotency_key','_token'];
    $normalize = static function ($value) use (&$normalize, $excluded) {
        if (!is_array($value)) {
            if (is_object($value)) return '[unsupported-object]';
            if (is_resource($value)) return '[unsupported-resource]';
            return $value;
        }
        $isList = array_is_list($value);
        if (!$isList) ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $item) {
            if (!$isList && in_array((string)$key, $excluded, true)) continue;
            $out[$key] = $normalize($item);
        }
        return $out;
    };
    $json = json_encode($normalize($payload), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($json)) throw new RuntimeException('Unable to verify this submission. Please reload the form.');
    return hash('sha256', $json);
}

final class FamsIdempotencyConflict extends RuntimeException {}

/** Ensure schema exists before a business transaction; MySQL DDL implicitly commits. */
function fams_shared_ensure_idempotency_schema(PDO $pdo): void {
    static $ready = [];
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') throw new RuntimeException('The idempotency service requires the configured MySQL database.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS fams_idempotency_keys (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, scope_key VARCHAR(80) NOT NULL, request_key CHAR(64) NOT NULL, payload_hash CHAR(64) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_fams_idempotency_user_scope_key (user_id, scope_key, request_key), KEY idx_fams_idempotency_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (empty($ready[$driver])) {
        $columns = $pdo->query("SHOW COLUMNS FROM fams_idempotency_keys LIKE 'payload_hash'")->fetch(PDO::FETCH_ASSOC);
        if (!$columns) {
            try {
                $pdo->exec("ALTER TABLE fams_idempotency_keys ADD COLUMN payload_hash CHAR(64) NOT NULL DEFAULT '' AFTER request_key");
            } catch (PDOException $migrationError) {
                // Two first requests may attempt the compatibility ALTER together.
                $code=(string)$migrationError->getCode();$vendor=(int)($migrationError->errorInfo[1]??0);
                if ($code!=='42S21' && $vendor!==1060) throw $migrationError;
            }
            $columns = $pdo->query("SHOW COLUMNS FROM fams_idempotency_keys LIKE 'payload_hash'")->fetch(PDO::FETCH_ASSOC);
            if (!$columns) throw new RuntimeException('The idempotency payload fingerprint column could not be prepared.');
        }
        $ready[$driver] = true;
    }
}
/** Claim a submission key atomically. Same key/same payload is a retry; same key/different payload is a conflict. */
function fams_shared_idempotency_claim(PDO $pdo, string $scope, string $key, int $userId, ?array $payload = null): bool {
    $scope = preg_replace('/[^a-z0-9_.:-]/i', '_', trim($scope)) ?: 'general';
    if ($key === '' || strlen($key) < 32 || strlen($key) > 128 || $userId < 1) throw new InvalidArgumentException('The submission key is missing or invalid. Reload the form and try again.');
    if (!$pdo->inTransaction()) throw new LogicException('Idempotency claims must be committed atomically with the requested operation.');
    fams_shared_ensure_idempotency_schema($pdo);
    $digest = hash('sha256', $key);
    $payloadHash = fams_shared_payload_fingerprint($payload);
    try {
        $stmt = $pdo->prepare('INSERT INTO fams_idempotency_keys(user_id,scope_key,request_key,payload_hash) VALUES(?,?,?,?)');
        $stmt->execute([$userId, $scope, $digest, $payloadHash]);
        return true;
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000' && (int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        $check = $pdo->prepare('SELECT payload_hash FROM fams_idempotency_keys WHERE user_id=? AND scope_key=? AND request_key=? LIMIT 1');
        $check->execute([$userId, $scope, $digest]);
        $existing = (string)$check->fetchColumn();
        // Legacy keys have an empty fingerprint; do not silently treat a changed payload as safe.
        if ($existing !== '' && !hash_equals($existing, $payloadHash)) throw new FamsIdempotencyConflict('This submission key was already used with different information. Reload the form and submit again.');
        if ($existing === '' && !hash_equals(hash('sha256', '{}'), $payloadHash)) throw new FamsIdempotencyConflict('This submission key belongs to an earlier request. Reload the form and submit again.');
        return false;
    }
}
/** Run a business operation once per user/scope/form key, atomically with its idempotency claim. */
function fams_shared_idempotent_transaction(PDO $pdo, string $scope, string $key, int $userId, callable $work, ?array $payload = null): array {
    fams_shared_ensure_idempotency_schema($pdo);
    return fams_shared_transaction($pdo, function(PDO $db) use ($scope, $key, $userId, $work, $payload): array {
        if (!fams_shared_idempotency_claim($db, $scope, $key, $userId, $payload)) return ['duplicate'=>true, 'result'=>null];
        return ['duplicate'=>false, 'result'=>$work($db)];
    });
}
function fams_shared_normalize_search(string $query, int $maxLength = 120): string {
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if (function_exists('mb_substr')) return mb_substr($query, 0, $maxLength, 'UTF-8');
    return substr($query, 0, $maxLength);
}
function fams_shared_page_window(int $total, int $requestedPage, int $perPage): array {
    $perPage = max(1, min(500, $perPage));
    $pages = max(1, (int)ceil(max(0, $total) / $perPage));
    $page = max(1, min($pages, $requestedPage));
    return ['page'=>$page, 'per_page'=>$perPage, 'pages'=>$pages, 'offset'=>($page-1)*$perPage, 'total'=>max(0,$total)];
}
/** Decode and inspect an image data URL before it is persisted. Returns bytes and trusted MIME. */
function fams_shared_decode_image_data_url(string $dataUrl, int $maxBytes = 2097152, array $allowedMimes = ['image/jpeg','image/png','image/webp']): array {
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$#i', trim($dataUrl), $m)) throw new RuntimeException('Please provide a valid JPG, PNG, or WebP image.');
    $raw = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($raw === false || $raw === '' || strlen($raw) > $maxBytes) throw new RuntimeException('The image is invalid or exceeds the allowed upload size.');
    $info = @getimagesizefromstring($raw); $mime = strtolower((string)($info['mime'] ?? ''));
    if (!$info || !in_array($mime, $allowedMimes, true)) throw new RuntimeException('The image contents are invalid or use an unsupported format.');
    if ((int)($info[0]??0) > 6000 || (int)($info[1]??0) > 6000) throw new RuntimeException('The image dimensions are too large. Please resize it and try again.');
    return ['bytes'=>$raw, 'mime'=>$mime, 'width'=>(int)$info[0], 'height'=>(int)$info[1]];
}

function fams_shared_validate_uploaded_image(array $upload, int $maxBytes = 2097152, array $allowedMimes = ['image/jpeg','image/png','image/webp']): ?array {
    if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) return null;
    $size=(int)($upload['size']??0); if($size<=0 || $size>$maxBytes) return null;
    $info=@getimagesize((string)$upload['tmp_name']); $mime=strtolower((string)($info['mime']??''));
    if(!$info || !in_array($mime,$allowedMimes,true) || (int)($info[0]??0)>6000 || (int)($info[1]??0)>6000) return null;
    $raw=@file_get_contents((string)$upload['tmp_name']); if(!is_string($raw)||$raw==='') return null;
    return [$raw,$mime];
}
function fams_shared_validate_account_fields(string $fullName, string $username, string $email = ''): array {
    $fullName=trim($fullName); $username=trim($username); $email=trim($email);
    if($fullName==='' || $username==='') throw new RuntimeException('Full name and username are required.');
    if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address or leave it blank.');
    $length=static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value,'UTF-8') : strlen($value);
    if($length($username)>150 || $length($fullName)>150 || $length($email)>150) throw new RuntimeException('One or more account fields exceed the allowed length.');
    return ['full_name'=>$fullName,'username'=>$username,'email'=>$email];
}
function fams_shared_validate_password(string $password, int $minimumLength = 6): void {
    $length=function_exists('mb_strlen') ? mb_strlen($password,'UTF-8') : strlen($password);
    if($length<max(6,$minimumLength)) throw new RuntimeException('Password must be at least '.max(6,$minimumLength).' characters.');
}
