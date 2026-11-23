<?php
require_once dirname(__DIR__).'/core/shared_runtime.php';
require_once dirname(__DIR__).'/core/account_runtime.php';
require_once dirname(__DIR__).'/core/audit_runtime.php';
require_once dirname(__DIR__).'/core/workspace_repositories.php';
function e(?string $v): string { return fams_shared_escape($v); }
function redirect(string $url): never { fams_shared_redirect($url); }
function is_logged_in(): bool { return fams_shared_logged_in(); }
function current_user(): ?array { return fams_shared_current_user(); }
/** Refresh the AICS-local session from its own account table. MAIFIP's users-table
 * synchronizer must not be called from AICS endpoints (it is not defined here). */
function sync_aics_current_user_from_db(PDO $pdo): ?array {
    if (!is_logged_in()) return null;
    $session=(array)($_SESSION['user']??[]); $id=(int)($session['id']??0);
    if ($id<=0) return null;
    try {
        $st=$pdo->prepare('SELECT u.id,u.full_name,u.username,u.email,u.role,u.office_id,u.is_active,o.name AS office_name,u.identity_source,u.lpgma_user_id,u.auth_version FROM aics_users u LEFT JOIN aics_offices o ON o.id=u.office_id WHERE u.id=? LIMIT 1');
        $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active']!==1) { fams_shared_invalidate_session('AICS account inactive or removed.'); return null; }
        if ((string)($row['identity_source']??'')==='LPGMA_BRIDGED') {
            $bridgeId=(int)($row['lpgma_user_id']??0);
            $central=$pdo->prepare('SELECT is_active,auth_version,approval_status FROM users WHERE id=? LIMIT 1');
            $central->execute([$bridgeId]); $authority=$central->fetch(PDO::FETCH_ASSOC);
            if (!$authority || (int)$authority['is_active']!==1 || (string)($authority['approval_status']??'APPROVED')!=='APPROVED') { fams_shared_invalidate_session('Linked LPGMA account inactive or removed.'); return null; }
            $row['auth_version']=(int)$authority['auth_version'];
        }
        $sessionVersion=(int)($session['auth_version']??0); $currentVersion=(int)($row['auth_version']??1);
        if ($sessionVersion>0 && $sessionVersion!==$currentVersion) { fams_shared_invalidate_session('AICS account security settings changed.'); return null; }
        $storedRole=(string)$row['role']; $typeDef=function_exists('fams_type_definition')?fams_type_definition($pdo,'AICS',$storedRole):null;
        if(!$typeDef || (int)$typeDef['is_active']!==1){fams_shared_invalidate_session('AICS user type disabled.');return null;}
        $priorKey=(string)($session['user_type_key']??''); $priorPolicy=(string)($session['user_type_policy_hash']??''); $currentPolicy=hash('sha256',(string)($typeDef['permissions_json']??''));
        if(($priorKey!=='' && !hash_equals($priorKey,$storedRole)) || ($priorPolicy!=='' && !hash_equals($priorPolicy,$currentPolicy))){unset($_SESSION['user']);session_regenerate_id(true);return null;}
        $_SESSION['user']=[
            'id'=>(int)$row['id'],'auth_version'=>$currentVersion,'full_name'=>(string)$row['full_name'],'username'=>(string)$row['username'],
            'email'=>(string)($row['email']??''),'role'=>(string)($typeDef['base_role']??$storedRole),'user_type_key'=>$storedRole,'user_type_label'=>(string)($typeDef['label']??$storedRole),'user_type_policy_hash'=>hash('sha256',(string)($typeDef['permissions_json']??'')),'office_id'=>(int)($row['office_id']??0),
            'office_name'=>(string)($row['office_name']??''),'identity_source'=>(string)($row['identity_source']??'LOCAL_AICS'),
            'lpgma_user_id'=>(int)($row['lpgma_user_id']??0)
        ];
        return $_SESSION['user'];
    } catch (Throwable $e) { error_log('AICS session refresh failed closed: '.$e->getMessage()); fams_shared_invalidate_session('AICS identity verification failed.'); return null; }
}
function require_login(): void { if (!is_logged_in()) redirect('login.php'); }
function require_role(array $roles): void { require_login(); if (!in_array((string)(current_user()['role'] ?? ''), $roles, true)) { http_response_code(403); exit('Access denied.'); } }
function csrf_token(): string { return fams_shared_csrf_token(); }
function verify_csrf(): void { fams_shared_verify_csrf(); }
function flash(string $type,string $message): void { fams_shared_flash_set($type,$message); }
function get_flash(): ?array { return fams_shared_flash_take(); }
function user_can_edit_records(): bool { return in_array(current_user()['role']??'', ['admin','manager','staff','social_worker'], true) && fams_current_type_allows('AICS','edit_records'); }
function user_is_admin(): bool { return (current_user()['role']??'')==='admin'; }
function user_is_lpgma_admin(): bool { return user_is_admin() && (int)(current_user()['office_id']??0)===1; }
function facility_rows(PDO $pdo): array { return $pdo->query("SELECT * FROM aics_offices WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order,id")->fetchAll(); }
function subfacility_rows(PDO $pdo): array { return $pdo->query("SELECT * FROM aics_offices WHERE is_active=1 AND parent_id IS NOT NULL ORDER BY sort_order,id")->fetchAll(); }
function user_is_social_worker(): bool { return (current_user()['role']??'')==='social_worker'; }
function user_office_id(): ?int { return isset($_SESSION['user']['office_id']) && $_SESSION['user']['office_id'] ? (int)$_SESSION['user']['office_id'] : null; }
function log_action(PDO $pdo,string $action,string $description,?int $recordId=null): void { if($pdo->inTransaction()) fams_audit_write_in_transaction($pdo,'aics',$action,$description,$recordId); else fams_audit_write($pdo,'aics',$action,$description,$recordId); }
function full_name(array $r,string $prefix=''): string {
    $parts=[];
    foreach (['first_name','middle_name','last_name','name_extension'] as $p) { $v=trim((string)($r[$prefix.$p]??'')); if($v!=='') $parts[]=$v; }
    return trim(implode(' ',$parts));
}
function record_client_name(array $r): string { return full_name($r,'client_'); }
function record_beneficiary_name(array $r): string { return full_name($r,'beneficiary_'); }
function money(float $n): string { return '₱'.number_format($n,2); }
function status_class(?string $s): string {
    $s=(string)$s; return strtolower($s); }
function fa_types(): array { return ['MEDICAL','BURIAL','EDUCATIONAL','OTHER CASH','FOOD']; }
function office_rows(PDO $pdo): array { return $pdo->query("SELECT o.*,p.name parent_name FROM aics_offices o LEFT JOIN aics_offices p ON p.id=o.parent_id WHERE o.is_active=1 ORDER BY COALESCE(p.sort_order,o.sort_order),o.parent_id,o.sort_order,o.id")->fetchAll(); }
function office_name(PDO $pdo,?int $id): string {
    if(!$id) return 'Unassigned';
    $st=$pdo->prepare('SELECT name FROM aics_offices WHERE id=?'); $st->execute([$id]); return (string)($st->fetchColumn() ?: 'Unassigned');
}
function can_access_record_scope(array $r): bool {
    $role=current_user()['role']??'';
    if($role==='admin') return true;
    if($role==='manager') return (int)(current_user()['office_id']??0)===1;
    if($role==='staff') { $officeId=(int)(current_user()['office_id']??0); return $officeId===1 || ($officeId>1 && !empty($r['office_id']) && (int)$r['office_id']===$officeId); }
    if($role==='social_worker') return !empty($r['office_id']) && (int)$r['office_id']===(int)(current_user()['office_id']??0);
    return false;
}
function can_edit_record(array $r): bool { return fams_current_type_allows('AICS','edit_records') && can_access_record_scope($r); }

function page_url(string $file,array $params=[]): string {
    return $file.($params ? '?'.http_build_query($params) : '');
}
function query_value(string $key,string $default=''): string { return trim((string)($_GET[$key]??$default)); }


function role_label(string $role): string { $pdo=$GLOBALS['pdo']??null; if($pdo instanceof PDO && function_exists('fams_user_type_label')) return fams_user_type_label($pdo,'AICS',$role); return match($role){'admin'=>'Administrator','manager'=>'Manager','staff'=>'Staff','social_worker'=>'Social Worker',default=>ucwords(str_replace('_',' ',$role))}; }
function user_name_initials(string $name): string { $o=''; foreach(preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY) as $p){ $ch=function_exists('mb_substr')?mb_substr($p,0,1,'UTF-8'):substr($p,0,1); $o.=function_exists('mb_strtoupper')?mb_strtoupper($ch,'UTF-8'):strtoupper($ch); } return $o ?: 'U'; }
function office_is_lpgma(PDO $pdo, int $officeId): bool { return $officeId === 1; }
function office_is_swad_subfacility(PDO $pdo, int $officeId): bool { if($officeId<=0)return false; $st=$pdo->prepare('SELECT parent_id FROM aics_offices WHERE id=? AND is_active=1'); $st->execute([$officeId]); $p=$st->fetchColumn(); return $p!==false && (int)$p===2; }
function validate_account_assignment(PDO $pdo,string $role,int $officeId): void { if($officeId<=0)throw new RuntimeException('Facility/office is required.'); fams_type_assignment_valid($pdo,'AICS',$role,(string)$officeId); }
function swad_subfacility_rows(PDO $pdo): array { return $pdo->query("SELECT o.*,p.name parent_name FROM aics_offices o JOIN aics_offices p ON p.id=o.parent_id WHERE o.is_active=1 AND o.parent_id=2 ORDER BY o.sort_order,o.id")->fetchAll(); }
function ensure_record_schema(PDO $pdo): void {
    static $done=false; if($done)return;
    if($pdo->inTransaction()) throw new LogicException('AICS record schema must be verified before a business transaction.');
    $check=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_assistance_records' AND COLUMN_NAME=?");
    $columns=[
        'record_version'=>'INT UNSIGNED NOT NULL DEFAULT 1',
        'validity_days'=>'INT UNSIGNED NOT NULL DEFAULT 7 AFTER validity_status',
        'validity_expires_at'=>'DATE NULL AFTER validity_days',
        'quotation'=>'VARCHAR(180) NULL AFTER guarantee_amount',
    ];
    foreach($columns as $column=>$definition){
        $check->execute([$column]);
        if((int)$check->fetchColumn()===0) $pdo->exec("ALTER TABLE aics_assistance_records ADD COLUMN `{$column}` {$definition}");
    }
    $pdo->exec('UPDATE aics_assistance_records SET validity_days=7 WHERE validity_days IS NULL OR validity_days=0');
    $pdo->exec('UPDATE aics_assistance_records SET validity_expires_at=DATE_ADD(date_submission, INTERVAL validity_days DAY) WHERE validity_expires_at IS NULL');
    $pdo->exec("UPDATE aics_assistance_records SET office_id=CASE UPPER(province) WHEN 'ISABELA' THEN 3 WHEN 'CAGAYAN' THEN 8 WHEN 'NUEVA VIZCAYA' THEN 9 WHEN 'QUIRINO' THEN 10 WHEN 'BATANES' THEN 11 ELSE office_id END WHERE office_id=2");
    $done=true;
}
function expire_waiting_records(PDO $pdo): void {
    ensure_record_schema($pdo);
    try {
        // Expiry is a system-driven status transition. Lock each eligible row and
        // keep the record update, history entry, and audit event in one transaction.
        fams_shared_transaction($pdo, static function (PDO $db): void {
            $rows = $db->query("SELECT id,control_number,record_version FROM aics_assistance_records WHERE status='WAITING' AND validity_expires_at IS NOT NULL AND validity_expires_at < CURDATE() ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return;
            $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
            $update = $db->prepare("UPDATE aics_assistance_records SET status='CANCELLED',cancelled_flag=1,updated_by=?,record_version=record_version+1 WHERE id=? AND status='WAITING' AND record_version=?");
            foreach ($rows as $row) {
                $update->execute([$uid, (int)$row['id'], (int)$row['record_version']]);
                if ($update->rowCount() !== 1) continue;
                $reason = 'Automatically cancelled after the validity period expired.';
                fams_status_history_write($db,'aics',(int)$row['id'],'WAITING','CANCELLED',$uid,$reason);
                $audit = $db->prepare("INSERT INTO aics_action_logs(user_id,username,role,action,description,record_id,ip_address,user_agent) VALUES(NULL,?,?,?,?,?,NULL,NULL)");
                $audit->execute(['system', 'system', 'AUTO_CANCEL_EXPIRED', 'Automatically cancelled expired record '.(string)$row['control_number'].'.', (int)$row['id']]);
            }
        });
    } catch (Throwable $e) {
        error_log('AICS automatic record expiry failed: '.$e->getMessage());
    }
}
function facility_logo_markup(PDO $pdo,int $officeId): string { if($officeId===1)return '<span class="facility-indicator facility-lpgma"><img src="../core/branding/lpgma-shared-logo.webp" alt="LPGMA"><b>LPGMA</b></span>'; return '<span class="facility-indicator facility-swad"><img src="assets/branding/dswd-region2-sticker.png" alt="DSWD"><b>SWAD</b></span>'; }
function ensure_user_schema(PDO $pdo): void { /* role is VARCHAR to support administrator-managed user types. */ }
function ensure_avatar_columns(PDO $pdo): void {
    static $done=false; if($done)return;
    if($pdo->inTransaction()) throw new LogicException('AICS avatar schema must be verified before a business transaction.');
    $check=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_users' AND COLUMN_NAME=?");
    foreach(['avatar_mime'=>'VARCHAR(50) NULL AFTER is_active','avatar_data'=>'MEDIUMBLOB NULL AFTER avatar_mime'] as $column=>$definition){
        $check->execute([$column]); if((int)$check->fetchColumn()===0)$pdo->exec("ALTER TABLE aics_users ADD COLUMN `{$column}` {$definition}");
    }
    $done=true;
}
function avatar_markup(string $role, string $class='', ?int $userId=null): string {
    $uid=$userId ? (int)$userId : 0;
    $cls='avatar-img '.trim($class);
    $src=$uid ? 'avatar.php?user='.$uid.'&v='.rawurlencode(APP_VERSION) : 'assets/avatars/default-user-silhouette.webp';
    return '<span class="avatar-frame '.e($class).'" data-avatar-user="'.$uid.'"><img class="'.e($cls).'" src="'.e($src).'" alt="Profile avatar" loading="eager"></span>';
}

/**
 * AICS control numbers are serialized by the caller with the shared MySQL
 * advisory lock `fams:aics:control-sequence`. The unique key is the final DB
 * guard; if legacy duplicates exist, do not attempt a destructive cleanup.
 */
function aics_ensure_control_number_unique(PDO $pdo): bool {
    static $checked = false;
    static $available = false;
    if ($checked) return $available;
    $checked = true;
    try {
        $idx = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_assistance_records' AND INDEX_NAME='uq_aics_control_number'")->fetchColumn();
        if ((int)$idx > 0) { $available = true; return true; }
        $duplicates = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT control_number FROM aics_assistance_records WHERE control_number IS NOT NULL AND control_number<>'' GROUP BY control_number HAVING COUNT(*)>1) d")->fetchColumn();
        if ($duplicates > 0) { error_log('AICS control-number unique index not created: legacy duplicate control numbers require review.'); return false; }
        $pdo->exec('ALTER TABLE aics_assistance_records ADD UNIQUE KEY uq_aics_control_number (control_number)');
        $available = true;
    } catch (Throwable $e) {
        error_log('AICS control-number unique index check failed: '.$e->getMessage());
    }
    return $available;
}

/** Allocate the next current-year AICS control number while the caller holds
 * fams:aics:control-sequence on this same PDO connection. */
function aics_next_control_number(PDO $db, int $year): array {
    if ($year < 1900 || $year > 9999) throw new RuntimeException('Invalid control-number year.');
    $q=$db->prepare("SELECT COALESCE(MAX(GREATEST(COALESCE(source_no,0),CAST(SUBSTRING_INDEX(control_number,'-',-1) AS UNSIGNED))),0) FROM aics_assistance_records WHERE LEFT(control_number,4)=? AND control_number REGEXP '^[0-9]{4}-[0-9]+$'");
    $q->execute([(string)$year]);
    $next=(int)$q->fetchColumn()+1;
    while (true) {
        $control=sprintf('%04d-%06d',$year,$next);
        $check=$db->prepare('SELECT 1 FROM aics_assistance_records WHERE control_number=? LIMIT 1');
        $check->execute([$control]);
        if (!$check->fetchColumn()) return ['control'=>$control,'source_no'=>$next];
        $next++;
        if ($next > 999999) throw new RuntimeException('AICS control-number sequence limit reached for '.$year.'.');
    }
}

/** Ticket IDs are AUTO_INCREMENT values; use the engine's next value rather
 * than MAX(id)+1 (which can regress after deletion of the highest row). The
 * caller must hold fams:aics:ticket-sequence on this same connection. */
function aics_next_ticket_number(PDO $db): string {
    $q=$db->prepare("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets'");
    $q->execute();
    $next=(int)$q->fetchColumn();
    if ($next < 1) $next=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM aics_tickets')->fetchColumn();
    return 'TKT-'.date('Y').'-'.str_pad((string)$next,5,'0',STR_PAD_LEFT);
}
