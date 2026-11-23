<?php
/**
 * MySQL staging test for FAMS idempotency and transaction behavior.
 *
 * Required environment:
 *   FAMS_ALLOW_DB_TESTS=1
 *   FAMS_TEST_DSN='mysql:host=127.0.0.1;dbname=fams_staging;charset=utf8mb4'
 *   FAMS_TEST_DB_USER='...'
 *   FAMS_TEST_DB_PASS='...'
 *
 * The database name must contain "test" or "staging". The script only creates
 * uniquely named temporary test data in the selected database and removes it.
 * It never loads the application config or uses the production database.
 */
declare(strict_types=1);
require_once __DIR__.'/../../core/shared_runtime.php';

$dsn=(string)(getenv('FAMS_TEST_DSN')?:'');
$user=(string)(getenv('FAMS_TEST_DB_USER')?:'');
$pass=(string)(getenv('FAMS_TEST_DB_PASS')?:'');
if(getenv('FAMS_ALLOW_DB_TESTS')!=='1'||$dsn===''||!preg_match('/dbname=([^;]+)/i',$dsn,$m)||!preg_match('/(test|staging)/i',$m[1])){
    fwrite(STDERR,"REFUSED: Set FAMS_ALLOW_DB_TESTS=1 and a FAMS_TEST_DSN whose database name contains test or staging.\n");exit(2);
}
if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"SKIP: pdo_mysql is not installed.\n");exit(2);}
$passed=0;$failed=0;$skipped=0;
$assert=static function(bool $ok,string $label)use(&$passed,&$failed):void{if($ok){$passed++;echo "PASS: {$label}\n";}else{$failed++;fwrite(STDERR,"FAIL: {$label}\n");}};
$connect=static fn():PDO=>new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db=$connect();
$driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);if($driver!=='mysql'){fwrite(STDERR,"REFUSED: MySQL is required.\n");exit(2);}
fams_shared_ensure_idempotency_schema($db);
$scope='test.mysql.'.bin2hex(random_bytes(6));$userId=random_int(700000000,799999999);
$table='fams_test_tx_'.bin2hex(random_bytes(5));
$db->exec("CREATE TABLE `{$table}` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, note VARCHAR(80) NOT NULL, record_version INT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB");
$keys=[];
try{
    // A failed business operation must roll back both the claim and its writes.
    $rollbackKey=fams_shared_idempotency_key();$keys[]=$rollbackKey;$rollbackDigest=hash('sha256',$rollbackKey);
    try{fams_shared_idempotent_transaction($db,$scope.'.rollback',$rollbackKey,$userId,function(PDO $tx)use($table):void{$tx->exec("INSERT INTO `{$table}`(note) VALUES('rollback')");throw new RuntimeException('forced rollback');});}catch(RuntimeException $e){}
    $q=$db->prepare('SELECT COUNT(*) FROM fams_idempotency_keys WHERE user_id=? AND scope_key=? AND request_key=?');$q->execute([$userId,$scope.'.rollback',$rollbackDigest]);
    $assert((int)$q->fetchColumn()===0,'failed operation rolls back its idempotency claim');
    $q=$db->query("SELECT COUNT(*) FROM `{$table}` WHERE note='rollback'");$assert((int)$q->fetchColumn()===0,'failed operation rolls back its business write');

    // A committed key must suppress a sequential retry.
    $singleKey=fams_shared_idempotency_key();$keys[]=$singleKey;$writes=0;
    $first=fams_shared_idempotent_transaction($db,$scope.'.single',$singleKey,$userId,function(PDO $tx)use($table,&$writes):string{$tx->prepare("INSERT INTO `{$table}`(note) VALUES(?)")->execute(['single']);$writes++;return 'ok';},['record'=>'SINGLE']);
    $retry=fams_shared_idempotent_transaction($db,$scope.'.single',$singleKey,$userId,function(PDO $tx)use(&$writes):void{$writes++;},['record'=>'SINGLE']);
    $assert(empty($first['duplicate'])&&!empty($retry['duplicate'])&&$writes===1,'committed duplicate submission with identical payload is applied once');
    $payloadConflict=false;try{fams_shared_idempotent_transaction($db,$scope.'.single',$singleKey,$userId,static function(PDO $tx):void{},['record'=>'DIFFERENT']);}catch(FamsIdempotencyConflict $e){$payloadConflict=true;}
    $assert($payloadConflict,'reusing a committed key with a different payload is rejected as a conflict');

    // Optimistic record versions must reject stale writes after a successful update.
    $db->prepare("INSERT INTO `{$table}`(note,record_version) VALUES('version',1)")->execute();$recordId=(int)$db->lastInsertId();
    $versionUpdate=$db->prepare("UPDATE `{$table}` SET note='version-2',record_version=record_version+1 WHERE id=? AND record_version=1");$versionUpdate->execute([$recordId]);
    $staleUpdate=$db->prepare("UPDATE `{$table}` SET note='stale',record_version=record_version+1 WHERE id=? AND record_version=1");$staleUpdate->execute([$recordId]);
    $assert($versionUpdate->rowCount()===1&&$staleUpdate->rowCount()===0,'record-version compare-and-swap rejects stale updates');

    // Two independent PHP CLI processes contend for the same unique claim.
    if(function_exists('proc_open')){
        $raceKey=fams_shared_idempotency_key();$keys[]=$raceKey;$raceScope=$scope.'.race';
        $command=[PHP_BINARY,__DIR__.'/mysql_concurrency_worker.php',$raceScope,$raceKey,(string)$userId];
        $pipes=[];$proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($proc)){$skipped++;echo "SKIP: unable to start the concurrent worker process.\n";}
        else{
            $ready=trim((string)fgets($pipes[1]));
            if($ready!=='READY'){$err=stream_get_contents($pipes[2]);fwrite(STDERR,"Concurrent worker failed to initialize: {$err}\n");$skipped++;fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);}
            else{
                $db->beginTransaction();$claimed=fams_shared_idempotency_claim($db,$raceScope,$raceKey,$userId);
                fwrite($pipes[0],"GO\n");fflush($pipes[0]);$attempt=trim((string)fgets($pipes[1]));
                if($attempt!=='ATTEMPT'){$db->rollBack();$assert(false,'second process started its competing request');}
                else{$db->commit();$outcome=trim((string)fgets($pipes[1]));$assert($claimed&&$outcome==='DUPLICATE','simultaneous duplicate request waits and is rejected by the unique key');}
                fclose($pipes[0]);fclose($pipes[1]);$workerError=stream_get_contents($pipes[2]);fclose($pipes[2]);$workerExit=proc_close($proc);
                if($workerExit!==0)fwrite(STDERR,"Concurrent worker exit {$workerExit}: {$workerError}\n");
            }
        }
    }else{$skipped++;echo "SKIP: proc_open unavailable; simultaneous-process test not run.\n";}

}finally{
    foreach($keys as $key){$st=$db->prepare('DELETE FROM fams_idempotency_keys WHERE user_id=? AND scope_key LIKE ? AND request_key=?');$st->execute([$userId,$scope.'%',hash('sha256',$key)]);}
    $db->exec("DROP TABLE IF EXISTS `{$table}`");
}
printf("\nMySQL staging tests: %d passed, %d failed, %d skipped.\n",$passed,$failed,$skipped);
exit($failed===0?0:1);
