<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/core/shared_runtime.php';
require_once $root.'/core/audit_runtime.php';
$checks=0;$failures=[];
$assert=static function(bool $ok,string $label)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$label;};
$read=static function(string $file)use($root):string{$v=file_get_contents($root.'/'.$file);if(!is_string($v))throw new RuntimeException('Cannot read '.$file);return $v;};

final class FamsRetryPdoDouble extends PDO {
    public bool $active=false; public int $failuresRemaining=1; public array $calls=[];
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function beginTransaction():bool{$this->active=true;$this->calls[]='BEGIN';return true;}
    public function commit():bool{$this->active=false;$this->calls[]='COMMIT';return true;}
    public function rollBack():bool{$this->active=false;$this->calls[]='ROLLBACK';return true;}
    public function exec(string $statement):int|false{$this->calls[]=$statement;return 0;}
}
$db=new FamsRetryPdoDouble();$runs=0;
$result=fams_shared_transaction_retry($db,function()use($db,&$runs):string{$runs++;if($db->failuresRemaining-->0){$e=new PDOException('deadlock');$e->errorInfo=['40001',1213,'deadlock'];throw $e;}return 'ok';},3);
$assert($result==='ok'&&$runs===2&&!$db->active&&count(array_filter($db->calls,static fn($v)=>$v==='BEGIN'))===2,'recognized deadlock retries a top-level DB-only transaction');
$db=new FamsRetryPdoDouble();$runs=0;
try{fams_shared_transaction_retry($db,function()use(&$runs):void{$runs++;throw new RuntimeException('validation');},3);}catch(RuntimeException $e){}
$assert($runs===1,'validation and non-database failures are never retried');
$db=new FamsRetryPdoDouble();$db->active=true;$runs=0;
$result=fams_shared_transaction_retry($db,function()use(&$runs):int{$runs++;return 9;},3);
$assert($result===9&&$runs===1&&$db->active,'nested transaction does not independently retry or commit its caller');
$msg=fams_shared_user_error(new RuntimeException('The request is invalid.'),'Generic failure.');
$runtimeSource=$read('core/shared_runtime.php');
$assert($msg==='The request is invalid.'&&str_contains($runtimeSource,"error_log('FAMS database operation failed: " )&&str_contains($runtimeSource,'return $fallback;'),'database details are logged internally while safe fallback text is returned');
$assert(str_contains($runtimeSource,'$vendor===1213 || $vendor===1205')&&str_contains($runtimeSource,'another request'),'deadlock and lock-timeout errors receive actionable retry guidance');
$assert(fams_audit_action_is_critical('UPDATE_STATUS')&&fams_audit_action_is_critical('REMOVE_USER')&&!fams_audit_action_is_critical('VIEW_DASHBOARD'),'critical audit action classification is explicit');

$account=$read('core/account_runtime.php');
$assert(str_contains($account,"['users', 'aics_users']")&&str_contains($account,"ADD COLUMN auth_version")&&str_contains($account,'auth_version=auth_version+1'),'both workspace account tables have monotonic session versions');
$assert(str_contains($account,'Account session invalidation must share the account mutation transaction.'),'session version changes require the account transaction');
$aics=$read('aics/helpers.php');$maifip=$read('maifip/helpers.php');
$assert(str_contains($aics,'$sessionVersion!==$currentVersion')&&str_contains($aics,'Linked LPGMA account inactive or removed.'),'AICS invalidates stale local and bridged LPGMA sessions');
$assert(str_contains($maifip,'$sessionVersion!==$currentVersion')&&str_contains($maifip,'approval_status'),'MAIFIP invalidates stale, disabled, rejected, or removed account sessions');
$assert(str_contains($read('aics/users.php'),'auth_version=auth_version+1')&&str_contains($read('maifip/users.php'),'auth_version=auth_version+1'),'admin account security mutations advance the session version');
$assert(str_contains($read('aics/account.php'),"\$_SESSION['user']['auth_version']")&&str_contains($read('maifip/account.php'),"\$_SESSION['user']['auth_version']"),'self-service profile/password changes refresh the current session version');
$assert(str_contains($read('auth/logout.php'),'session_destroy()')&&str_contains($read('core/unified.php'),'fams_shared_invalidate_session'),'logout and server-side invalidation destroy active session identity');
$chat=$read('core/chat_runtime.php');
$assert(str_contains($chat,'Chat schema migration could not be verified')&&!str_contains($chat,'catch(Throwable $ignored)'),'chat schema migration no longer silently swallows DDL failures');
$access=$read('core/access_control.php');
$assert(str_contains($access,'User permissions could not be initialized safely')&&!str_contains($access,'catch (Throwable $ignored) {}'),'user-type schema migration failures are surfaced rather than silently ignored');
$assert(str_contains($read('aics/status_update.php'),'fams_shared_transaction_retry')&&str_contains($read('maifip/status_update.php'),'fams_shared_transaction_retry'),'both status workflows use bounded transient-conflict retry');

printf("Security resilience: %d checks, %d failures\n",$checks,count($failures));foreach($failures as $f)echo "FAIL: {$f}\n";exit($failures?1:0);
