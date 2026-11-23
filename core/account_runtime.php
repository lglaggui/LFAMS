<?php
declare(strict_types=1);
require_once __DIR__.'/shared_runtime.php';

/** Add a monotonic account version used to revoke every previously issued session. */
function fams_account_ensure_session_version_schema(PDO $pdo): void {
    static $ready = [];
    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) return;
    if ($pdo->inTransaction()) throw new LogicException('Account session schema must be verified before a business transaction.');
    foreach (['users', 'aics_users'] as $table) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='auth_version'");
        $check->execute([$table]);
        if ((int)$check->fetchColumn() === 0) {
            try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1"); }
            catch (PDOException $e) {
                $vendor=(int)($e->errorInfo[1]??0);
                if ((string)$e->getCode()!=='42S21' && $vendor!==1060) throw $e;
            }
            $check->execute([$table]);
            if ((int)$check->fetchColumn() === 0) throw new RuntimeException("The {$table} session-version migration could not be verified.");
        }
    }
    $ready[$key] = true;
}

/** Revoke sessions by incrementing the authoritative account version inside its transaction. */
function fams_account_bump_auth_version(PDO $pdo, string $workspace, int $userId): void {
    if (!$pdo->inTransaction()) throw new LogicException('Account session invalidation must share the account mutation transaction.');
    if ($userId < 1) throw new InvalidArgumentException('A valid account ID is required.');
    $table = match (strtolower($workspace)) { 'aics'=>'aics_users', 'maifip'=>'users', default=>throw new InvalidArgumentException('Unknown account workspace.') };
    $st=$pdo->prepare("UPDATE {$table} SET auth_version=auth_version+1 WHERE id=?");
    $st->execute([$userId]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Account session version could not be advanced.');
}

/** Shared personal-account operations; storage adapters preserve each workspace schema. */
function fams_account_store_own_avatar(PDO $pdo, string $workspace, int $userId, string $dataUrl, int $maxBytes = 2097152): string {
    if($userId<1) throw new RuntimeException('The account identifier is invalid.');
    $image=fams_shared_decode_image_data_url($dataUrl,$maxBytes);
    if($workspace==='aics') {
        $st=$pdo->prepare('UPDATE aics_users SET avatar_mime=?,avatar_data=? WHERE id=?');
        $st->execute([$image['mime'],$image['bytes'],$userId]);
    } elseif($workspace==='maifip') {
        $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$image['mime']];
        $filename='avatar_'.$userId.'_'.date('YmdHis').'.'.$ext;
        $st=$pdo->prepare('UPDATE users SET avatar_data=?,avatar_mime=?,avatar_filename=? WHERE id=?');
        $st->execute([$image['bytes'],$image['mime'],$filename,$userId]);
    } else throw new InvalidArgumentException('Unknown account storage adapter.');
    if($st->rowCount()===0) {
        $check=$pdo->prepare($workspace==='aics'?'SELECT id FROM aics_users WHERE id=?':'SELECT id FROM users WHERE id=?');
        $check->execute([$userId]); if(!$check->fetchColumn()) throw new RuntimeException('The account could not be found.');
    }
    return $image['mime'];
}
function fams_account_revert_own_avatar(PDO $pdo, string $workspace, int $userId): void {
    if($userId<1) throw new RuntimeException('The account identifier is invalid.');
    if($workspace==='aics') $st=$pdo->prepare('UPDATE aics_users SET avatar_mime=NULL,avatar_data=NULL WHERE id=?');
    elseif($workspace==='maifip') $st=$pdo->prepare('UPDATE users SET avatar_data=NULL,avatar_mime=NULL,avatar_filename=NULL WHERE id=?');
    else throw new InvalidArgumentException('Unknown account storage adapter.');
    $st->execute([$userId]); if($st->rowCount()===0) { $table=$workspace==='aics'?'aics_users':'users'; $check=$pdo->prepare("SELECT id FROM {$table} WHERE id=?");$check->execute([$userId]);if(!$check->fetchColumn())throw new RuntimeException('The account could not be found.'); }
}
function fams_account_change_own_password(PDO $pdo, string $workspace, int $userId, string $current, string $new, string $confirm): void {
    $table=$workspace==='aics'?'aics_users':($workspace==='maifip'?'users':''); if($table==='') throw new InvalidArgumentException('Unknown account storage adapter.');
    fams_shared_validate_password($new);
    if($new!==$confirm) throw new RuntimeException('New passwords do not match.');
    $st=$pdo->prepare("SELECT password_hash FROM {$table} WHERE id=? LIMIT 1");$st->execute([$userId]);$hash=(string)$st->fetchColumn();
    if($hash==='' || !password_verify($current,$hash)) throw new RuntimeException('Current password is incorrect.');
    $pdo->prepare("UPDATE {$table} SET password_hash=?,auth_version=auth_version+1 WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$userId]);
}
