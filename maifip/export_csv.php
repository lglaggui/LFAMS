<?php
require_once __DIR__ . '/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','view_records') || !fams_current_type_allows('MAIFIP','export_records')){http_response_code(403);exit('Your user type does not have permission to export these records. Contact the Site Administrator if this access is required.');}
require_role(['admin','encoder','social_worker','manager']);

log_action($pdo, 'EXPORT_CSV', 'Exported financial assistance records to CSV.');

$activeFacility=active_data_facility($pdo); $st=$pdo->prepare("SELECT * FROM assistance_records WHERE facility=? ORDER BY date_submission DESC, id DESC"); $st->execute([$activeFacility]); $records=$st->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="financial_assistance_records_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, [
    'Control Number','Legacy No.','Date Submission','Patient First Name','Patient Middle Name','Patient Last Name','Name Extension',
    'Barangay','Municipality','Province','Client','Contact Number',
    'Bill Amount','Guarantee Amount','Remarks','Status','Release Date'
]);

foreach ($records as $row) {
    fputcsv($output, array_map('fams_csv_safe_cell', [
        $row['control_number'],$row['legacy_no'],$row['date_submission'],
        $row['patient_first_name'],
        $row['patient_middle_name'],
        $row['patient_last_name'],
        $row['patient_name_extension'],
        $row['barangay'],
        $row['municipality'],
        $row['province'],
        $row['guardian'],
        $row['contact_number'],
        $row['bill_amount'],
        $row['guarantee_amount'],
        $row['remarks'],
        $row['status'],
        $row['release_date'],
    ]));
}
fclose($output);
exit;
