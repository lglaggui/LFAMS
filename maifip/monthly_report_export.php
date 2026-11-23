<?php
require_once __DIR__ . '/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','view_reports')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
require_role(['admin','encoder','social_worker','manager']);
$activeFacility=active_data_facility($pdo);
$activeFacilityInfo=facility_info($activeFacility);
$year=(int)($_GET['year']??date('Y')); $month=(int)($_GET['month']??date('n'));
if($year<2000 || $year>2100 || $month<1 || $month>12){http_response_code(400);exit('Invalid reporting period.');}
$rows=maifip_monthly_released_records($pdo,$year,$month);
$fundReconciliation=maifip_monthly_fund_reconciliation($pdo,$year,$month);
$reportAmount=$fundReconciliation['disbursements'];
$reportClients=$fundReconciliation['clients'];
$detailAmount=array_sum(array_map(static fn($r)=>(float)$r['guarantee_amount'],$rows));
$detailClients=count($rows);
$detailMatches=(abs($detailAmount-$reportAmount)<0.01 && $detailClients===$reportClients);
log_action($pdo,'EXPORT_MONTHLY_CSV',"Exported {$year}-".sprintf('%02d',$month).' '.$activeFacilityInfo['short'].' monthly disbursement report to CSV.');
$monthNames=[1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.strtoupper($activeFacilityInfo['short']).'_monthly_disbursement_'.$year.'_'.strtolower($monthNames[$month]).'.csv"');
$out=fopen('php://output','w'); fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out,['NO','DATE OF SUBMISSION','BENEFICIARY NAME','ADDRESS','GUARANTEE AMOUNT','CLIENT','ENDORSED BY','APPROVED BY','CONTACT','STATUS','RELEASE DATE']);
foreach($rows as $i=>$r){$name=trim(($r['patient_first_name']??'').' '.($r['patient_middle_name']??'').' '.($r['patient_last_name']??'').' '.($r['patient_name_extension']??'')); $address=trim(implode(', ',array_filter([$r['barangay']??'',$r['municipality']??'',$r['province']??'']))); fputcsv($out,array_map('fams_csv_safe_cell',[$i+1,$r['date_submission'],$name,$address,$r['guarantee_amount'],$r['guardian']??'','—','—',$r['contact_number']??'',$r['status'],$r['release_date']??'']));}
// The fund summary is the same authoritative calculation used by the worksheet and print view.
fputcsv($out,[]);
fputcsv($out,['FUND BALANCE RECONCILIATION','AMOUNT']);
fputcsv($out,['Opening Balance',number_format($fundReconciliation['opening_balance'],2,'.','')]);
fputcsv($out,['Net Fund Downloads',number_format($fundReconciliation['fund_downloads'],2,'.','')]);
fputcsv($out,['Fund Adjustments',number_format($fundReconciliation['adjustments'],2,'.','')]);
fputcsv($out,['Less: Released Assistance',number_format($fundReconciliation['disbursements'],2,'.','')]);
fputcsv($out,['Ending Balance',number_format($fundReconciliation['ending_balance'],2,'.','')]);
fputcsv($out,['Disbursement Source',$fundReconciliation['disbursement_source']]);
fputcsv($out,['Detail vs Selected Report Total',$detailMatches?'MATCH':'DIFFERENCE']);
fputcsv($out,['Stored Monthly Summary Amount',$fundReconciliation['stored_summary_amount']===null?'':number_format((float)$fundReconciliation['stored_summary_amount'],2,'.','')]);
fputcsv($out,['Stored Monthly Summary Clients',$fundReconciliation['stored_summary_clients']===null?'':$fundReconciliation['stored_summary_clients']]);
fputcsv($out,['Stored Summary Comparison',$fundReconciliation['summary_discrepancy']?'DIFFERENCE - LIVE RELEASED RECORDS USED':($fundReconciliation['stored_summary_amount']===null?'NO STORED SUMMARY':'MATCH / SUMMARY FALLBACK')]);
fputcsv($out,['Detail Released Total',number_format($detailAmount,2,'.','')]);
fputcsv($out,['Detail Client Count',$detailClients]);
fputcsv($out,['Authoritative Client Count',$reportClients]);
fputcsv($out,[]);
fputcsv($out,['','','TOTAL RELEASED ASSISTANCE','',number_format($reportAmount,2,'.',''),$reportClients.' CLIENT(S)','','','','SUMMARY','']);
fclose($out); exit;
?>