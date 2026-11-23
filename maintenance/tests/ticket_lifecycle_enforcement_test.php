<?php
$root=dirname(__DIR__,2);
$checks=0;$failures=[];
function check_ticket(bool $ok,string $name):void{global $checks,$failures;$checks++;if(!$ok)$failures[]=$name;}
function src(string $path):string{$v=file_get_contents(dirname(__DIR__,2).'/'.$path);if($v===false)throw new RuntimeException('Missing source: '.$path);return $v;}
$aics=src('aics/tickets.php');$rev=src('aics/revision_ticket.php');$del=src('aics/delete_ticket_request.php');$office=src('aics/office_reassignment_ticket.php');$create=src('maifip/ticket_create.php');$edit=src('maifip/ticket_edit.php');$review=src('maifip/ticket_review.php');
check_ticket(str_contains($aics,"status IN ('OPEN','IN PROGRESS') LIMIT 1 FOR UPDATE"),'AICS decision locks only pending ticket states');
check_ticket(str_contains($aics,"Reviewed tickets are retained for audit history") && str_contains($aics,'Record-linked workflow tickets cannot be deleted'),'AICS deletion preserves reviewed and record-linked ticket audit history');
check_ticket(str_contains($aics,'fams_status_history_write($db,\'aics\''),'AICS approved status revisions write status history');
check_ticket(str_contains($aics,'_base_record_version') && str_contains($aics,'record_version=?'),'AICS ticket approval enforces original record version');
check_ticket(str_contains($rev,'can_access_record_scope($current)') && str_contains($rev,'LIMIT 1 FOR UPDATE'),'AICS revision submission rechecks record scope under lock');
check_ticket(str_contains($del,'can_access_record_scope($current)') && str_contains($del,'LIMIT 1 FOR UPDATE'),'AICS delete submission rechecks record scope under lock');
check_ticket(str_contains($office,'can_access_record_scope($current)') && str_contains($office,'LIMIT 1 FOR UPDATE'),'AICS reassignment submission rechecks record scope under lock');
check_ticket(str_contains($create,'LIMIT 1 FOR UPDATE') && str_contains($create,'recordBaseVersion'),'MAIFIP ticket creation locks and validates the base record version');
check_ticket(str_contains($edit,'ticketBaseVersion') && str_contains($edit,'Create a new ticket using the latest record values'),'MAIFIP ticket editing cannot silently rebase stale proposals');
check_ticket(str_contains($review,"WHERE id=? AND status='OPEN'") && str_contains($review,'FOR UPDATE'),'MAIFIP decisions require an open ticket locked in transaction');
check_ticket(str_contains($review,'record_version=?') && str_contains($review,'status_history'),'MAIFIP approval applies record-version guard and status history');
check_ticket(str_contains($review,'resolved_by') && str_contains($review,'resolved_at'),'MAIFIP decisions retain resolver identity and timestamp');
echo "Ticket lifecycle static checks: {$checks}; passed: ".($checks-count($failures))."; failed: ".count($failures)."\n";
foreach($failures as $failure)echo "FAIL: {$failure}\n";
exit($failures?1:0);
