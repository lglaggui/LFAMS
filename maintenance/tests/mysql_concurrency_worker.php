<?php
declare(strict_types=1);
require_once __DIR__.'/../../core/shared_runtime.php';
$dsn=(string)(getenv('FAMS_TEST_DSN')?:'');$user=(string)(getenv('FAMS_TEST_DB_USER')?:'');$pass=(string)(getenv('FAMS_TEST_DB_PASS')?:'');
$scope=(string)($argv[1]??'');$key=(string)($argv[2]??'');$userId=(int)($argv[3]??0);
try{
 $db=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 // Schema DDL must complete before the parent holds an uncommitted claim.
 fams_shared_ensure_idempotency_schema($db);
 fwrite(STDOUT,"READY\n");fflush(STDOUT);
 $go=fgets(STDIN);if(trim((string)$go)!=='GO')exit(2);
 $db->beginTransaction();fwrite(STDOUT,"ATTEMPT\n");fflush(STDOUT);
 $claimed=fams_shared_idempotency_claim($db,$scope,$key,$userId);$db->commit();
 fwrite(STDOUT,$claimed?"CLAIMED\n":"DUPLICATE\n");fflush(STDOUT);exit($claimed?3:0);
}catch(Throwable $e){if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(4);}
