<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/core/shared_runtime.php';
$passed=0;$failed=0;$check=static function(bool $ok,string $name)use(&$passed,&$failed):void{if($ok){$passed++;echo "PASS: {$name}\n";}else{$failed++;echo "FAIL: {$name}\n";}};
$a=['record'=>['name'=>'ALICE','amount'=>'100.00'],'csrf_token'=>'one','idempotency_key'=>str_repeat('a',48)];
$b=['idempotency_key'=>str_repeat('b',48),'csrf_token'=>'two','record'=>['amount'=>'100.00','name'=>'ALICE']];
$c=['record'=>['name'=>'ALICE','amount'=>'100.01']];
$check(fams_shared_payload_fingerprint($a)===fams_shared_payload_fingerprint($b),'associative order and security tokens do not affect payload fingerprint');
$check(fams_shared_payload_fingerprint($a)!==fams_shared_payload_fingerprint($c),'business-field changes produce a different payload fingerprint');
$check(fams_shared_payload_fingerprint(['rows'=>[['id'=>1],['id'=>2]]])!==fams_shared_payload_fingerprint(['rows'=>[['id'=>2],['id'=>1]]]),'ordered batch rows preserve order in fingerprint');
$check(strlen(fams_shared_payload_fingerprint([]))===64,'fingerprint is a SHA-256 digest');
echo "RESULT: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
