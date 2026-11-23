<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$existing=$root.'/maintenance/endpoint-registry.json';
$existingData=is_file($existing)?json_decode((string)file_get_contents($existing),true):null;
if(($existingData['manifest_version']??0)>=2 && !in_array('--force-raw',$argv??[],true)){fwrite(STDERR,'Refusing to overwrite the reconciled endpoint registry. Use --force-raw only when intentionally rebuilding a raw discovery inventory. '.PHP_EOL);exit(2);}
$routes=[];
$excluded=['config.php','helpers.php','chat_unread_helper.php','dashboard_layout.php','app_version.php','media_upgrade.php'];
$public=['login.php','register.php'];
$write=['record_form.php','status_update.php','sheet_save.php','import_sheet.php','tickets.php','revision_ticket.php','delete_ticket_request.php','office_reassignment_ticket.php','ticket_create.php','ticket_edit.php','ticket_review.php','delete_request.php','delete_record.php','offline_sync.php','offline_resolve.php','users.php','user.php','account.php','offices.php','trash.php','fund.php','usertypes.php','monthly_report_settings.php','media_upgrade.php'];
foreach(['aics','maifip'] as $workspace){
  foreach(glob($root.'/'.$workspace.'/*.php')?:[] as $file){$name=basename($file);if(in_array($name,$excluded,true))continue;
    $isPublic=in_array($name,$public,true);$isWrite=in_array($name,$write,true);
    $routes[]=['path'=>$workspace.'/'.$name,'workspace'=>$workspace,'methods_to_verify'=>['GET','POST'],'login_required'=>!$isPublic,'capability'=>'workspace_endpoint_policy','csrf'=>'required_for_state_changing_requests','ownership_scope'=>$workspace==='aics'?'aics_office_adapter':'maifip_facility_adapter','idempotency'=>$isWrite?'required_for_retryable_create_or_batch_write':'not_applicable_or_read_only','review_status'=>'inventory_only_authenticated_http_test_pending'];
  }
}
$routes[]=['path'=>'portal/select-system.php','workspace'=>'portal','methods_to_verify'=>['GET','POST'],'login_required'=>true,'capability'=>'portal.access','csrf'=>'required_for_state_changing_requests','ownership_scope'=>'central_lpgma_identity','idempotency'=>'not_applicable','review_status'=>'inventory_only_authenticated_http_test_pending'];
$out=['manifest_version'=>1,'generated_at_utc'=>gmdate('c'),'policy'=>'Inventory contract only; methods_to_verify are candidate methods, not confirmed support. Entries are not runtime authorization enforcement. Endpoint-specific capability and method rules must be verified before enforcement.','routes'=>$routes];
file_put_contents($root.'/maintenance/endpoint-registry.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo 'Registered '.count($routes).' routes'.PHP_EOL;
