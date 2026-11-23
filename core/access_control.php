<?php
/**
 * Canonical authorization architecture for the unified FAMS platform.
 *
 * This is a policy description/catalog, not a replacement for endpoint enforcement.
 * Existing workspace helpers remain authoritative for record-level checks until
 * each endpoint is migrated and covered by authenticated integration tests.
 * Read visibility is deliberately distinct from mutation scope.
 */
function fams_authorization_model(): array {
    return [
        'model_version' => 1,
        'decision_order' => ['authenticated_session','workspace_role','capability','workspace_scope','record_ownership','workflow_state'],
        'deny_by_default' => true,
        'ui_visibility_is_authorization' => false,
        'workspaces' => [
            'AICS' => [
                'identity_key' => 'AICS',
                'scope_dimension' => 'office_id',
                'read_scope' => 'workspace_records',
                'roles' => [
                    'admin' => ['read'=>'workspace_records','mutate'=>'all_authorized_offices','workflow'=>'administrator_policy'],
                    'manager' => ['read'=>'workspace_records','mutate'=>'lpgma_office_context_only','workflow'=>'capability_and_ticket_policy'],
                    'staff' => ['read'=>'workspace_records','mutate'=>'lpgma_or_assigned_office','workflow'=>'waiting_direct_edit_other_states_ticket_or_admin'],
                    'social_worker' => ['read'=>'workspace_records','mutate'=>'assigned_swad_office','workflow'=>'waiting_direct_edit_other_states_ticket_or_admin'],
                ],
                'invariants' => ['swad_cross_office_read_visibility','office_scoped_mutations','ticket_controlled_locked_records','workspace_specific_user_types'],
            ],
            'MAIFIP' => [
                'identity_key' => 'MAIFIP',
                'scope_dimension' => 'facility',
                'read_scope' => 'active_facility',
                'roles' => [
                    'admin' => ['read'=>'active_facility','mutate'=>'authorized_facility_context','workflow'=>'administrator_policy'],
                    'manager' => ['read'=>'active_facility','mutate'=>'lpgma_facility_context','workflow'=>'waiting_direct_edit_other_states_ticket_or_admin'],
                    'encoder' => ['read'=>'active_facility','mutate'=>'lpgma_facility_context','workflow'=>'waiting_direct_edit_other_states_ticket_or_admin'],
                    'staff' => ['read'=>'active_facility','mutate'=>'lpgma_facility_context','workflow'=>'waiting_direct_edit_other_states_ticket_or_admin'],
                    'social_worker' => ['read'=>'active_facility','mutate'=>'assigned_facility','workflow'=>'waiting_status_and_ticket_policy'],
                ],
                'invariants' => ['active_facility_data_boundary','facility_qualified_mutations','ticket_controlled_locked_records','workspace_specific_user_types'],
            ],
        ],
        'shared_requirements' => ['server_side_capability_check','server_side_scope_check','record_state_validation','csrf_for_state_changes','method_allowlist','audit_sensitive_mutations','transactional_multi_step_writes'],
    ];
}

/** Return the declared role policy without applying it to a live request. */
function fams_authorization_role_policy(string $system, string $baseRole): ?array {
    $system = strtoupper(trim($system));
    $model = fams_authorization_model();
    return $model['workspaces'][$system]['roles'][$baseRole] ?? null;
}

/** Shared, system-scoped user-type registry and permission matrix. */
function fams_permission_catalog(): array {
    return [
        'view_dashboard'=>'View dashboard', 'view_records'=>'View assistance records',
        'create_records'=>'Create assistance records', 'edit_records'=>'Edit permitted records',
        'update_status'=>'Update assistance status', 'submit_tickets'=>'Submit revision/deletion tickets',
        'manage_tickets'=>'Review and decide tickets', 'export_records'=>'Export / print records',
        'view_reports'=>'View reports', 'use_chat'=>'Use chat and groups',
        'manage_funds'=>'Manage fund settings', 'manage_users'=>'Manage user accounts',
        'manage_usertypes'=>'Manage user types and permissions', 'manage_offices'=>'Manage offices / facilities',
        'view_action_logs'=>'View action logs'
    ];
}
function fams_default_type_permissions(string $system, string $baseRole): array {
    $all=array_fill_keys(array_keys(fams_permission_catalog()), true);
    if ($baseRole==='admin') return $all;
    $sets = $system==='AICS' ? [
        'manager'=>['view_dashboard','view_records','export_records','view_reports','use_chat'],
        'staff'=>['view_dashboard','view_records','create_records','edit_records','update_status','submit_tickets','export_records','view_reports','use_chat'],
        'social_worker'=>['view_records','update_status','submit_tickets','use_chat'],
    ] : [
        'manager'=>['view_dashboard','view_records','export_records','view_reports','use_chat'],
        'encoder'=>['view_dashboard','view_records','create_records','edit_records','update_status','submit_tickets','export_records','view_reports','use_chat'],
        'staff'=>['view_dashboard','view_records','create_records','edit_records','update_status','submit_tickets','export_records','view_reports','use_chat'],
        'social_worker'=>['view_records','update_status','submit_tickets','use_chat'],
    ];
    return array_fill_keys($sets[$baseRole] ?? ['view_records'], true) + array_fill_keys(array_diff(array_keys(fams_permission_catalog()), $sets[$baseRole] ?? ['view_records']), false);
}
function fams_ensure_usertype_schema(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fams_user_types (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          system_key ENUM('AICS','MAIFIP') NOT NULL,
          role_key VARCHAR(48) NOT NULL,
          label VARCHAR(90) NOT NULL,
          base_role VARCHAR(32) NOT NULL,
          is_system TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          permissions_json LONGTEXT NOT NULL,
          created_by INT UNSIGNED NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_fams_user_type (system_key,role_key), KEY idx_fams_user_type_active(system_key,is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $seeds = [
          'AICS'=>['admin'=>['Administrator','admin'],'manager'=>['Manager','manager'],'staff'=>['Staff','staff'],'social_worker'=>['Social Worker','social_worker']],
          'MAIFIP'=>['admin'=>['Admin / Site Admin','admin'],'manager'=>['LPGMA Manager','manager'],'encoder'=>['LPGMA Staff','encoder'],'social_worker'=>['Social Worker','social_worker'],'staff'=>['Staff','staff']]
        ];
        $ins=$pdo->prepare('INSERT IGNORE INTO fams_user_types(system_key,role_key,label,base_role,is_system,is_active,permissions_json) VALUES(?,?,?,?,1,1,?)');
        foreach($seeds as $sys=>$roles) foreach($roles as $key=>$def) {
            $perms=fams_default_type_permissions($sys,$def[1]);
            $ins->execute([$sys,$key,$def[0],$def[1],json_encode($perms,JSON_UNESCAPED_SLASHES)]);
        }
        // Legacy installations used ENUMs, which prevent adding administrator-defined types.
        foreach ([['users','MAIFIP'],['aics_users','AICS']] as [$table,$system]) {
            try {
                $col=$pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$pdo->quote($table)." AND COLUMN_NAME='role'")->fetchColumn();
                if ($col && stripos((string)$col,'enum(')===0) $pdo->exec("ALTER TABLE `{$table}` MODIFY `role` VARCHAR(48) NOT NULL DEFAULT '".($system==='AICS'?'staff':'encoder')."'");
            } catch (Throwable $migrationError) { throw new RuntimeException('FAMS user-type role schema migration failed for '.$table.'.',0,$migrationError); }
        }
    } catch (Throwable $e) { error_log('FAMS user-type registry initialization: '.$e->getMessage()); throw new RuntimeException('User permissions could not be initialized safely. Please contact the system administrator.',0,$e); }
}
function fams_type_definition(PDO $pdo, string $system, string $roleKey): ?array {
    // User types are workspace-scoped. Never borrow a similarly named role from
    // the other workspace: that can silently transfer privileges across systems.
    try {
        $s=$pdo->prepare('SELECT * FROM fams_user_types WHERE system_key=? AND role_key=? LIMIT 1');
        $s->execute([strtoupper($system),$roleKey]);
        $r=$s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch(Throwable $e) { return null; }
}
function fams_base_role(PDO $pdo, string $system, string $roleKey): string {
    $d=fams_type_definition($pdo,$system,$roleKey);
    if (!$d) { $builtins=strtoupper($system)==='AICS'?['admin','manager','staff','social_worker']:['admin','manager','encoder','social_worker','staff']; return in_array($roleKey,$builtins,true)?$roleKey:'__disabled__'; }
    if ((int)$d['is_active']!==1) return '__disabled__';
    return (string)$d['base_role'];
}
function fams_user_type_label(PDO $pdo, string $system, string $roleKey): string {
    $d=fams_type_definition($pdo,$system,$roleKey); return $d ? (string)$d['label'] : ucwords(str_replace('_',' ',$roleKey));
}
function fams_list_user_types(PDO $pdo, string $system, bool $activeOnly=false): array {
    try { $sql='SELECT * FROM fams_user_types WHERE system_key=?'.($activeOnly?' AND is_active=1':'').' ORDER BY is_system DESC,label'; $s=$pdo->prepare($sql); $s->execute([strtoupper($system)]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch(Throwable $e) { $defs=strtoupper($system)==='AICS'?['admin'=>['Administrator','admin'],'manager'=>['Manager','manager'],'staff'=>['Staff','staff'],'social_worker'=>['Social Worker','social_worker']]:['admin'=>['Admin / Site Admin','admin'],'manager'=>['LPGMA Manager','manager'],'encoder'=>['LPGMA Staff','encoder'],'social_worker'=>['Social Worker','social_worker'],'staff'=>['Staff','staff']]; $rows=[]; foreach($defs as $key=>$d)$rows[]=['id'=>0,'system_key'=>strtoupper($system),'role_key'=>$key,'label'=>$d[0],'base_role'=>$d[1],'is_system'=>1,'is_active'=>1,'permissions_json'=>json_encode(fams_default_type_permissions(strtoupper($system),$d[1]))]; return $rows; }
}
function fams_current_type_allows(string $system, string $permission): bool {
    $system=strtoupper(trim($system));
    if (!in_array($system, ['AICS','MAIFIP'], true) || !array_key_exists($permission, fams_permission_catalog())) return false;
    $u=$_SESSION['user']??[];
    if (!is_array($u) || (int)($u['id']??0)<=0) return false;
    $key=(string)($u['user_type_key']??$u['role']??'');
    $pdo=$GLOBALS['pdo']??null;
    if (!($pdo instanceof PDO)) {
        // Deterministic CLI/offline fallback only recognizes built-in roles.
        $builtins=$system==='AICS'?['admin','manager','staff','social_worker']:['admin','manager','encoder','social_worker','staff'];
        if (!in_array($key,$builtins,true)) return false;
        $base=(string)($u['role']??$key);
        return !empty(fams_default_type_permissions($system,$base)[$permission]);
    }
    // With a live database, the active workspace-specific registry is authoritative.
    // Missing, disabled, malformed, or cross-workspace types fail closed, including admins.
    $d=fams_type_definition($pdo,$system,$key);
    if (!$d || (string)($d['system_key']??'')!==$system || (int)($d['is_active']??0)!==1) return false;
    $perms=json_decode((string)($d['permissions_json']??''),true);
    if (!is_array($perms) || empty($perms[$permission])) return false;
    $baseRole=(string)($d['base_role']??'');
    if (!in_array($baseRole,['admin','manager','staff','encoder','social_worker'],true)) return false;
    if ($baseRole==='admin' && ((int)($d['is_system']??0)!==1 || (string)($d['role_key']??'')!=='admin')) return false;
    // Custom permission JSON may narrow, never elevate, the role template.
    $baseline=fams_default_type_permissions($system,$baseRole);
    return !empty($baseline[$permission]);
}


/** Enforce workspace-local administrator authority for account/type administration.
 * A local administrator may administer only the workspace authenticated in this session.
 * Cross-workspace administration is reserved for the central LPGMA identity and still
 * requires an active administrator type in the target workspace.
 */
function fams_workspace_admin_allowed(string $targetSystem, string $permission): bool {
    $targetSystem = strtoupper(trim($targetSystem));
    if (!in_array($targetSystem, ['AICS','MAIFIP'], true)) return false;
    $user = $_SESSION['user'] ?? [];
    if (!is_array($user) || (string)($user['role'] ?? '') !== 'admin') return false;
    $activeSystem = strtolower((string)($_SESSION['fams_system'] ?? ''));
    $targetLower = strtolower($targetSystem);
    $centralLpgma = function_exists('fams_current_lpgma_user_id') && fams_current_lpgma_user_id() > 0;
    if ($activeSystem !== $targetLower && !$centralLpgma) return false;
    return fams_current_type_allows($targetSystem, $permission);
}

function fams_current_type_label(string $system): string {
    $u=$_SESSION['user']??[]; return (string)($u['user_type_label']??$u['user_type_key']??$u['role']??'User');
}
function fams_type_assignment_valid(PDO $pdo, string $system, string $roleKey, string $facilityOrOffice=''): void {
    $system=strtoupper(trim($system));
    if (!in_array($system,['AICS','MAIFIP'],true)) throw new RuntimeException('Invalid workspace for account assignment.');
    $st=$pdo->prepare('SELECT * FROM fams_user_types WHERE system_key=? AND role_key=? LIMIT 1');$st->execute([strtoupper($system),$roleKey]);$d=$st->fetch(PDO::FETCH_ASSOC);
    if(!$d || (int)$d['is_active']!==1 || (string)$d['system_key']!==$system) throw new RuntimeException('This user type is disabled or unavailable in this workspace. Choose an active user type.');
    $base=(string)$d['base_role'];
    if ($base==='admin' && ((int)($d['is_system']??0)!==1 || (string)$d['role_key']!=='admin')) throw new RuntimeException('Administrator privileges are reserved for the protected built-in Administrator type.');
    if($system==='AICS') {
        $office=(int)$facilityOrOffice;
        if($base==='social_worker') { if(!function_exists('office_is_swad_subfacility') || !office_is_swad_subfacility($pdo,$office)) throw new RuntimeException('This user type must be assigned to an active SWAD office.'); }
        elseif($office!==1) throw new RuntimeException('This user type is restricted to the LPGMA office.');
    } else {
        $facility=strtoupper(trim($facilityOrOffice));
        if($base==='social_worker' && ($facility==='' || $facility==='LPGMA')) throw new RuntimeException('Social Workers must be assigned to an active medical facility.');
        if($base!=='social_worker' && $facility!=='' && $facility!=='LPGMA') throw new RuntimeException('This user type is restricted to the LPGMA office.');
    }
}
