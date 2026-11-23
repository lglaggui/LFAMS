<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/core/shared_runtime.php';
require_once dirname(__DIR__).'/core/account_runtime.php';
require_once dirname(__DIR__).'/core/audit_runtime.php';
require_once dirname(__DIR__).'/core/workspace_repositories.php';

function e(?string $value): string { return fams_shared_escape($value); }

/**
 * Normalize user-entered official text before it is persisted.
 * Passwords, emails, usernames, IDs, tokens, URLs, and other technical
 * values must not pass through this helper.
 */
function fams_uppercase_text($value): string {
    $value = trim((string)$value);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}
function fams_uppercase_fields(array $data, array $fields): array {
    foreach ($fields as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = fams_uppercase_text($data[$field]);
        }
    }
    return $data;
}

// Database-backed system media endpoint. Keep this helper defined centrally so every
// logo/avatar reference uses the same endpoint instead of producing a fatal error
// when the media system is enabled.
function system_media_url(string $key): string {
    return 'media.php?key=' . rawurlencode($key);
}
function ensure_user_avatar_schema(PDO $pdo): void {
    static $done=false; if($done)return;
    if($pdo->inTransaction()) throw new LogicException('MAIFIP avatar schema must be verified before a business transaction.');
    $exists=(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='avatar_filename'")->fetchColumn();
    if(!$exists)$pdo->exec("ALTER TABLE users ADD COLUMN avatar_filename VARCHAR(180) NULL AFTER avatar_mime");
    $done=true;
}
function fams_avatar_filename(string $username, int $userId, string $mime): string {
    $base=preg_replace('/[^A-Za-z0-9._-]+/','_',trim($username));
    $base=trim((string)$base,'._-');
    if($base==='') $base='user';
    $base=substr($base,0,110);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??'bin';
    return $base.'_'.max(0,$userId).'.'.$ext;
}
function redirect(string $path): never { fams_shared_redirect($path); }
function is_logged_in(): bool { return fams_shared_logged_in(); }
function current_user(): ?array { return fams_shared_current_user(); }
function sync_current_user_from_db(PDO $pdo): ?array {
    if (!is_logged_in()) return null;
    $session=(array)($_SESSION['user']??[]);
    $id=(int)($session['id']??0);
    if ($id<=0) return null;
    try {
        $st=$pdo->prepare('SELECT id, username, full_name, email, role, facility, is_active, auth_version, approval_status FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row=$st->fetch();
        if (!$row || (int)$row['is_active']!==1 || (string)($row['approval_status']??'APPROVED')!=='APPROVED') {
            fams_shared_invalidate_session('MAIFIP account inactive or removed.');
            return null;
        }
        $sessionVersion=(int)($session['auth_version']??0); $currentVersion=(int)($row['auth_version']??1);
        if($sessionVersion>0 && $sessionVersion!==$currentVersion){fams_shared_invalidate_session('MAIFIP account security settings changed.');return null;}
        $storedRole=(string)$row['role']; $typeDef=function_exists('fams_type_definition')?fams_type_definition($pdo,'MAIFIP',$storedRole):null;
        if(!$typeDef || (int)$typeDef['is_active']!==1){fams_shared_invalidate_session('MAIFIP user type disabled.');return null;}
        $priorKey=(string)($session['user_type_key']??''); $priorPolicy=(string)($session['user_type_policy_hash']??''); $currentPolicy=hash('sha256',(string)($typeDef['permissions_json']??''));
        if(($priorKey!=='' && !hash_equals($priorKey,$storedRole)) || ($priorPolicy!=='' && !hash_equals($priorPolicy,$currentPolicy))){unset($_SESSION['user']);session_regenerate_id(true);return null;}
        $_SESSION['user']=[
            'id'=>(int)$row['id'], 'auth_version'=>$currentVersion, 'username'=>(string)$row['username'], 'full_name'=>(string)$row['full_name'],
            'email'=>(string)($row['email']??''), 'role'=>(string)($typeDef['base_role']??$storedRole),
            'user_type_key'=>$storedRole, 'user_type_label'=>(string)($typeDef['label']??$storedRole), 'user_type_policy_hash'=>hash('sha256',(string)($typeDef['permissions_json']??'')), 'facility'=>user_facility($row),
        ];
        return $_SESSION['user'];
    } catch(Throwable $e) {
        error_log('MAIFIP session refresh failed closed: '.$e->getMessage()); fams_shared_invalidate_session('MAIFIP identity verification failed.'); return null;
    }
}
function require_login(): void { if (!is_logged_in()) redirect('login.php'); }
function require_role(array $roles): void { require_login(); if (!in_array((string)(current_user()['role'] ?? ''), $roles, true)) { http_response_code(403); exit('Access denied.'); } }
function csrf_token(): string { return fams_shared_csrf_token(); }
function verify_csrf(): void { fams_shared_verify_csrf(); }
function verify_csrf_json(): void { fams_shared_verify_csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''), true); }
function flash(string $type,string $message): void { fams_shared_flash_set($type,$message); }
function get_flash(): ?array { return fams_shared_flash_take(); }

const FAMS_ACTIVE_FACILITIES = ['SIMC','CDH','CIMC','CVMC'];
const FAMS_FACILITIES = [
    'LPGMA' => ['name'=>'LPGMA Isabela District','short'=>'LPGMA','logo'=>'lpgma-logo.png','accent'=>'#ee7d25','is_system'=>1],
    'SIMC'  => ['name'=>'Southern Isabela Medical Center','short'=>'SIMC','logo'=>'simc-logo.webp','accent'=>'#d33030','is_system'=>0],
    'CDH'   => ['name'=>'Cauayan District Hospital','short'=>'CDH','logo'=>'cdh-logo.webp','accent'=>'#268b49','is_system'=>0],
    'CIMC'  => ['name'=>'City of Ilagan Medical Center','short'=>'CIMC','logo'=>'cimc-logo.webp','accent'=>'#2567c4','is_system'=>0],
    'CVMC'  => ['name'=>'Cagayan Valley Medical Center','short'=>'CVMC','logo'=>'cvmc-logo.webp','accent'=>'#eebe20','is_system'=>0],
];
function facility_table_available(PDO $pdo): bool {
    static $available=null; if($available!==null) return $available;
    try { $st=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fams_facilities'"); $available=((int)$st->fetchColumn()>0); } catch(Throwable $e){ $available=false; }
    return $available;
}
function ensure_facility_schema(PDO $pdo): void {
    static $done=false; if($done)return;
    if($pdo->inTransaction())throw new LogicException('MAIFIP facility schema must be verified before a business transaction.');
    $exists=(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='facility'")->fetchColumn();
    if(!$exists)$pdo->exec("ALTER TABLE users ADD COLUMN facility VARCHAR(32) NULL AFTER role");
    $pdo->exec("UPDATE users SET facility='SIMC' WHERE (facility IS NULL OR facility='') AND role='social_worker'");
    $pdo->exec("UPDATE users SET facility='LPGMA' WHERE facility IS NULL OR facility=''");
    $done=true;
}
function facility_options(): array {
    global $pdo;
    if(facility_table_available($pdo)) {
        try {
            $rows=$pdo->query("SELECT facility_key,name,short_name,accent_color,logo_media_key FROM fams_facilities WHERE is_active=1 AND is_system=0 ORDER BY sort_order, name")->fetchAll();
            $out=[]; foreach($rows as $r){ $out[$r['facility_key']]=['name'=>$r['name'],'short'=>$r['short_name'],'logo'=>null,'accent'=>$r['accent_color'],'logo_media_key'=>$r['logo_media_key']]; }
            // A partially migrated/empty table must never make the facility
            // selector disappear. Fall back to the established medical facilities
            // until the explicit facility seed migration is completed.
            if ($out) return $out;
        } catch(Throwable $e) {}
    }
    $out=[]; foreach(FAMS_ACTIVE_FACILITIES as $key){$out[$key]=FAMS_FACILITIES[$key];} return $out;
}
function facility_info(string $facility): array {
    global $pdo;
    if(facility_table_available($pdo)) {
        try { $st=$pdo->prepare("SELECT facility_key,name,short_name,accent_color,logo_media_key,is_active,is_system FROM fams_facilities WHERE facility_key=? LIMIT 1"); $st->execute([$facility]); $r=$st->fetch(); if($r){ return ['name'=>$r['name'],'short'=>$r['short_name'],'logo'=>null,'accent'=>$r['accent_color'],'logo_media_key'=>$r['logo_media_key'],'is_active'=>(int)$r['is_active'],'is_system'=>(int)$r['is_system']]; } } catch(Throwable $e) {}
    }
    return FAMS_FACILITIES[$facility] ?? ['name'=>$facility,'short'=>$facility,'logo'=>null,'accent'=>'#2b587d','logo_media_key'=>null,'is_active'=>1,'is_system'=>0];
}
function facility_label(string $facility): string { return facility_info($facility)['short']; }
function user_facility(array $user): string {
    $facility=trim((string)($user['facility']??''));
    if($facility!=='' && (isset(FAMS_FACILITIES[$facility]) || facility_info($facility)['name']!==$facility)) return $facility;
    return (($user['role']??'')==='social_worker') ? 'SIMC' : 'LPGMA';
}
function facility_logo(string $facility): ?string { return facility_info($facility)['logo'] ?? null; }
function facility_logo_media_key(string $facility): ?string {
    $info=facility_info($facility); if(!empty($info['logo_media_key'])) return (string)$info['logo_media_key'];
    $map=['LPGMA'=>'lpgma_logo','SIMC'=>'simc_logo','CDH'=>'cdh_logo','CIMC'=>'cimc_logo','CVMC'=>'cvmc_logo'];
    return $map[$facility] ?? (preg_match('/^[A-Z0-9_]+$/',$facility) ? 'facility_logo_'.strtolower($facility) : null);
}
function facility_logo_url(string $facility): ?string { $key=facility_logo_media_key($facility); return $key ? system_media_url($key) : null; }
function selected_facility(PDO $pdo): string {
    ensure_facility_schema($pdo); $u=current_user() ?: []; $own=user_facility($u); $allowed=array_keys(facility_options());
    if($own!=='LPGMA'){
        if(!facility_is_active($pdo,$own)){ http_response_code(403); exit('Your facility is currently disabled or unavailable. Please contact the Site Admin.'); }
        $_SESSION['fams_facility']=$own; return $own;
    }
    $requested=trim((string)($_GET['facility']??'')); if($requested!=='' && in_array($requested,$allowed,true)) $_SESSION['fams_facility']=$requested;
    $selected=trim((string)($_SESSION['fams_facility']??'')); if(!in_array($selected,$allowed,true)) $selected=$allowed[0] ?? 'SIMC'; $_SESSION['fams_facility']=$selected; return $selected;
}
function can_switch_facility(): bool { return user_facility(current_user() ?: [])==='LPGMA'; }
function facility_initials(string $facility): string { $i=facility_info($facility)['short']??$facility; return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$i),0,4)); }
function facility_logo_markup(string $facility,string $class=''): string {
    global $pdo; $info=facility_info($facility); $short=$info['short']??$facility; $mediaKey=facility_logo_media_key($facility); $url=$mediaKey ? facility_logo_url($facility) : null;
    $knownFallbacks=['lpgma_logo','simc_logo','cdh_logo','cimc_logo','cvmc_logo']; $hasMedia=in_array((string)$mediaKey,$knownFallbacks,true);
    if(!$hasMedia && $mediaKey && facility_table_available($pdo)) { try{$st=$pdo->prepare('SELECT COUNT(*) FROM system_media WHERE media_key=?');$st->execute([$mediaKey]);$hasMedia=(int)$st->fetchColumn()>0;}catch(Throwable $e){} }
    if($url && $hasMedia){ return '<img class="'.e($class).'" src="'.e($url).'" decoding="sync" data-fams-media-key="'.e((string)$mediaKey).'" alt="'.e($short).' logo">'; }
    return '<span class="facility-initials '.e($class).'" aria-label="'.e($short).'">'.e(facility_initials($facility)).'</span>';
}
function facility_is_active(PDO $pdo,string $facility): bool {
    if($facility==='LPGMA') return true;
    if(facility_table_available($pdo)){
        try{
            $st=$pdo->prepare('SELECT is_active FROM fams_facilities WHERE facility_key=? LIMIT 1');
            $st->execute([$facility]); $v=$st->fetchColumn();
            return $v!==false && (int)$v===1;
        }catch(Throwable $e){ return false; }
    }
    $info=facility_info($facility); return (int)($info['is_active']??1)===1;
}
function facility_accent(string $facility): string { $c=(string)(facility_info($facility)['accent']??'#2b587d'); return preg_match('/^#[0-9a-fA-F]{6}$/',$c)?$c:'#2b587d'; }

function ensure_facility_data_schema(PDO $pdo): void {
    static $done=false; if($done)return;
    if($pdo->inTransaction())throw new LogicException('MAIFIP facility data schema must be verified before a business transaction.');
    $tables=[
        'assistance_records'=>['after'=>'record_version','default'=>'SIMC'],
        'fund_downloads'=>['after'=>'fund_source','default'=>'SIMC'],
        'monthly_disbursement_summary'=>['after'=>'notes','default'=>'SIMC'],
        'monthly_report_settings'=>['after'=>'downloaded_balance','default'=>'SIMC'],
    ];
    foreach($tables as $table=>$meta){
        $check=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='facility'");
        $check->execute([$table]);
        if((int)$check->fetchColumn()===0)$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN facility VARCHAR(32) NOT NULL DEFAULT '{$meta['default']}' AFTER `{$meta['after']}`");
        $pdo->exec("UPDATE `{$table}` SET facility='SIMC' WHERE facility IS NULL OR facility=''");
    }
    // Install the facility-aware key before removing the legacy key. If the new key
    // cannot be created (for example, legacy duplicates), the old uniqueness guard stays.
    $oldKey=(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_disbursement_summary' AND INDEX_NAME='uq_monthly_disbursement_year_month'")->fetchColumn();
    $newDefinition=(string)$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_disbursement_summary' AND INDEX_NAME='uq_monthly_disbursement_facility_year_month' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchColumn();
    if($newDefinition!=='' && $newDefinition!=='facility,year,month_no')throw new RuntimeException('MAIFIP monthly-summary replacement index has an unexpected definition.');
    if($newDefinition==='')$pdo->exec("ALTER TABLE monthly_disbursement_summary ADD UNIQUE KEY uq_monthly_disbursement_facility_year_month (facility,year,month_no)");
    $newDefinition=(string)$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_disbursement_summary' AND INDEX_NAME='uq_monthly_disbursement_facility_year_month' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchColumn();
    if($newDefinition!=='facility,year,month_no')throw new RuntimeException('MAIFIP monthly-summary replacement index could not be verified.');
    if($oldKey)$pdo->exec("ALTER TABLE monthly_disbursement_summary DROP INDEX uq_monthly_disbursement_year_month");
    foreach(array_keys($tables) as $table){
        $check=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='facility'");
        $check->execute([$table]);
        if((int)$check->fetchColumn()!==1)throw new RuntimeException('MAIFIP facility schema verification failed for '.$table.'.');
    }
    $done=true;
}

function ensure_control_number_schema(PDO $pdo): void {
    static $done=false; if($done) return;
    try {
        $columns=$pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assistance_records'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('facility',$columns,true) || !in_array('control_number',$columns,true)) {
            throw new RuntimeException('MAIFIP facility/control-number columns are not ready; uniqueness migration was deferred.');
        }

        // Never remove an existing uniqueness guard before the replacement is
        // proven safe. Legacy duplicate pairs are reported and preserved.
        $duplicates=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT facility,control_number FROM assistance_records WHERE control_number IS NOT NULL GROUP BY facility,control_number HAVING COUNT(*)>1) d")->fetchColumn();
        if ($duplicates>0) {
            error_log('MAIFIP facility/control-number unique index deferred: legacy duplicate pairs require review.');
            return;
        }

        $indexRows=$pdo->query("SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assistance_records' GROUP BY INDEX_NAME,NON_UNIQUE")->fetchAll(PDO::FETCH_ASSOC);
        $hasComposite=false;
        foreach($indexRows as $idx){
            if((string)$idx['INDEX_NAME']==='uq_facility_control_number' && (string)$idx['index_columns']!=='facility,control_number') {
                throw new RuntimeException('The existing uq_facility_control_number index has an unexpected definition.');
            }
            if((int)$idx['NON_UNIQUE']===0 && (string)$idx['index_columns']==='facility,control_number') $hasComposite=true;
        }
        if(!$hasComposite){
            try { $pdo->exec("ALTER TABLE assistance_records ADD UNIQUE KEY uq_facility_control_number (facility,control_number)"); }
            catch(Throwable $migrationError) {
                // A concurrent request may have installed the same key. Verify
                // the actual definition before treating that as success.
                $verify=$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assistance_records' AND INDEX_NAME='uq_facility_control_number' GROUP BY INDEX_NAME")->fetchColumn();
                if((string)$verify!=='facility,control_number') throw $migrationError;
            }
        }

        // Only after the composite unique key exists may obsolete single-column
        // unique indexes be removed. A failure leaves the prior guard intact.
        $singleUnique=[];
        foreach($indexRows as $idx){
            if((int)$idx['NON_UNIQUE']===0 && (string)$idx['index_columns']==='control_number' && (string)$idx['INDEX_NAME']!=='PRIMARY') $singleUnique[]=(string)$idx['INDEX_NAME'];
        }
        foreach($singleUnique as $indexName){
            $safeName=str_replace('`','``',$indexName);
            $pdo->exec('ALTER TABLE assistance_records DROP INDEX `'.$safeName.'`');
        }

        $verify=$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assistance_records' AND INDEX_NAME='uq_facility_control_number' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchColumn();
        if((string)$verify!=='facility,control_number') throw new RuntimeException('MAIFIP facility/control-number unique index could not be verified.');
        $done=true;
    } catch(Throwable $e) {
        $done=false;
        error_log('MAIFIP control-number schema verification failed: '.$e->getMessage());
        throw new RuntimeException('MAIFIP control-number schema could not be verified. No record mutation was processed.',0,$e);
    }
}
function active_data_facility(PDO $pdo): string { ensure_facility_data_schema($pdo); ensure_control_number_schema($pdo); return selected_facility($pdo); }
function facility_scope(string $column='facility'): array { $f=selected_facility($GLOBALS['pdo']); return [" AND {$column} = ?", $f]; }

function role_label(string $role): string { $pdo=$GLOBALS['pdo']??null; if($pdo instanceof PDO && function_exists('fams_user_type_label')) return fams_user_type_label($pdo,'MAIFIP',$role); return match($role){ 'admin'=>'LPGMA Admin / Site Admin','manager'=>'LPGMA Manager','encoder'=>'LPGMA Staff','social_worker'=>'Social Worker', default=>ucwords(str_replace('_',' ',$role)) }; }
function status_class(string $status): string { return match(strtoupper($status)){ 'RELEASED'=>'status-released','WAITING'=>'status-waiting','CANCELLED'=>'status-cancelled',default=>'status-neutral'}; }
function can_edit_record(): bool {
    $u=current_user()??[]; $role=(string)($u['role']??''); if(!fams_current_type_allows('MAIFIP','edit_records')) return false;
    $isLpgma=(function_exists('user_facility') && user_facility($u)==='LPGMA') || (($u['facility']??'')==='LPGMA') || (($u['identity_source']??'')==='LPGMA_BRIDGED');
    return $role==='admin' || ($isLpgma && in_array($role,['manager','encoder','staff'],true));
}
function can_edit_existing_record(array $record): bool {
    $role = current_user()['role'] ?? '';
    if ($role === 'admin') return fams_current_type_allows('MAIFIP','edit_records');
    return can_edit_record() && in_array($role,['manager','encoder','staff'],true) && strtoupper((string)($record['status'] ?? 'WAITING')) === 'WAITING';
}
function can_update_status(): bool {
    $u=current_user()??[]; $role=(string)($u['role']??''); if(!fams_current_type_allows('MAIFIP','update_status')) return false;
    $isLpgma=(function_exists('user_facility') && user_facility($u)==='LPGMA') || (($u['facility']??'')==='LPGMA') || (($u['identity_source']??'')==='LPGMA_BRIDGED');
    // All authorized facility-side users can directly update WAITING records.
    // status_update.php independently enforces facility scope and prevents non-admin edits
    // once a record is RELEASED or CANCELLED.
    return $role==='admin' || (!$isLpgma && in_array($role,['social_worker','encoder','staff','manager'],true)) || ($isLpgma && in_array($role,['manager','encoder','staff'],true));
}
function can_raise_ticket(): bool { return in_array(current_user()['role']??'', ['encoder','social_worker','manager','staff'], true) && fams_current_type_allows('MAIFIP','submit_tickets'); }
function can_manage_users(): bool { return (current_user()['role']??'') === 'admin' && fams_current_type_allows('MAIFIP','manage_users'); }
function ensure_action_logs_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    if ($pdo->inTransaction()) {
        throw new LogicException('MAIFIP audit schema must be verified before starting a business transaction.');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS action_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL,
        username VARCHAR(80) NOT NULL,
        role VARCHAR(40) NOT NULL,
        action VARCHAR(80) NOT NULL,
        description VARCHAR(255) NOT NULL,
        record_id INT UNSIGNED DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        client_hostname VARCHAR(255) DEFAULT NULL,
        user_agent VARCHAR(512) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_logs_user(user_id), INDEX idx_logs_action(action), INDEX idx_logs_created(created_at)
    ) ENGINE=InnoDB");
    $required = ['client_hostname'=>'VARCHAR(255) DEFAULT NULL AFTER ip_address','user_agent'=>'VARCHAR(512) DEFAULT NULL AFTER client_hostname'];
    foreach ($required as $column=>$definition) {
        $check=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='action_logs' AND COLUMN_NAME=?");
        $check->execute([$column]);
        if ((int)$check->fetchColumn()===0) $pdo->exec("ALTER TABLE action_logs ADD COLUMN {$column} {$definition}");
    }
    $verify=$pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='action_logs'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id','user_id','username','role','action','description','record_id','ip_address','client_hostname','user_agent','created_at'] as $column) {
        if (!in_array($column,$verify,true)) throw new RuntimeException('MAIFIP audit schema is incomplete: missing '.$column.'.');
    }
    $done=true;
}

function fams_client_ip(): ?string {
    $ip=filter_var((string)($_SERVER['REMOTE_ADDR']??''), FILTER_VALIDATE_IP);
    return $ip ?: null;
}
function fams_client_hostname(?string $ip): ?string {
    static $resolved=false, $hostname=null;
    if($resolved) return $hostname;
    $resolved=true;
    if(!$ip || in_array($ip,['127.0.0.1','::1'],true)) return $hostname='localhost';
    try {
        $host=@gethostbyaddr($ip);
        if($host && $host!==$ip) $hostname=substr((string)$host,0,255);
    } catch(Throwable $e) {}
    return $hostname;
}
function log_action(PDO $pdo,string $action,string $description,?int $recordId=null):void { if($pdo->inTransaction()) fams_audit_write_in_transaction($pdo,'maifip',$action,$description,$recordId); else fams_audit_write($pdo,'maifip',$action,$description,$recordId); }


function ensure_record_sync_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $hasVersion = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assistance_records' AND COLUMN_NAME='record_version'")->fetchColumn();
        if (!$hasVersion) {
            // Existing installations can have a slightly older assistance_records schema.
            // Do not assume updated_by exists when adding the sync revision column.
            $pdo->exec("ALTER TABLE assistance_records ADD COLUMN record_version INT UNSIGNED NOT NULL DEFAULT 1");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS offline_sync_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_change_id VARCHAR(80) NOT NULL,
            user_id INT UNSIGNED NULL,
            operation ENUM('CREATE','UPDATE') NOT NULL,
            record_id INT UNSIGNED NULL,
            base_version INT UNSIGNED NOT NULL DEFAULT 0,
            payload_json LONGTEXT NOT NULL,
            status ENUM('APPLIED','CONFLICT','REJECTED','RESOLVED') NOT NULL DEFAULT 'APPLIED',
            conflict_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_offline_client_change(client_change_id),
            INDEX idx_offline_record(record_id),
            INDEX idx_offline_user(user_id),
            INDEX idx_offline_status(status),
            CONSTRAINT fk_offline_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_offline_record FOREIGN KEY(record_id) REFERENCES assistance_records(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $syncType = (string)$pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='offline_sync_log' AND COLUMN_NAME='status'")->fetchColumn();
        if ($syncType !== '' && strpos($syncType, "'RESOLVED'") === false) {
            $pdo->exec("ALTER TABLE offline_sync_log MODIFY status ENUM('APPLIED','CONFLICT','REJECTED','RESOLVED') NOT NULL DEFAULT 'APPLIED'");
        }
        // Older installations may have accumulated repeated client IDs before the
        // unique constraint was introduced. Preserve those historical rows while
        // reserving the original ID for the earliest entry, then enforce uniqueness.
        $uniqueIndex = $pdo->prepare("SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='offline_sync_log' AND INDEX_NAME='uq_offline_client_change' LIMIT 1");
        $uniqueIndex->execute();$indexState=$uniqueIndex->fetchColumn();
        if ($indexState === false || (int)$indexState !== 0) {
            if($indexState!==false)$pdo->exec('ALTER TABLE offline_sync_log DROP INDEX uq_offline_client_change');
            $pdo->exec("UPDATE offline_sync_log o JOIN (SELECT client_change_id,MIN(id) keep_id FROM offline_sync_log GROUP BY client_change_id HAVING COUNT(*)>1) d ON d.client_change_id=o.client_change_id AND o.id<>d.keep_id SET o.client_change_id=CONCAT('legacy-',o.id,'-',LEFT(SHA2(CONCAT(o.client_change_id,':',o.id),256),16))");
            $pdo->exec('ALTER TABLE offline_sync_log ADD UNIQUE KEY uq_offline_client_change(client_change_id)');
        }
    } catch (Throwable $e) {
        $done=false;
        error_log('MAIFIP offline-sync schema migration failed: '.$e->getMessage());
        throw new RuntimeException('Offline synchronization schema could not be verified. No synchronization operation was processed.',0,$e);
    }
    $done = true;
}

function ensure_monthly_report_settings(PDO $pdo): void {
    static $done=false; if($done)return;
    if($pdo->inTransaction())throw new LogicException('MAIFIP monthly-report schema must be verified before a business transaction.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_report_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        report_year SMALLINT NOT NULL,
        report_month TINYINT NOT NULL,
        downloaded_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        facility VARCHAR(32) NOT NULL DEFAULT 'SIMC',
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_monthly_report_facility_period (facility, report_year, report_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hasFacility=(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_report_settings' AND COLUMN_NAME='facility'")->fetchColumn();
    if(!$hasFacility)$pdo->exec("ALTER TABLE monthly_report_settings ADD COLUMN facility VARCHAR(32) NOT NULL DEFAULT 'SIMC' AFTER downloaded_balance");
    $oldKey=(int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_report_settings' AND INDEX_NAME='uq_monthly_report_period'")->fetchColumn();
    $newDefinition=(string)$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_report_settings' AND INDEX_NAME='uq_monthly_report_facility_period' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchColumn();
    if($newDefinition!=='' && $newDefinition!=='facility,report_year,report_month')throw new RuntimeException('MAIFIP monthly-report replacement index has an unexpected definition.');
    if($newDefinition==='')$pdo->exec("ALTER TABLE monthly_report_settings ADD UNIQUE KEY uq_monthly_report_facility_period (facility, report_year, report_month)");
    $newDefinition=(string)$pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_report_settings' AND INDEX_NAME='uq_monthly_report_facility_period' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchColumn();
    if($newDefinition!=='facility,report_year,report_month')throw new RuntimeException('MAIFIP monthly-report facility uniqueness could not be verified.');
    if($oldKey)$pdo->exec("ALTER TABLE monthly_report_settings DROP INDEX uq_monthly_report_period");
    $done=true;
}

function get_monthly_report_adjustments(PDO $pdo, int $year, int $month): float {
    $facility=active_data_facility($pdo);
    $start=sprintf('%04d-%02d-01',$year,$month);
    $end=$month===12 ? sprintf('%04d-01-01',$year+1) : sprintf('%04d-%02d-01',$year,$month+1);
    $st=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM maifip_fund_adjustments WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND effective_date>=? AND effective_date<?");
    $st->execute([$facility,$start,$end]);
    return (float)$st->fetchColumn();
}
/**
 * Return the canonical record-level released rows for a MAIFIP reporting month.
 * Facility and status comparisons are normalized exactly like the summary helper,
 * and the half-open date range avoids YEAR()/MONTH() scans.
 */
function maifip_monthly_released_records(PDO $pdo, int $year, int $month): array {
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid MAIFIP reporting period.');
    }
    $facility = active_data_facility($pdo);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = $month === 12 ? sprintf('%04d-01-01', $year + 1) : sprintf('%04d-%02d-01', $year, $month + 1);
    $stmt = $pdo->prepare("SELECT * FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date>=? AND release_date<? ORDER BY release_date, id");
    $stmt->execute([$facility, $start, $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Authoritative MAIFIP monthly fund calculation shared by worksheet, print,
 * and CSV output. Disbursement precedence is identical to the dashboard:
 * live RELEASED records when present, otherwise the saved historical summary.
 */
function maifip_monthly_fund_reconciliation(PDO $pdo, int $year, int $month): array {
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid MAIFIP reporting period.');
    }
    $summary = reporting_disbursement_summary($pdo, $year, $month);
    $disbursement = $summary['monthly'][$month] ?? ['amount'=>0.0,'clients'=>0,'source'=>'records'];
    $opening = monthly_report_opening_balance($pdo, $year, $month);
    $downloads = get_monthly_report_downloaded_balance($pdo, $year, $month);
    $adjustments = get_monthly_report_adjustments($pdo, $year, $month);
    $released = (float)($disbursement['amount'] ?? 0.0);
    return [
        'year'=>$year, 'month'=>$month,
        'opening_balance'=>round($opening,2),
        'fund_downloads'=>round($downloads,2),
        'adjustments'=>round($adjustments,2),
        'disbursements'=>round($released,2),
        'ending_balance'=>round($opening + $downloads + $adjustments - $released,2),
        'clients'=>(int)($disbursement['clients'] ?? 0),
        'disbursement_source'=>(string)($disbursement['source'] ?? 'records'),
        'stored_summary_amount'=>array_key_exists('stored_summary_amount',$disbursement) ? (float)$disbursement['stored_summary_amount'] : null,
        'stored_summary_clients'=>array_key_exists('stored_summary_clients',$disbursement) ? (int)$disbursement['stored_summary_clients'] : null,
        'summary_discrepancy'=>(bool)($disbursement['summary_discrepancy'] ?? false),
    ];
}

function add_monthly_report_adjustment(PDO $pdo,int $year,int $month,float $amount,string $reason,?int $userId): int {
    if(!$pdo->inTransaction()) throw new LogicException('Fund adjustments must be recorded inside a transaction.');
    if(!is_finite($amount) || abs($amount)>9999999999999.99 || abs($amount)<0.005) throw new InvalidArgumentException('Adjustment amount must be non-zero and within the supported range.');
    $reason=trim($reason);
    if($reason==='' || strlen($reason)>500) throw new InvalidArgumentException('A reason of 1 to 500 characters is required.');
    $facility=active_data_facility($pdo);
    $effectiveDate=sprintf('%04d-%02d-01',$year,$month);
    $st=$pdo->prepare('INSERT INTO maifip_fund_adjustments (facility,effective_date,amount,reason,created_by) VALUES (?,?,?,?,?)');
    $st->execute([$facility,$effectiveDate,round($amount,2),$reason,$userId]);
    return (int)$pdo->lastInsertId();
}

function get_monthly_report_downloaded_balance(PDO $pdo, int $year, int $month): float {
    $facility=active_data_facility($pdo);
    // Legacy monthly_report_settings values are retained for audit/history only.
    // Actual fund downloads are the sole source of monthly downloaded additions.
    $first=sprintf('%04d-%02d-01',$year,$month);
    $next=$month===12 ? sprintf('%04d-01-01',$year+1) : sprintf('%04d-%02d-01',$year,$month+1);
    // The 2026 ledger permits at most one opening anchor for the facility across
    // the entire ledger, including anchors dated after the selected report month.
    $anchorCount=$pdo->prepare("SELECT COUNT(*) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'");
    $anchorCount->execute([$facility]);
    $globalAnchorCount=(int)$anchorCount->fetchColumn();
    $janAnchorCount=$pdo->prepare("SELECT COUNT(*) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'");
    $janAnchorCount->execute([$facility]);
    $janCount=(int)$janAnchorCount->fetchColumn();
    if($globalAnchorCount>1 || ($globalAnchorCount===1 && $janCount!==1)) throw new RuntimeException('Invalid MAIFIP opening-balance classification. Keep at most one verified January 1, 2026 carryover for this facility; classify all other receipts as ordinary fund downloads.');
    // If the single opening anchor begins mid-month, do not count fund
    // transactions dated before that anchor as new funds for this period.
    $anchor=$pdo->prepare("SELECT date_downloaded FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' ORDER BY date_downloaded DESC,id DESC LIMIT 1");
    $anchor->execute([$facility,$next]); $anchorDate=$anchor->fetchColumn();
    $effectiveStart=($anchorDate!==false && $anchorDate>$first) ? (string)$anchorDate : $first;
    $st=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>=? AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
    $st->execute([$facility,$effectiveStart,$next]);
    // Preserve the signed net total: negative entries represent reversals or corrections
    // and must reduce the facility balance rather than being clipped to zero.
    return round((float)$st->fetchColumn(),2);
}
function monthly_report_opening_balance(PDO $pdo, int $year, int $month): float {
    // January begins at the effective opening anchor (or legacy cumulative
    // position). Later months roll forward one completed month at a time.
    $facility=active_data_facility($pdo);
    $periodStart=sprintf('%04d-%02d-01',$year,$month);
    $yearStart=sprintf('%04d-01-01',$year);
    $periodEnd=$month===12 ? sprintf('%04d-01-01',$year+1) : sprintf('%04d-%02d-01',$year,$month+1);
    $dup=$pdo->prepare("SELECT COUNT(*) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'");
    $dup->execute([$facility]);
    $globalAnchorCount=(int)$dup->fetchColumn();
    $janDup=$pdo->prepare("SELECT COUNT(*) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'");
    $janDup->execute([$facility]); $janCount=(int)$janDup->fetchColumn();
    if($year>=2026 && ($globalAnchorCount>1 || ($globalAnchorCount===1 && $janCount!==1))) throw new RuntimeException('Invalid MAIFIP opening-balance classification. Keep at most one verified January 1, 2026 carryover for this facility; classify all other receipts as ordinary fund downloads.');
    if($year>2026) return (float)maifip_fund_ledger_position($pdo,$periodStart)['remaining'];
    $beginSql=$year>=2026
        ? "SELECT date_downloaded,downloaded_amount FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' ORDER BY id LIMIT 1"
        : "SELECT date_downloaded,downloaded_amount FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded<=? AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' ORDER BY date_downloaded DESC,id DESC LIMIT 1";
    $beginSt=$pdo->prepare($beginSql);
    if($year>=2026) $beginSt->execute([$facility]); else $beginSt->execute([$facility,$periodStart]);
    $anchor=$beginSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if($anchor){
        $anchorDate=(string)$anchor['date_downloaded'];
        $balance=(float)$anchor['downloaded_amount'];
        $firstMonth=(int)substr($anchorDate,0,4)===$year ? (int)substr($anchorDate,5,2) : 1;
    }else{
        if($year>=2026){
            // The 2026 ledger starts at zero when no verified 2025 carryover
            // is present. Pre-2026 transactions are not silently rolled in.
            $balance=0.0; $anchorDate='2026-01-01'; $firstMonth=1;
        }else{
        // Legacy fallback for reports before the 2026 ledger baseline.
        $priorAdd=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
        $priorAdd->execute([$facility,$yearStart]); $balance=(float)$priorAdd->fetchColumn();
        $priorRelease=$pdo->prepare("SELECT COALESCE(SUM(guarantee_amount),0) FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date<? AND release_date IS NOT NULL AND release_date<>'' AND release_date<>'0000-00-00'");
        $priorRelease->execute([$facility,$yearStart]); $balance-=(float)$priorRelease->fetchColumn();
        $priorAdjustments=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM maifip_fund_adjustments WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND effective_date<?');
        $priorAdjustments->execute([$facility,$yearStart]); $balance+=(float)$priorAdjustments->fetchColumn();
        $anchorDate=null; $firstMonth=1;
        }
    }
    if($month<=1 || $firstMonth >= $month) return round($balance,2);

    ensure_monthly_report_settings($pdo);
    $downloadSt=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>=? AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
    $adjustmentSt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM maifip_fund_adjustments WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND effective_date>=? AND effective_date<?');
    $summarySt=$pdo->prepare('SELECT amount FROM monthly_disbursement_summary WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND year=? AND month_no=? LIMIT 1');
    $actualSt=$pdo->prepare("SELECT COUNT(*) AS record_count, COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date>=? AND release_date<? AND release_date IS NOT NULL AND release_date<>'' AND release_date<>'0000-00-00'");

    for($m=$firstMonth;$m<$month;$m++){
        $monthStart=sprintf('%04d-%02d-01',$year,$m);
        $monthEnd=$m===12 ? sprintf('%04d-01-01',$year+1) : sprintf('%04d-%02d-01',$year,$m+1);
        $effectiveStart=($anchorDate!==null && $m===(int)substr($anchorDate,5,2) && (int)substr($anchorDate,0,4)===$year && $anchorDate>$monthStart) ? $anchorDate : $monthStart;
        $downloadSt->execute([$facility,$effectiveStart,$monthEnd]); $downloaded=(float)$downloadSt->fetchColumn();
        $adjustmentSt->execute([$facility,$monthStart,$monthEnd]); $adjustments=(float)$adjustmentSt->fetchColumn();

        // Live records are authoritative. Monthly summary fallback is safe only
        // for a complete calendar month; do not apply it to a partial anchor month.
        $actualSt->execute([$facility,$effectiveStart,$monthEnd]);
        $actualRow=$actualSt->fetch(PDO::FETCH_ASSOC) ?: ['record_count'=>0,'amount'=>0];
        if((int)$actualRow['record_count']>0){ $released=(float)$actualRow['amount']; }
        elseif($effectiveStart===$monthStart){
            $summarySt->execute([$facility,$year,$m]); $summaryAmount=$summarySt->fetchColumn();
            $released=($summaryAmount!==false) ? (float)$summaryAmount : 0.0;
        }else{ $released=0.0; }
        $balance += $downloaded+$adjustments-$released;
    }
    return round($balance,2);
}

function maifip_fund_ledger_position(PDO $pdo, ?string $asOfExclusive = null): array {
    $facility=active_data_facility($pdo);
    $asOfExclusive=$asOfExclusive ?: date('Y-m-d',strtotime('tomorrow'));
    if ($asOfExclusive >= '2026-01-01') {
        $anchors=$pdo->prepare("SELECT COUNT(*) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'");
        $anchors->execute([$facility]); $anchorCount=(int)$anchors->fetchColumn();
        $jan=$pdo->prepare("SELECT downloaded_amount FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded='2026-01-01' AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' ORDER BY id LIMIT 2");
        $jan->execute([$facility]); $janRows=$jan->fetchAll(PDO::FETCH_COLUMN);
        if ($anchorCount>1 || count($janRows)>1 || ($anchorCount===1 && count($janRows)!==1)) throw new RuntimeException('Invalid MAIFIP opening-balance classification. Keep at most one verified January 1, 2026 carryover for this facility; classify all other receipts as ordinary fund downloads.');
        $base=$janRows ? (float)$janRows[0] : 0.0;
        $add=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>='2026-01-01' AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
        $add->execute([$facility,$asOfExclusive]); $additions=(float)$add->fetchColumn();
        $adjustment=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM maifip_fund_adjustments WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND effective_date>='2026-01-01' AND effective_date<?");
        $adjustment->execute([$facility,$asOfExclusive]); $adjustments=(float)$adjustment->fetchColumn();
        // Match the report's historical-summary fallback month by month:
        // live released rows are authoritative for a month; use a saved
        // monthly summary only when that complete month has no live rows.
        $actual=$pdo->prepare("SELECT COUNT(*) AS record_count,COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date>=? AND release_date<? AND release_date IS NOT NULL AND release_date<>'' AND release_date<>'0000-00-00'");
        $summary=$pdo->prepare('SELECT amount FROM monthly_disbursement_summary WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND year=? AND month_no=? LIMIT 1');
        $disbursed=0.0;
        $cursor=new DateTimeImmutable('2026-01-01'); $limit=new DateTimeImmutable($asOfExclusive);
        while($cursor<$limit){
            $monthStart=$cursor->format('Y-m-01'); $monthEnd=$cursor->modify('first day of next month')->format('Y-m-d');
            $to=min($asOfExclusive,$monthEnd);
            $actual->execute([$facility,$monthStart,$to]); $actualRow=$actual->fetch(PDO::FETCH_ASSOC) ?: ['record_count'=>0,'amount'=>0];
            if((int)$actualRow['record_count']>0) $disbursed+=(float)$actualRow['amount'];
            elseif($to===$monthEnd){ $summary->execute([$facility,(int)$cursor->format('Y'),(int)$cursor->format('n')]); $saved=$summary->fetchColumn(); if($saved!==false)$disbursed+=(float)$saved; }
            $cursor=$cursor->modify('first day of next month');
        }
        return ['available'=>$base+$additions+$adjustments,'disbursed'=>$disbursed,'remaining'=>$base+$additions+$adjustments-$disbursed,'anchor_date'=>'2026-01-01'];
    }
    $dup=$pdo->prepare("SELECT COUNT(*) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded<=? AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'");
    $dup->execute([$facility,$asOfExclusive]);
    if((int)$dup->fetchColumn()>1) throw new RuntimeException('Multiple MAIFIP BEGINNING BALANCE records apply to this facility and date. Review the opening-balance records before relying on this fund balance.');
    $anchorSt=$pdo->prepare("SELECT date_downloaded,downloaded_amount FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded<=? AND UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' ORDER BY date_downloaded DESC,id DESC LIMIT 1");
    $anchorSt->execute([$facility,$asOfExclusive]); $anchor=$anchorSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if($anchor){
        $date=(string)$anchor['date_downloaded']; $base=(float)$anchor['downloaded_amount'];
        $add=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded>=? AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
        $add->execute([$facility,$date,$asOfExclusive]); $additions=(float)$add->fetchColumn();
        $adjustmentTotalSt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM maifip_fund_adjustments WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND effective_date>=? AND effective_date<?');
        $adjustmentTotalSt->execute([$facility,$date,$asOfExclusive]); $adjustments=(float)$adjustmentTotalSt->fetchColumn();
        // Match reporting_disbursement_summary(): use live released records
        // for months containing them; use imported monthly summaries only when
        // that month has no record-level releases.
        $monthStart=new DateTimeImmutable(substr($date,0,7).'-01');
        $limit=new DateTimeImmutable($asOfExclusive);
        $actual=$pdo->prepare("SELECT COUNT(*) AS record_count,COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date>=? AND release_date<?");
        $manual=$pdo->prepare('SELECT amount FROM monthly_disbursement_summary WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND year=? AND month_no=? LIMIT 1');
        $disbursed=0.0;
        for($cursor=$monthStart;$cursor<$limit;$cursor=$cursor->modify('+1 month')){
            $from=max($date,$cursor->format('Y-m-01'));
            $to=min($asOfExclusive,$cursor->modify('+1 month')->format('Y-m-01'));
            if($from >= $to) continue;
            $actual->execute([$facility,$from,$to]); $actualRow=$actual->fetch(PDO::FETCH_ASSOC) ?: ['record_count'=>0,'amount'=>0];
            if((int)$actualRow['record_count']>0){ $disbursed+=(float)$actualRow['amount']; }
            elseif($from===$cursor->format('Y-m-01')) { $manual->execute([$facility,(int)$cursor->format('Y'),(int)$cursor->format('n')]); $saved=$manual->fetchColumn(); if($saved!==false)$disbursed+=(float)$saved; }
        }
        return ['available'=>$base+$additions+$adjustments,'disbursed'=>$disbursed,'remaining'=>$base+$additions+$adjustments-$disbursed,'anchor_date'=>$date];
    }
    $add=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM fund_downloads WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
    $add->execute([$facility,$asOfExclusive]); $available=(float)$add->fetchColumn();
    $rel=$pdo->prepare("SELECT COALESCE(SUM(guarantee_amount),0) FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date<? AND release_date IS NOT NULL AND release_date<>'' AND release_date<>'0000-00-00'");
    $rel->execute([$facility,$asOfExclusive]); $disbursed=(float)$rel->fetchColumn();
    $adj=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM maifip_fund_adjustments WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND effective_date<?');
    $adj->execute([$facility,$asOfExclusive]); $adjustments=(float)$adj->fetchColumn();
    return ['available'=>$available+$adjustments,'disbursed'=>$disbursed,'remaining'=>$available+$adjustments-$disbursed,'anchor_date'=>null];
}

function reporting_disbursement_summary(PDO $pdo, int $year, ?int $throughMonth = null): array {
    $throughMonth = $throughMonth ?? 12;
    $throughMonth = max(1, min(12, $throughMonth));
    $manual = [];
    $actual = [];
    $facility = null;

    // Reporting is used by the application shell itself. A reporting/schema
    // problem must never terminate the page after the sidebar has rendered.
    // Resolve the facility first, then independently attempt the two data
    // sources. If either source is unavailable, its contribution simply falls
    // back to zero/live data instead of leaving an undefined $facility or
    // uncaught PDO exception.
    try {
        $facility = active_data_facility($pdo);
    } catch (Throwable $e) {
        $facility = null;
    }

    if ($facility !== null && $facility !== '') {
        try {
            $stmt = $pdo->prepare("SELECT month_no, amount, patient_count FROM monthly_disbursement_summary WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND year=? AND month_no BETWEEN 1 AND ? ORDER BY month_no");
            $stmt->execute([$facility,$year, $throughMonth]);
            foreach ($stmt->fetchAll() as $r) {
                $manual[(int)$r['month_no']] = ['amount'=>(float)$r['amount'], 'clients'=>(int)$r['patient_count']];
            }
        } catch (Throwable $e) {
            $manual = [];
        }

        try {
            $stmt = $pdo->prepare("SELECT MONTH(release_date) AS month_no, COUNT(*) AS clients, COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date IS NOT NULL AND release_date<>'' AND release_date<>'0000-00-00' AND YEAR(release_date)=? AND MONTH(release_date) BETWEEN 1 AND ? GROUP BY MONTH(release_date)");
            $stmt->execute([$facility,$year, $throughMonth]);
            foreach ($stmt->fetchAll() as $r) {
                $actual[(int)$r['month_no']] = ['amount'=>(float)$r['amount'], 'clients'=>(int)$r['clients']];
            }
        } catch (Throwable $e) {
            $actual = [];
        }
    }

    $monthly = []; $amountTotal = 0.0; $clientTotal = 0;
    for ($m=1; $m<=12; $m++) {
        if ($m > $throughMonth) { $monthly[$m] = ['amount'=>0.0,'clients'=>0,'source'=>'future']; continue; }

        // Record-level RELEASED data is the live source of truth whenever the
        // month contains released records. The stored monthly summary is only
        // a fallback for a month with no record-level releases (for example an
        // imported historical summary). This prevents dashboard figures from
        // becoming stale after a record is released, corrected, or removed.
        if (array_key_exists($m, $actual)) {
            $v = $actual[$m];
            $saved = $manual[$m] ?? null;
            $differs = $saved !== null && (abs((float)$v['amount']-(float)$saved['amount'])>=0.01 || (int)$v['clients']!==(int)$saved['clients']);
            $monthly[$m] = $v + [
                'source'=>'records',
                'stored_summary_amount'=>$saved['amount'] ?? null,
                'stored_summary_clients'=>$saved['clients'] ?? null,
                'summary_discrepancy'=>$differs,
            ];
        } elseif (array_key_exists($m, $manual)) {
            $monthly[$m] = $manual[$m] + [
                'source'=>'summary-fallback',
                'stored_summary_amount'=>$manual[$m]['amount'],
                'stored_summary_clients'=>$manual[$m]['clients'],
                'summary_discrepancy'=>false,
            ];
        } else {
            $monthly[$m] = ['amount'=>0.0,'clients'=>0,'source'=>'records','stored_summary_amount'=>null,'stored_summary_clients'=>null,'summary_discrepancy'=>false];
        }
        $amountTotal += $monthly[$m]['amount'];
        $clientTotal += $monthly[$m]['clients'];
    }
    return ['monthly'=>$monthly, 'amount'=>$amountTotal, 'clients'=>$clientTotal];
}

function fund_source_logo(string $role): array {
    $u=current_user()??[];
    $facility=user_facility($u);
    $info=facility_info($facility);
    return [($info['logo']??'lpgma-logo.png'),($info['short']??$facility)];
}
function role_class(string $role): string { return match($role){ 'admin'=>'role-admin','manager'=>'role-manager','encoder'=>'role-staff','social_worker'=>'role-social-worker', default=>'role-other' }; }
function office_label(string $role): string { return user_facility(current_user()??[]); }
function is_manager(): bool { return (current_user()['role'] ?? '') === 'manager'; }
function ensure_user_role_schema(PDO $pdo): void { /* role is VARCHAR to support administrator-managed user types. */ }
function office_logo_file(string $role): string { $u=current_user() ?: []; return user_facility($u)!=='LPGMA' ? (facility_logo(user_facility($u)) ?? 'lpgma-logo.png') : 'lpgma-logo.png'; }
function user_name_initials(string $fullName): string {
    $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '';
    $letters = '';
    foreach ($parts as $part) {
        $ch = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        $letters .= $ch;
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($letters, 'UTF-8') : strtoupper($letters);
}
function avatar_markup(string $role, string $sizeClass='', $avatarRef=null): string {
    $src='';
    if ($avatarRef !== null && $avatarRef !== '' && ctype_digit((string)$avatarRef) && (int)$avatarRef > 0) {
        $src='avatar.php?user='.(int)$avatarRef.'&v='.rawurlencode(defined('APP_VERSION') ? APP_VERSION : '1');
    }
    if ($src !== '') {
        return '<span class="user-avatar custom-avatar '.e($sizeClass).'"><img src="'.e($src).'" width="256" height="256" decoding="sync" fetchpriority="low" data-user-avatar="1" data-avatar-user-id="'.(int)$avatarRef.'" alt="User avatar"></span>';
    }
    $defaultKey=($role==='admin') ? 'default_admin_avatar' : 'default_user_avatar';
    $alt=($role==='admin') ? 'Site Admin default avatar' : 'Default user silhouette';
    return '<span class="user-avatar default-avatar '.($role==='admin'?'':'generic-avatar ').e($sizeClass).'">'.'<img src="'.e(system_media_url($defaultKey)).'" width="256" height="256" decoding="sync" fetchpriority="low" data-avatar-fallback="'.e($role==='admin'?'default_admin_avatar':'default_user_avatar').'" data-user-avatar="1" alt="'.e($alt).'">'.'</span>';
}
