<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_role(['admin','encoder']);
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required.']); exit; }
$input=json_decode(file_get_contents('php://input'),true);
if (!is_array($input)) { $input=$_POST; }
$token=(string)($input['csrf_token']??'');
if (!$token || !hash_equals((string)($_SESSION['csrf_token']??''), $token)) { http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Invalid or expired request token.']); exit; }
$yearRaw=$input['year']??null; $monthRaw=$input['month']??null; $amountRaw=$input['adjustment_amount']??null; $reason=trim((string)($input['reason']??''));
$amountText=is_string($amountRaw)||is_int($amountRaw)||is_float($amountRaw) ? trim((string)$amountRaw) : '';
if (!is_scalar($yearRaw) || !preg_match('/^\d{4}$/',(string)$yearRaw) || !is_scalar($monthRaw) || !preg_match('/^\d{1,2}$/',(string)$monthRaw)
    || !preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/',$amountText) || $reason==='' || strlen($reason)>500) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Enter a valid year, month, signed adjustment amount, and reason (up to 500 characters).']); exit;
}
$year=(int)$yearRaw; $month=(int)$monthRaw; $amount=(float)$amountText;
if ($year<2000 || $year>2100 || $month<1 || $month>12 || !is_finite($amount) || abs($amount)>9999999999999.99 || abs($amount)<0.005) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>'The adjustment is outside the permitted range or is zero.']); exit;
}
try {
    fams_shared_transaction_retry($pdo, function(PDO $db) use ($year,$month,$amount,$reason): void {
        add_monthly_report_adjustment($db,$year,$month,$amount,$reason,(int)($_SESSION['user']['id']??0));
        fams_audit_write_critical($db,'maifip','CREATE_FUND_ADJUSTMENT',"Recorded facility fund adjustment for {$year}-".sprintf('%02d',$month).' amount '.number_format($amount,2).'; reason: '.substr($reason,0,120));
    });
    echo json_encode(['ok'=>true,'adjustment_amount'=>$amount]);
} catch (Throwable $error) {
    error_log('MAIFIP monthly report settings update failed: '.$error->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>fams_shared_user_error($error,'Fund adjustment could not be saved. Please try again.')]);
}
