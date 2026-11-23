<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$checks=0;$failed=0;
$assert=function(bool $ok,string $name)use(&$checks,&$failed):void{$checks++;if(!$ok){$failed++;fwrite(STDERR,"FAIL: {$name}\n");}else echo "PASS: {$name}\n";};
$read=static function(string $rel)use($root):string{$p=$root.'/'.$rel;return is_file($p)?(string)file_get_contents($p):'';};
// All AICS control-number writers use one shared advisory lock and one allocator.
foreach(['aics/record_form.php','aics/import_sheet.php','aics/trash.php'] as $f){$s=$read($f);$assert(str_contains($s,"fams:aics:control-sequence"),"AICS sequence lock: {$f}");$assert(str_contains($s,'aics_next_control_number('),"AICS shared allocator: {$f}");}
// All MAIFIP control-number writers share the same facility-keyed lock.
foreach(['maifip/record_form.php','maifip/import_sheet.php','maifip/offline_sync.php','maifip/trash.php'] as $f){$s=$read($f);$assert(str_contains($s,"fams:maifip:"),"MAIFIP facility sequence lock: {$f}");}
// All AICS ticket creation routes serialize ticket number allocation.
foreach(['aics/tickets.php','aics/delete_ticket_request.php','aics/office_reassignment_ticket.php','aics/revision_ticket.php'] as $f){$s=$read($f);$assert(str_contains($s,"fams:aics:ticket-sequence"),"AICS ticket sequence lock: {$f}");$assert(str_contains($s,'aics_next_ticket_number('),"AICS ticket allocator: {$f}");}
$helpers=$read('aics/helpers.php');
$assert(str_contains($helpers,'ADD UNIQUE KEY uq_aics_control_number'), 'AICS unique control-number index migration guard exists');
$assert(str_contains($helpers,'INFORMATION_SCHEMA.TABLES')&&str_contains($helpers,'AUTO_INCREMENT'), 'AICS ticket allocator uses next AUTO_INCREMENT rather than MAX(id)+1');
$assert(str_contains($helpers,'GREATEST(COALESCE(source_no,0),CAST(SUBSTRING_INDEX(control_number'), 'AICS allocator reconciles source_no and control-number suffix');
$assert(str_contains($helpers,'LIMIT 1')&&str_contains($helpers,'uq_aics_control_number'), 'AICS allocator checks exact control-number collision');
echo "\nChecks: {$checks}; failed: {$failed}\n";
exit($failed?1:0);
