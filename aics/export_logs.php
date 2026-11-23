<?php
require_once __DIR__ . '/config.php';
require_login();
if (!fams_current_type_allows('AICS','view_action_logs')) { http_response_code(403); exit('Your user type does not have permission to export action logs.'); }
require_once dirname(__DIR__) . '/core/audit_runtime.php';
$export = fams_audit_export($pdo, 'aics', $_GET, 25000);
log_action($pdo, 'EXPORT_LOGS', 'Exported filtered aics action logs as CSV.');
$filename = 'AICS-SWAD-action-logs-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
$headers = ['Event ID','Date / Time','User','Role','Action','Description','Record ID','Client IP','Host','Browser / Device'];
fputcsv($out, $headers, ',', '"', '');
foreach ($export['rows'] as $row) {
    $values = [(string)($row['id'] ?? ''),(string)($row['created_at'] ?? ''),(string)($row['username'] ?? ''),(string)($row['role'] ?? ''),(string)($row['action'] ?? ''),(string)($row['description'] ?? ''),(string)($row['record_id'] ?? ''),(string)($row['ip_address'] ?? ''),(string)($row['client_hostname'] ?? ''),(string)($row['user_agent'] ?? '')];
    $values = array_map('fams_csv_safe_cell', $values);
    fputcsv($out, $values, ',', '"', '');
}
if (!empty($export['truncated'])) fputcsv($out, ['NOTICE', 'Export limited to '.$export['limit'].' rows. Narrow the filters to retrieve a smaller, complete result set.'], ',', '"', '');
fclose($out);
exit;
