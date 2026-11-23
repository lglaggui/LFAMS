<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('AICS','view_records') || !fams_current_type_allows('AICS','export_records')){http_response_code(403);exit('Your user type does not have permission to export these records. Contact the Site Administrator if this access is required.');}
$csvUser=current_user()??[];$sql='SELECT r.*,o.name office_name FROM aics_assistance_records r LEFT JOIN aics_offices o ON o.id=r.office_id';
// SWAD masterlist visibility is centralized: every authorized SWAD user exports all offices.
// Office assignment continues to govern edit/status/delete actions, not read-only visibility.
$rows=$pdo->query($sql.' ORDER BY r.date_submission DESC,r.id DESC')->fetchAll();
log_action($pdo,'EXPORT_MASTERLIST','Exported the centralized masterlist to CSV.');
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="SWAD_Region_II_Masterlist_'.date('Ymd_His').'.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['CONTROL NO.','SOURCE NO.','DATE OF SUBMISSION','CLIENT FIRST NAME','CLIENT MIDDLE NAME','CLIENT LAST NAME','CLIENT EXT.','BARANGAY','MUNICIPALITY','PROVINCE','CONTACT NUMBER','BENEFICIARY FIRST NAME','BENEFICIARY MIDDLE NAME','BENEFICIARY LAST NAME','BENEFICIARY EXT.','ASSISTANCE TYPE','ORIGINAL TYPE','GUARANTEE AMOUNT','REMARKS','EMAIL','VALIDITY','OFFICE','STATUS','RELEASE DATE','MONTH OF RELEASE','CANCELLED']);
foreach($rows as $r)fputcsv($out,array_map('fams_csv_safe_cell',[$r['control_number'],$r['source_no'],$r['date_submission'],$r['client_first_name'],$r['client_middle_name'],$r['client_last_name'],$r['client_name_extension'],$r['barangay'],$r['municipality'],$r['province'],$r['contact_number'],$r['beneficiary_first_name'],$r['beneficiary_middle_name'],$r['beneficiary_last_name'],$r['beneficiary_name_extension'],$r['assistance_type'],$r['assistance_type_raw'],$r['guarantee_amount'],$r['remarks'],$r['email'],$r['validity_status'],$r['office_name'],$r['status'],$r['release_date'],$r['month_of_release'],$r['cancelled_flag']]));
fclose($out);exit;
