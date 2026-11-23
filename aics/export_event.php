<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required.']); exit; }
if (!fams_current_type_allows('AICS','view_records') || !fams_current_type_allows('AICS','export_records')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Export permission is required.']); exit; }
verify_csrf();
$format=strtolower(trim((string)($_POST['format']??'')));
if (!in_array($format,['xls','pdf','print'],true)) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Unsupported export format.']); exit; }
$rowCount=max(0,min(100000,(int)($_POST['row_count']??0)));
log_action($pdo,'EXPORT_SHEET_REQUEST','Requested AICS editable masterlist '.$format.' export for '.$rowCount.' displayed row(s).');
echo json_encode(['ok'=>true]);
