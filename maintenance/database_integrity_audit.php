<?php
declare(strict_types=1);
/**
 * Read-only FAMS schema/data integrity audit. CLI only; emits aggregate counts,
 * never beneficiary/patient/account row contents. Requires an explicitly
 * confirmed non-production database name and a read-only database account.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = trim((string)getenv('FAMS_AUDIT_DSN'));
$user = (string)getenv('FAMS_AUDIT_DB_USER');
$pass = (string)getenv('FAMS_AUDIT_DB_PASS');
$confirm = trim((string)getenv('FAMS_AUDIT_CONFIRM_DB'));
if ($dsn === '' || $confirm === '') {
    fwrite(STDERR, "Set FAMS_AUDIT_DSN and FAMS_AUDIT_CONFIRM_DB to the exact disposable/local database name.\n");
    exit(2);
}
if (preg_match('/(?:^|[;])dbname=([^;]+)/i', $dsn, $m) !== 1) {
    fwrite(STDERR, "FAMS_AUDIT_DSN must explicitly include dbname=.\n"); exit(2);
}
$dsnDb = trim($m[1]);
if ($dsnDb === '' || !hash_equals($dsnDb, $confirm) || preg_match('/prod|production|live/i', $dsnDb)) {
    fwrite(STDERR, "Database name confirmation failed or the name appears production-like; audit refused.\n"); exit(2);
}
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $actual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!hash_equals($confirm, $actual)) throw new RuntimeException('Connected database does not match the explicit confirmation.');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $schema = $actual;
    $tables = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $tableSet = array_fill_keys($tables, true);
    $columns = [];
    $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $has = static function(string $table, string $column) use (&$columns,$colStmt,$tableSet): bool {
        if (!isset($tableSet[$table])) return false;
        if (!isset($columns[$table])) { $colStmt->execute([$table]); $columns[$table]=array_fill_keys($colStmt->fetchAll(PDO::FETCH_COLUMN),true); }
        return isset($columns[$table][$column]);
    };
    $results = ['database'=>$schema,'mode'=>'READ ONLY','tables_checked'=>0,'checks'=>[],'foreign_keys'=>[]];
    $add = static function(string $name, string $status, int $count=0, string $detail='') use (&$results): void {
        $results['checks'][]=['check'=>$name,'status'=>$status,'count'=>$count,'detail'=>$detail];
    };
    $dupChecks = [
        ['AICS duplicate control numbers','aics_assistance_records',['control_number']],
        ['MAIFIP duplicate facility/control-number pairs','assistance_records',['facility','control_number']],
        ['AICS duplicate ticket numbers','aics_tickets',['ticket_no']],
        ['MAIFIP duplicate ticket numbers','revision_tickets',['ticket_number']],
        ['Duplicate offline client change IDs','offline_sync_log',['client_change_id']],
    ];
    foreach ($dupChecks as [$label,$table,$fields]) {
        if (!isset($tableSet[$table]) || count(array_filter($fields,static fn($f)=>$has($table,$f)))!==count($fields)) { $add($label,'NOT_APPLICABLE',0,'Table/columns not present in this schema.'); continue; }
        $group = implode(',',array_map(static fn($f)=>'`'.$f.'`',$fields));
        $sql = "SELECT COUNT(*) FROM (SELECT {$group} FROM `{$table}` GROUP BY {$group} HAVING COUNT(*)>1) d";
        $count=(int)$pdo->query($sql)->fetchColumn(); $add($label,$count?'REVIEW':'PASS',$count,$count?'Duplicate groups exist; no rows were changed.':'No duplicate groups found.');
    }
    $orphanChecks = [
        ['AICS ticket-to-record references','aics_tickets','record_id','aics_assistance_records'],
        ['AICS status-history-to-record references','aics_status_history','record_id','aics_assistance_records'],
        ['MAIFIP ticket-to-record references','revision_tickets','record_id','assistance_records'],
        ['MAIFIP status-history-to-record references','status_history','record_id','assistance_records'],
        ['MAIFIP offline-sync-to-record references','offline_sync_log','record_id','assistance_records'],
    ];
    foreach ($orphanChecks as [$label,$child,$fk,$parent]) {
        if (!$has($child,$fk) || !$has($parent,'id')) { $add($label,'NOT_APPLICABLE',0,'Reference columns not present.'); continue; }
        $archiveJoin=''; $archiveFilter='';
        if ($has('fams_deleted_records','system_key') && $has('fams_deleted_records','original_id')) {
            $system=str_starts_with($child,'aics_')?'aics':'maifip';
            $archiveJoin=" LEFT JOIN fams_deleted_records ar ON ar.system_key='".$system."' AND ar.original_id=c.`{$fk}`";
            $archiveFilter=' AND ar.id IS NULL';
        }
        $sql="SELECT COUNT(*) FROM `{$child}` c LEFT JOIN `{$parent}` p ON p.id=c.`{$fk}`{$archiveJoin} WHERE c.`{$fk}` IS NOT NULL AND c.`{$fk}`<>0 AND p.id IS NULL{$archiveFilter}";
        $count=(int)$pdo->query($sql)->fetchColumn(); $add($label,$count?'REVIEW':'PASS',$count,$count?'Unresolved orphan references exist; archived-as-deleted references are excluded. No rows were changed.':'No unresolved orphan references found (archived deletions excluded).');
    }
    $fkStmt=$pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,DELETE_RULE,UPDATE_RULE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r USING(CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME) WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');
    $fkStmt->execute(); $results['foreign_keys']=$fkStmt->fetchAll();
    $results['tables_checked']=count($tables);
    $pdo->commit();
    $review= count(array_filter($results['checks'],static fn($r)=>$r['status']==='REVIEW'));
    $na=count(array_filter($results['checks'],static fn($r)=>$r['status']==='NOT_APPLICABLE'));
    $results['summary']=['review_items'=>$review,'not_applicable'=>$na,'check_count'=>count($results['checks'])];
    echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    exit($review>0?1:0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'Integrity audit failed safely: '.$e->getMessage().PHP_EOL); exit(3);
}
