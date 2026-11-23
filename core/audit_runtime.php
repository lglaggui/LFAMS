<?php
declare(strict_types=1);
/** Shared audit event contract with workspace-specific storage adapters. */
function fams_audit_write(PDO $pdo, string $workspace, string $action, string $description, ?int $recordId = null): void {
    $workspace=strtolower($workspace); $u=is_array($_SESSION['user']??null)?$_SESSION['user']:[];
    $action=substr(trim(preg_replace('/[\r\n\t]+/',' ', $action)),0,80);
    $description=substr(trim(preg_replace('/[\r\n\t]+/',' ', $description)),0,255);
    if($action==='') $action='UNKNOWN_ACTION';
    $userId=isset($u['id'])?(int)$u['id']:null; $username=substr((string)($u['username']??'system'),0,100); $role=substr((string)($u['role']??'system'),0,40);
    $ip=filter_var((string)($_SERVER['REMOTE_ADDR']??''),FILTER_VALIDATE_IP) ?: null;
    if($workspace==='aics') {
        $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,512);
        $st=$pdo->prepare('INSERT INTO aics_action_logs(user_id,username,role,action,description,record_id,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute([$userId,$username,$role,$action,$description,$recordId,$ip,$ua!==''?$ua:null]); return;
    }
    if($workspace==='maifip') {
        if(function_exists('ensure_action_logs_schema')) ensure_action_logs_schema($pdo);
        // Critical audit writes run inside the business transaction. Avoid reverse-DNS
        // network lookups there so retryable transaction callbacks remain database-only.
        $host=null;
        if(!$pdo->inTransaction() && function_exists('fams_client_hostname')) $host=fams_client_hostname($ip);
        $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,512);
        $st=$pdo->prepare('INSERT INTO action_logs(user_id,username,role,action,description,record_id,ip_address,client_hostname,user_agent) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$userId,substr($username,0,80),$role,$action,$description,$recordId,$ip,$host,$ua!==''?$ua:null]); return;
    }
    throw new InvalidArgumentException('Unknown FAMS audit workspace.');
}

/** Classify events whose absence would make a business/security mutation unauditable. */
function fams_audit_action_is_critical(string $action): bool {
    return (bool)preg_match('/^(CREATE|REGISTER|UPDATE|DELETE|REMOVE|RESTORE|TOGGLE|CHANGE_PASSWORD|CHANGE_OWN_PASSWORD|APPROVE|REJECT|IMPORT|OFFLINE_CONFLICT|AUTO_CANCEL|SUBMIT|EDIT|DECIDE|RELEASE|CANCEL|RESET|REVOKE|ASSIGN|UNASSIGN|ADMIN_UPDATE)/i',trim($action));
}

/** Critical events have no standalone-write mode: callers must include them in the mutation transaction. */
function fams_audit_write_critical(PDO $pdo,string $workspace,string $action,string $description,?int $recordId=null): void {
    if(!fams_audit_action_is_critical($action)) throw new InvalidArgumentException('This audit event is not classified as a critical mutation.');
    fams_audit_write_in_transaction($pdo,$workspace,$action,$description,$recordId);
}

/** Critical business mutations must persist their audit event in the same transaction. */
function fams_audit_write_in_transaction(PDO $pdo, string $workspace, string $action, string $description, ?int $recordId = null): void {
    if (!$pdo->inTransaction()) throw new LogicException('Audit events for critical mutations must share the business transaction.');
    fams_audit_write($pdo,$workspace,$action,$description,$recordId);
}

/** Workspace-specific status history adapter with a shared transactional contract. */
function fams_status_history_write(PDO $pdo, string $workspace, int $recordId, ?string $oldStatus, string $newStatus, ?int $changedBy, ?string $remarks = null): void {
    if (!$pdo->inTransaction()) throw new LogicException('Status history must be written in the same transaction as its record mutation.');
    if ($recordId < 1 || trim($newStatus) === '') throw new InvalidArgumentException('A valid record and new status are required for status history.');
    $table=match(strtolower($workspace)) { 'aics'=>'aics_status_history', 'maifip'=>'status_history', default=>throw new InvalidArgumentException('Unknown FAMS history workspace.') };
    $st=$pdo->prepare("INSERT INTO {$table}(record_id,old_status,new_status,changed_by,remarks) VALUES(?,?,?,?,?)");
    $st->execute([$recordId,$oldStatus,$newStatus,$changedBy,$remarks]);
}


/** Archive a record and its workspace-specific status history as one immutable snapshot. */
function fams_archive_record_with_history(PDO $pdo, string $workspace, string $facility, array $record, int $deletedBy): int {
    if(!$pdo->inTransaction()) throw new LogicException('Record archives and history snapshots must share the deletion transaction.');
    $workspace=strtolower($workspace);
    $historyTable=match($workspace){'aics'=>'aics_status_history','maifip'=>'status_history',default=>throw new InvalidArgumentException('Unknown archive workspace.')};
    $recordId=(int)($record['id']??0);
    if($recordId<1)throw new InvalidArgumentException('A valid record ID is required for archival.');
    $history=$pdo->prepare("SELECT * FROM {$historyTable} WHERE record_id=? ORDER BY changed_at ASC,id ASC");
    $history->execute([$recordId]);
    $historyPayload=json_encode($history->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $controlNumber=(string)($record['control_number']??'');
    if($workspace==='aics'){
        $table=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets'");
        if((int)$table->fetchColumn()===1){$tickets=$pdo->prepare('UPDATE aics_tickets SET record_control_number=COALESCE(record_control_number,?),record_id=NULL WHERE record_id=?');$tickets->execute([$controlNumber,$recordId]);}
    }else{
        $table=$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='revision_tickets'");
        if((int)$table->fetchColumn()===1){$tickets=$pdo->prepare('UPDATE revision_tickets SET record_control_number=COALESCE(record_control_number,?),record_facility=COALESCE(record_facility,?),record_id=NULL WHERE record_id=?');$tickets->execute([$controlNumber,$facility,$recordId]);}
    }
    $recordPayload=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $archive=$pdo->prepare('INSERT INTO fams_deleted_records(system_key,facility,original_id,control_number,record_payload,history_payload,deleted_by) VALUES(?,?,?,?,?,?,?)');
    $archive->execute([$workspace,$facility,$recordId,(string)($record['control_number']??''),$recordPayload,$historyPayload,$deletedBy]);
    return (int)$pdo->lastInsertId();
}

/** Shared, validated audit-log filtering and retrieval for both workspaces. */
function fams_audit_normalize_filters(array $input): array {
    $date = static function($value): string {
        $value=trim((string)$value);
        if($value==='') return '';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $d && $d->format('Y-m-d')===$value ? $value : '';
    };
    $from=$date($input['date_from']??''); $to=$date($input['date_to']??'');
    if($from!=='' && $to!=='' && $from>$to){ [$from,$to]=[$to,$from]; }
    return [
        'date_from'=>$from, 'date_to'=>$to,
        'user'=>substr(trim((string)($input['user']??'')),0,80),
        'action'=>substr(trim((string)($input['action']??'')),0,80),
        'q'=>function_exists('fams_shared_normalize_search') ? fams_shared_normalize_search((string)($input['q']??'')) : trim((string)($input['q']??'')),
    ];
}
function fams_audit_where(array $filters): array {
    $where=[]; $params=[];
    if($filters['date_from']!==''){ $where[]='created_at >= ?'; $params[]=$filters['date_from'].' 00:00:00'; }
    if($filters['date_to']!==''){ $where[]='created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[]=$filters['date_to'].' 00:00:00'; }
    if($filters['user']!==''){ $where[]='username = ?'; $params[]=$filters['user']; }
    if($filters['action']!==''){ $where[]='action = ?'; $params[]=$filters['action']; }
    if($filters['q']!==''){
        $fields=['username','role','action','description','CAST(record_id AS CHAR)','ip_address','client_hostname','user_agent'];
        $where[]='('.implode(' OR ',array_map(static fn($field)=>$field.' LIKE ?', $fields)).')';
        $like='%'.$filters['q'].'%'; foreach($fields as $_) $params[]=$like;
    }
    return [$where ? ' WHERE '.implode(' AND ',$where) : '',$params];
}
function fams_audit_table(string $workspace): string {
    return match(strtolower($workspace)) { 'aics'=>'aics_action_logs', 'maifip'=>'action_logs', default=>throw new InvalidArgumentException('Unknown FAMS audit workspace.') };
}
function fams_audit_page(PDO $pdo,string $workspace,array $input,int $requestedPage=1,int $perPage=50): array {
    $table=fams_audit_table($workspace); $filters=fams_audit_normalize_filters($input); [$where,$params]=fams_audit_where($filters);
    $summary=$pdo->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT username) AS users, COUNT(DISTINCT action) AS actions FROM {$table}{$where}");
    $summary->execute($params); $stats=$summary->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'users'=>0,'actions'=>0];
    $total=(int)$stats['total']; $pageWindow=function_exists('fams_shared_page_window') ? fams_shared_page_window($total,max(1,$requestedPage),$perPage) : ['page'=>1,'pages'=>1,'offset'=>0];
    $sql="SELECT id,username,role,action,description,record_id,ip_address,client_hostname,user_agent,created_at FROM {$table}{$where} ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?";
    $st=$pdo->prepare($sql); foreach($params as $i=>$value)$st->bindValue($i+1,$value,PDO::PARAM_STR);
    $st->bindValue(count($params)+1,$perPage,PDO::PARAM_INT); $st->bindValue(count($params)+2,(int)$pageWindow['offset'],PDO::PARAM_INT); $st->execute();
    $users=$pdo->query("SELECT DISTINCT username FROM {$table} WHERE username IS NOT NULL AND username<>'' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
    $actions=$pdo->query("SELECT DISTINCT action FROM {$table} WHERE action IS NOT NULL AND action<>'' ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    return ['filters'=>$filters,'where'=>$where,'params'=>$params,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'stats'=>['total'=>$total,'users'=>(int)$stats['users'],'actions'=>(int)$stats['actions']],'page'=>$pageWindow['page'],'pages'=>max(1,(int)$pageWindow['pages']),'per_page'=>$perPage,'users'=>$users,'actions'=>$actions];
}
function fams_audit_export(PDO $pdo,string $workspace,array $input,int $limit=25000): array {
    $limit=max(1,min(50000,$limit));
    $table=fams_audit_table($workspace); $filters=fams_audit_normalize_filters($input); [$where,$params]=fams_audit_where($filters);
    $sql="SELECT id,created_at,username,role,action,description,record_id,ip_address,client_hostname,user_agent FROM {$table}{$where} ORDER BY created_at DESC,id DESC LIMIT ?";
    $st=$pdo->prepare($sql); foreach($params as $i=>$value)$st->bindValue($i+1,$value,PDO::PARAM_STR); $st->bindValue(count($params)+1,$limit+1,PDO::PARAM_INT); $st->execute();
    $rows=$st->fetchAll(PDO::FETCH_ASSOC); $truncated=count($rows)>$limit; if($truncated) array_pop($rows);
    return ['filters'=>$filters,'rows'=>$rows,'truncated'=>$truncated,'limit'=>$limit];
}
