<?php
declare(strict_types=1);

require_once __DIR__ . '/shared_runtime.php';
require_once __DIR__ . '/account_runtime.php';

// Production-safe PHP diagnostics. The host's configured PHP error log remains the source of detail.
if (!defined('FAMS_DEBUG')) define('FAMS_DEBUG', false);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
if (!FAMS_DEBUG) ini_set('display_errors', '0');

/** Shared FAMS Region II session and identity services.
 *  The unified login authenticates against the central users table for LPGMA/MAIFIP accounts
 *  and aics_users for local SWAD accounts. Workspace-specific operational tables remain intact.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path' => '/',
    ]);
    session_start();
}

/** One cross-workspace idle and absolute session lifetime policy. */
if (!defined('FAMS_SESSION_IDLE_SECONDS')) define('FAMS_SESSION_IDLE_SECONDS', 3600);
if (!defined('FAMS_SESSION_ABSOLUTE_SECONDS')) define('FAMS_SESSION_ABSOLUTE_SECONDS', 43200);
if (!empty($_SESSION['user']['id'])) {
    $now=time();
    $started=(int)($_SESSION['fams_authenticated_at']??0);
    $last=(int)($_SESSION['fams_last_activity_at']??0);
    if ($started<=0) $_SESSION['fams_authenticated_at']=$started=$now;
    if ($last<=0) $_SESSION['fams_last_activity_at']=$last=$now;
    if (($now-$last)>FAMS_SESSION_IDLE_SECONDS || ($now-$started)>FAMS_SESSION_ABSOLUTE_SECONDS) {
        foreach (['user','fams_system','fams_identity','fams_lpgma_user_id','fams_user_type','fams_authenticated_at','fams_last_activity_at','fams_facility','csrf_token','portal_csrf','auth_csrf'] as $key) unset($_SESSION[$key]);
        $_SESSION['fams_session_expired']=true;
        session_regenerate_id(true);
    } else {
        $fetchMode=strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE']??''));
        $isBackgroundRequest=in_array($fetchMode,['cors','same-origin','no-cors'],true) || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        if(!$isBackgroundRequest) $_SESSION['fams_last_activity_at']=$now;
    }
}

/** Neutralize spreadsheet formula prefixes in CSV text cells. Numeric database values remain numeric. */
function fams_csv_safe_cell(mixed $value): mixed {
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    $text = (string)$value;
    if ($text !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/', $text)) return "'" . $text;
    return $text;
}

function fams_system(): string { return (string)($_SESSION['fams_system'] ?? ''); }
function fams_set_system(string $system): void {
    if (!in_array($system, ['maifip','aics'], true)) return;
    $_SESSION['fams_system'] = $system;
}
function fams_is_lpgma_role(?string $role): bool {
    $role=(string)$role; if(in_array($role,['admin','manager','encoder','representative','staff'],true))return true;
    $pdo=$GLOBALS['pdo']??null; if($pdo instanceof PDO && function_exists('fams_base_role')){ $base=fams_base_role($pdo,'MAIFIP',$role); return in_array($base,['admin','manager','encoder','representative','staff'],true); }
    return false;
}
function fams_lpgma_role_label(string $role): string {
    return match ($role) {
        'admin' => 'SITE Admin',
        'manager' => 'LPGMA Manager',
        'representative' => 'LPGMA Representative',
        'encoder', 'staff' => 'LPGMA Staff',
        default => ucwords(str_replace('_', ' ', $role)),
    };
}
function fams_lpgma_db(): ?PDO {
    // One shared database connection for the whole LFAMS application.
    try {
        require_once __DIR__ . '/database.php';
        return lfams_pdo();
    } catch (Throwable $e) {
        return null;
    }
}

function fams_find_lpgma_user(string $identifier): ?array {
    $db = fams_lpgma_db();
    if (!$db) return null;
    $st = $db->prepare("SELECT id,full_name,username,email,password_hash,role,facility,is_active,approval_status,auth_version,avatar_data,avatar_mime,avatar_filename FROM users WHERE username=? OR email=? OR full_name=? ORDER BY is_active DESC,id ASC");
    $st->execute([$identifier,$identifier,$identifier]);
    foreach ($st->fetchAll() as $candidate) {
        if ((int)$candidate['is_active'] === 1 && fams_is_lpgma_role((string)$candidate['role'])) return $candidate;
    }
    return null;
}
function fams_authenticate_lpgma(string $identifier, string $password): ?array {
    $db = fams_lpgma_db();
    if (!$db) return null;
    $st = $db->prepare("SELECT id,full_name,username,email,password_hash,role,facility,is_active,approval_status,auth_version,avatar_data,avatar_mime,avatar_filename FROM users WHERE username=? OR email=? OR full_name=? ORDER BY is_active DESC,id ASC");
    $st->execute([$identifier,$identifier,$identifier]);
    foreach ($st->fetchAll() as $candidate) {
        if ((int)$candidate['is_active'] !== 1 || !fams_is_lpgma_role((string)$candidate['role'])) continue;
        if (($candidate['approval_status'] ?? 'APPROVED') !== 'APPROVED') continue;
        if (password_verify($password, (string)$candidate['password_hash'])) return $candidate;
    }
    return null;
}
function fams_role_for_aics(string $role): string {
    $pdo=$GLOBALS['pdo']??null;
    if(!in_array($role,['admin','manager','representative','encoder','staff','social_worker'],true) && $pdo instanceof PDO && function_exists('fams_type_definition')){
        $custom=fams_type_definition($pdo,'MAIFIP',$role); if($custom && (int)$custom['is_active']===1 && (string)$custom['base_role']!=='admin') return $role;
    }
    $base=$role; if(!in_array($base,['admin','manager','representative','encoder','staff','social_worker'],true) && $pdo instanceof PDO && function_exists('fams_base_role'))$base=fams_base_role($pdo,'MAIFIP',$role);
    return match ($base) { 'admin'=>'admin', 'manager','representative'=>'manager', 'encoder','staff'=>'staff', default=>'staff' };
}
function fams_begin_login(string $system, array $user, array $extra = []): void {
    // Preserve the authoritative LPGMA bridge identity across system switches.
    // Some older/stale sessions reached the portal without fams_lpgma_user_id;
    // the switch endpoints now recover it before calling this function.
    $lpgmaId = isset($extra['lpgma_user_id']) && (int)$extra['lpgma_user_id'] > 0
        ? (int)$extra['lpgma_user_id']
        : null;
    $authenticatedAt = !empty($_SESSION['user']['id']) && !empty($_SESSION['fams_authenticated_at']) ? (int)$_SESSION['fams_authenticated_at'] : time();
    session_regenerate_id(true);
    $_SESSION['fams_authenticated_at'] = $authenticatedAt;
    $_SESSION['fams_last_activity_at'] = time();
    unset($_SESSION['fams_session_expired'],$_SESSION['fams_session_invalidated'],$_SESSION['fams_session_invalid_reason']);
    $_SESSION['fams_system'] = $system;
    $_SESSION['fams_identity'] = $extra['identity_source'] ?? 'local';
    $_SESSION['fams_lpgma_user_id'] = $lpgmaId;
    $_SESSION['user'] = $user;
}
/** Validate the authoritative LPGMA identity even while the user is in the portal selector. */
function fams_validate_lpgma_session(): bool {
    $id=fams_current_lpgma_user_id();
    if($id<1) return false;
    $db=fams_lpgma_db();
    if(!$db){fams_shared_invalidate_session('LPGMA identity could not be verified.');return false;}
    try{
        fams_account_ensure_session_version_schema($db);
        $st=$db->prepare("SELECT is_active,approval_status,auth_version,role FROM users WHERE id=? LIMIT 1");
        $st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['is_active']!==1||(string)($row['approval_status']??'APPROVED')!=='APPROVED'||!fams_is_lpgma_role((string)$row['role'])){fams_shared_invalidate_session('LPGMA account inactive, removed, or no longer authorized.');return false;}
        $sessionVersion=(int)($_SESSION['user']['auth_version']??0);$currentVersion=(int)($row['auth_version']??1);
        if($sessionVersion>0&&$sessionVersion!==$currentVersion){fams_shared_invalidate_session('LPGMA account security settings changed.');return false;}
        $_SESSION['user']['auth_version']=$currentVersion;
        return true;
    }catch(Throwable $e){error_log('FAMS LPGMA session validation failed: '.$e->getMessage());fams_shared_invalidate_session('LPGMA identity verification failed.');return false;}
}
function fams_current_lpgma_user_id(): int {
    $id = (int)($_SESSION['fams_lpgma_user_id'] ?? 0);
    if ($id > 0) return $id;
    $current = $_SESSION['user'] ?? [];
    $bridgeId = (int)($current['lpgma_user_id'] ?? 0);
    if ($bridgeId > 0) return $bridgeId;
    return 0;
}
function fams_lpgma_can_cross(array $user): bool { return fams_is_lpgma_role((string)($user['role'] ?? '')); }
/** Canonical LPGMA account registry shared by MAIFIP and AICS. Local AICS-only accounts remain in aics_users. */
function fams_list_lpgma_accounts(PDO $db): array {
    $sql = "SELECT id,full_name,username,email,role,facility,is_active,approval_status,auth_version,avatar_mime,avatar_filename FROM users WHERE role IN ('admin','manager','encoder','representative','staff') AND (facility IS NULL OR TRIM(facility)='' OR UPPER(TRIM(facility))='LPGMA') ORDER BY full_name, id";
    try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

function fams_shared_identity(array $workspaceUser, string $system): array {
    // The central user id, not the workspace's mapped role, determines LPGMA identity.
    // This keeps name, username, email, role and avatar identical when switching workspaces.
    $id = fams_current_lpgma_user_id();
    if ($id > 0) {
        $db = fams_lpgma_db();
        if ($db) {
            try {
                $st = $db->prepare('SELECT id,full_name,username,email,role,facility,auth_version,avatar_data,avatar_mime,avatar_filename FROM users WHERE id=? AND is_active=1 LIMIT 1');
                $st->execute([$id]);
                $central = $st->fetch(PDO::FETCH_ASSOC);
                if ($central && fams_is_lpgma_role((string)$central['role'])) {
                    $central['_fams_avatar_url'] = '../core/avatar.php?user='.(int)$central['id'];
                    $central['_fams_identity_source'] = 'LPGMA';
                    // Preserve workspace-only fields without allowing them to replace central identity fields.
                    return array_merge($workspaceUser, $central);
                }
            } catch (Throwable $e) { /* retain the authenticated workspace identity */ }
        }
    }
    $localId = (int)($workspaceUser['id'] ?? 0);
    $workspaceUser['_fams_avatar_url'] = $localId > 0 ? 'avatar.php?user='.$localId : '';
    $workspaceUser['_fams_identity_source'] = 'LOCAL';
    return $workspaceUser;
}
function fams_shared_role_label(string $role): string {
    $pdo=$GLOBALS['pdo']??null; if($pdo instanceof PDO && function_exists('fams_type_definition')) { $d=fams_type_definition($pdo,'MAIFIP',$role); if($d && !empty($d['label'])) return (string)$d['label']; }
    $known=['admin'=>'SITE Admin','manager'=>'LPGMA Manager','representative'=>'LPGMA Representative','encoder'=>'LPGMA Staff','staff'=>'LPGMA Staff','social_worker'=>'Social Worker'];
    return $known[$role]??ucwords(str_replace('_', ' ', $role));
}
function fams_shared_avatar_markup(array $identity, string $class = ''): string {
    $url = (string)($identity['_fams_avatar_url'] ?? '');
    $role = (string)($identity['role'] ?? 'staff');
    $uid = (int)($identity['id'] ?? 0);
    $outer = trim($class);
    $fallback = $role === 'admin' ? '../core/branding/default-admin-avatar.webp' : '../core/branding/default-regular-avatar.webp';
    $src = $url !== '' ? $url : $fallback;
    return '<span class="fams-shared-avatar '.e($outer).'" data-avatar-user="'.$uid.'"><img src="'.e($src).'" width="256" height="256" alt="Profile avatar" loading="eager" decoding="async" onerror="this.onerror=null;this.src=\''.e($fallback).'\';"></span>';
}
function fams_version(): string { return defined('FAMS_VERSION') ? FAMS_VERSION : '0.0085'; }

/**
 * Synchronize one authorized LPGMA identity into the AICS users table (aics_users).
 * Idempotent: safe to call repeatedly when a user returns to the portal or
 * switches systems multiple times. Existing local AICS users are never
 * overwritten unless they are already marked as the same LPGMA bridge.
 */
function fams_sync_aics_shadow(PDO $pdo, array $lpgma, int $officeId = 1): ?array {
    $lpgmaId=(int)($lpgma['id']??0);
    if($lpgmaId<=0 || !fams_is_lpgma_role((string)($lpgma['role']??''))) return null;
    $role=fams_role_for_aics((string)$lpgma['role']);
    $username=trim((string)($lpgma['username']??''));
    $email=trim((string)($lpgma['email']??''));
    if($username==='') return null;

    // 1) Exact bridge match is authoritative.
    $st=$pdo->prepare("SELECT * FROM aics_users WHERE lpgma_user_id=? LIMIT 1");
    $st->execute([$lpgmaId]);
    $local=$st->fetch();

    // 2) Recover an older bridge if the lpgma_user_id column was added after
    // an earlier synchronization, matching by username/email only when the
    // row is already identified as a bridge.
    if(!$local){
        $st=$pdo->prepare("SELECT * FROM aics_users WHERE (identity_source='LPGMA_BRIDGED' AND (username=? OR (?<>'' AND email=?))) ORDER BY id ASC LIMIT 1");
        $st->execute([$username,$email,$email]);
        $local=$st->fetch();
    }

    if($local){
        // Email is unique in AICS. A bridged account can legitimately have the
        // same email as an existing local account (for example, an administrator
        // who was created in AICS before the unified switch was introduced).
        // Never overwrite that other row and never let the switch die on a 1062.
        $syncEmail = $email !== '' ? $email : null;
        if($syncEmail !== null){
            $em=$pdo->prepare("SELECT id FROM aics_users WHERE email=? AND id<>? LIMIT 1");
            $em->execute([$syncEmail,(int)$local['id']]);
            if($em->fetchColumn()) $syncEmail=null;
        }
        $up=$pdo->prepare("UPDATE aics_users SET full_name=?,email=?,password_hash=?,role=?,office_id=?,is_active=?,auth_version=?,identity_source='LPGMA_BRIDGED',lpgma_user_id=?,identity_synced_at=NOW(),updated_at=NOW() WHERE id=?");
        // Do not change username here. This avoids colliding with an existing
        // AICS local username while retaining the single LPGMA identity.
        $up->execute([$lpgma['full_name']??$local['full_name'],$syncEmail,$lpgma['password_hash']??$local['password_hash'],$role,$officeId,(int)($lpgma['is_active']??1),(int)($lpgma['auth_version']??1),$lpgmaId,(int)$local['id']]);
        $localId=(int)$local['id'];
    } else {
        // Username is globally unique in AICS. If a local AICS account already
        // owns it, give the technical bridge a deterministic internal alias.
        $candidate=$username;
        $st=$pdo->prepare("SELECT id,identity_source FROM aics_users WHERE username=? LIMIT 1");
        $st->execute([$candidate]);
        $collision=$st->fetch();
        if($collision){
            $candidate=$username.'__LPGMA_'.$lpgmaId;
            $suffix=1;
            while(true){
                $q=$pdo->prepare("SELECT id FROM aics_users WHERE username=? LIMIT 1"); $q->execute([$candidate]);
                if(!$q->fetchColumn()) break;
                $candidate=$username.'__LPGMA_'.$lpgmaId.'_'.$suffix++;
            }
        }
        // Email is also unique. Preserve it when free; otherwise leave the
        // shadow email NULL because the LPGMA account remains authoritative.
        $shadowEmail=$email!==''?$email:null;
        if($shadowEmail!==null){
            $st=$pdo->prepare("SELECT id FROM aics_users WHERE email=? LIMIT 1"); $st->execute([$shadowEmail]);
            if($st->fetchColumn()) $shadowEmail=null;
        }
        $up=$pdo->prepare("INSERT INTO aics_users(full_name,username,email,password_hash,role,office_id,is_active,auth_version,identity_source,lpgma_user_id,identity_synced_at,created_at,updated_at) VALUES(?,?,?,?,?,?,1,?,'LPGMA_BRIDGED',?,NOW(),NOW(),NOW())");
        $up->execute([$lpgma['full_name'],$candidate,$shadowEmail,$lpgma['password_hash'],$role,$officeId,(int)($lpgma['auth_version']??1),$lpgmaId]);
        $localId=(int)$pdo->lastInsertId();
    }
    $q=$pdo->prepare("SELECT u.*,o.name office_name FROM aics_users u LEFT JOIN aics_offices o ON o.id=u.office_id WHERE u.id=? LIMIT 1");
    $q->execute([$localId]);
    return $q->fetch() ?: null;
}

