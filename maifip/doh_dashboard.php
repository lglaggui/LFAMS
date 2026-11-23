<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();
$user = current_user() ?? [];
if (user_facility($user) !== 'LPGMA' || !fams_current_type_allows('MAIFIP', 'view_dashboard')) {
    http_response_code(403);
    exit('The DOH MAIFIP Region II Dashboard is restricted to authorized LPGMA users. If you believe you need access, contact the LPGMA Site Administrator.');
}
$canEdit = fams_current_type_allows('MAIFIP', 'manage_funds');
/** Build the workbook-shaped table from live MAIFIP facilities and ledgers.
 * The uploaded workbook supplies the visual structure only; financial values
 * and facility names come from the active FAMS database. */
function doh_build_live_template(PDO $pdo): array {
    $widths = [132,173,183,149,150,96,163,248];
    $rows = [
        ['SUMMARY OF DOH MAIFIP FUNDS','','','','','','',''],
        ['AND','','','','','','',''],
        ['DISBURSED FINANCIAL ASSISTANCE','','','','','','',''],
        [date('F d, Y'),'','','','','','',''],
        ['HEALTH FACILITY','','DOWNLOADED FUNDS','DOWNLOAD DATE','SOURCE','FUND DESTINATION / \nDISBURSEMENT','UTILIZED FUND','REMARKS'],
        ['','','','','','','','']
    ];
    $heights = [36,17,30,26,32,4];
    $merges = [
        ['r'=>0,'c'=>0,'rs'=>1,'cs'=>8], ['r'=>1,'c'=>0,'rs'=>1,'cs'=>8],
        ['r'=>2,'c'=>0,'rs'=>1,'cs'=>8], ['r'=>3,'c'=>0,'rs'=>1,'cs'=>8],
        ['r'=>4,'c'=>0,'rs'=>1,'cs'=>2]
    ];
    $facilities = facility_options();
    $grandDownloaded = 0.0; $grandUtilized = 0.0;
    $facilityCount = 0;
    foreach ($facilities as $key => $info) {
        $facility = strtoupper(trim((string)$key));
        if ($facility === '' || $facility === 'LPGMA') continue;
        $facilityCount++;
        $name = trim((string)($info['name'] ?? $facility));
        $downloads = [];
        try {
            $st = $pdo->prepare('SELECT batch_no, downloaded_amount, date_downloaded, fund_source FROM fund_downloads WHERE facility=? ORDER BY date_downloaded,id');
            $st->execute([$facility]); $downloads = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $downloads = []; }
        // Match the MAIFIP reporting rule: released record totals override a
        // stored monthly summary for that month; summary rows fill only months
        // that have no record-level released entries. Aggregate across years so
        // the fund balance compares with the full downloaded-fund ledger.
        $monthlyUtilization = [];
        try {
            $st = $pdo->prepare("SELECT year, month_no, amount FROM monthly_disbursement_summary WHERE UPPER(TRIM(facility))=UPPER(TRIM(?))");
            $st->execute([$facility]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $monthlyUtilization[(int)$m['year'].'-'.(int)$m['month_no']] = (float)$m['amount'];
            }
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT YEAR(release_date) AS y, MONTH(release_date) AS m, COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED' AND release_date IS NOT NULL AND release_date<>'' AND release_date<>'0000-00-00' GROUP BY YEAR(release_date), MONTH(release_date)");
            $st->execute([$facility]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $monthlyUtilization[(int)$m['y'].'-'.(int)$m['m']] = (float)$m['amount'];
            }
        } catch (Throwable $e) {}
        $utilized = array_sum($monthlyUtilization);
        $downloaded = 0.0;
        foreach ($downloads as $d) $downloaded += (float)($d['downloaded_amount'] ?? 0);
        $first = count($rows);
        if (!$downloads) {
            $rows[] = [$facility."\n".$name,'',0,'','', '', '', 'No fund downloads are recorded in the system.'];
            $heights[] = 34;
        } else {
            foreach ($downloads as $i => $d) {
                $date = trim((string)($d['date_downloaded'] ?? ''));
                $dateLabel = $date !== '' ? date('F d, Y', strtotime($date)) : '';
                $remarks = trim((string)($d['batch_no'] ?? '')) !== '' ? 'BATCH NO. '.trim((string)$d['batch_no']) : '';
                $rows[] = [$i === 0 ? $facility."\n".$name : '', '', (float)($d['downloaded_amount'] ?? 0), $dateLabel, (string)($d['fund_source'] ?? ''), '', '', $remarks];
                $heights[] = $i === 0 ? 34 : 22;
            }
        }
        $last = count($rows)-1;
        if ($last >= $first) $merges[] = ['r'=>$first,'c'=>0,'rs'=>$last-$first+1,'cs'=>2];
        $totalIndex = count($rows);
        $short = trim((string)($info['short'] ?? $facility));
        $rows[] = ['TOTAL','',$downloaded,'','','SYSTEM RELEASED ASSISTANCE',$utilized,'UTILIZED AMOUNT FROM RELEASED MAIFIP RECORDS'];
        $heights[] = 32;
        $merges[] = ['r'=>$totalIndex,'c'=>0,'rs'=>1,'cs'=>2];
        $rows[] = ['REMAINING '.$short.' FUNDS','','','','',$downloaded-$utilized,'',''];
        $heights[] = 22;
        $merges[] = ['r'=>$totalIndex+1,'c'=>0,'rs'=>1,'cs'=>2];
        $rows[] = ['','','','','','','','']; $heights[] = 4;
        $grandDownloaded += $downloaded; $grandUtilized += $utilized;
    }
    if ($facilityCount === 0) {
        $rows[] = ['No active MAIFIP health facilities are configured.','','','','','','','']; $heights[] = 28;
        $merges[] = ['r'=>count($rows)-1,'c'=>0,'rs'=>1,'cs'=>8];
    }
    $rows[] = ['TOTAL DOWNLOADED FUNDS','',$grandDownloaded,'','','','','']; $heights[] = 26;
    $merges[] = ['r'=>count($rows)-1,'c'=>0,'rs'=>1,'cs'=>2];
    $rows[] = ['TOTAL UTILIZED FUNDS','','','','',$grandUtilized,'','']; $heights[] = 26;
    $merges[] = ['r'=>count($rows)-1,'c'=>0,'rs'=>1,'cs'=>2];
    $rows[] = ['TOTAL REMAINING MAIFIP FUNDS','','','','',$grandDownloaded-$grandUtilized,'','']; $heights[] = 30;
    $merges[] = ['r'=>count($rows)-1,'c'=>0,'rs'=>1,'cs'=>2];
    return ['sheet'=>'SYSTEM-LIVE','source'=>'MAIFIP facility records, fund downloads, and released assistance records','layoutVersion'=>2,'rows'=>$rows,'merges'=>$merges,'widths'=>$widths,'heights'=>$heights,'styles'=>[]];
}
$template = doh_build_live_template($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS doh_maifip_dashboard (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    sheet_json LONGTEXT NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$seedJson = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$seed = $pdo->prepare('INSERT IGNORE INTO doh_maifip_dashboard (id, sheet_json, updated_by) VALUES (1, ?, ?)');
$seed->execute([$seedJson, (int)($user['id'] ?? 0) ?: null]);
// Upgrade the previously hard-coded workbook snapshot once to live facility data.
$existingSt = $pdo->query('SELECT sheet_json FROM doh_maifip_dashboard WHERE id=1');
$existingData = json_decode((string)$existingSt->fetchColumn(), true);
if (!is_array($existingData) || (int)($existingData['layoutVersion'] ?? 0) < 2) {
    $upgradeJson = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE doh_maifip_dashboard SET sheet_json=?, updated_by=? WHERE id=1')->execute([$upgradeJson, (int)($user['id'] ?? 0) ?: null]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doh_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Your security token expired. Refresh the page and try again.']); exit;
    }
    if (!$canEdit) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Your user type has view-only access to this dashboard.']); exit;
    }
    $action = (string)$_POST['doh_action'];
    if ($action === 'reset') {
        $data = $template;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $pdo->prepare('UPDATE doh_maifip_dashboard SET sheet_json=?, updated_by=? WHERE id=1');
        $st->execute([$json, (int)($user['id'] ?? 0) ?: null]);
        log_action($pdo, 'RESET_DOH_MAIFIP_DASHBOARD', 'Reset the DOH MAIFIP Region II dashboard table to the supplied workbook template.');
        echo json_encode(['ok'=>true,'data'=>$data,'message'=>'Dashboard table reset to the original workbook template.']); exit;
    }
    if ($action === 'save') {
        $raw = (string)($_POST['sheet_json'] ?? '');
        if ($raw === '' || strlen($raw) > 300000) { http_response_code(413); echo json_encode(['ok'=>false,'error'=>'The submitted table is empty or too large.']); exit; }
        $data = json_decode($raw, true);
        $valid = is_array($data) && isset($data['rows'], $data['merges'], $data['widths'], $data['heights'])
            && is_array($data['rows']) && count($data['rows']) >= 1 && count($data['rows']) <= 100
            && is_array($data['merges']) && is_array($data['widths']) && count($data['widths']) === 8
            && is_array($data['heights']) && count($data['heights']) === count($data['rows']);
        if ($valid) {
            foreach ($data['rows'] as $row) {
                if (!is_array($row) || count($row) !== 8) { $valid = false; break; }
                foreach ($row as $cell) if (!is_scalar($cell) || strlen((string)$cell) > 5000) { $valid = false; break 2; }
            }
        }
        if ($valid) {
            foreach ($data['widths'] as $w) if (!is_numeric($w) || (float)$w < 36 || (float)$w > 900) { $valid=false; break; }
            foreach ($data['heights'] as $h) if (!is_numeric($h) || (float)$h < 4 || (float)$h > 500) { $valid=false; break; }
            if (isset($data['styles'])) {
                if (!is_array($data['styles']) || count($data['styles']) > 800) $valid=false;
                else foreach ($data['styles'] as $cellKey=>$style) {
                    if (!preg_match('/^(\d{1,3}):(\d)$/',(string)$cellKey, $styleKeyMatch) || (int)$styleKeyMatch[1] >= count($data['rows']) || (int)$styleKeyMatch[2] >= 8 || !is_array($style)) { $valid=false; break; }
                    foreach (['color','backgroundColor'] as $colorKey) if (isset($style[$colorKey]) && !preg_match('/^#[0-9a-fA-F]{6}$/',(string)$style[$colorKey])) { $valid=false; break 2; }
                    if (isset($style['fontSize']) && (!is_numeric($style['fontSize']) || (float)$style['fontSize'] < 7 || (float)$style['fontSize'] > 36)) { $valid=false; break; }
                    if (isset($style['fontFamily']) && !in_array($style['fontFamily'], ['Arial','Aptos','Calibri','Times New Roman','Verdana','Tahoma'], true)) { $valid=false; break; }
                    foreach (['bold','italic','underline'] as $flag) if (isset($style[$flag]) && !is_bool($style[$flag])) { $valid=false; break; }
                }
            }
        }
        if (!$valid) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'The table structure, geometry, or cell formatting is invalid. Your changes were not saved.']); exit; }
        $data['layoutVersion'] = 2;
        // Keep the sheet structure anchored to the workbook's eight columns and allow only bounded merges.
        foreach ($data['merges'] as $m) {
            if (!is_array($m) || !isset($m['r'],$m['c'],$m['rs'],$m['cs']) || (int)$m['r'] < 0 || (int)$m['c'] < 0 || (int)$m['r'] >= count($data['rows']) || (int)$m['c'] >= 8 || (int)$m['rs'] < 1 || (int)$m['cs'] < 1 || (int)$m['r'] + (int)$m['rs'] > count($data['rows']) || (int)$m['c'] + (int)$m['cs'] > 8) { $valid = false; break; }
        }
        if (!$valid) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'The table contains an invalid merged-cell range.']); exit; }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $pdo->prepare('UPDATE doh_maifip_dashboard SET sheet_json=?, updated_by=? WHERE id=1');
        $st->execute([$json, (int)($user['id'] ?? 0) ?: null]);
        log_action($pdo, 'UPDATE_DOH_MAIFIP_DASHBOARD', 'Saved edits to the DOH MAIFIP Region II dashboard table.');
        echo json_encode(['ok'=>true,'message'=>'All changes saved.']); exit;
    }
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unknown dashboard action.']); exit;
}
$st = $pdo->query('SELECT sheet_json, updated_at FROM doh_maifip_dashboard WHERE id=1');
$saved = $st->fetch();
$sheet = $saved ? json_decode((string)$saved['sheet_json'], true) : $template;
if (!is_array($sheet) || empty($sheet['rows'])) $sheet = $template;
$updatedAt = $saved['updated_at'] ?? null;
$pageTitle = 'DOH MAIFIP Region II Dashboard';
require __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="assets/doh-dashboard.css?v=<?=e(APP_VERSION)?>">
<div class="doh-dashboard-page" id="dohDashboard" data-can-edit="<?=$canEdit?'1':'0'?>" data-csrf="<?=e(csrf_token())?>">
  <section class="doh-dashboard-hero">
    <div class="doh-hero-brand"><img src="../core/branding/doh-logo.jpg" alt="Department of Health, Republic of the Philippines"><div><span class="section-kicker">DOH REGION II • CAGAYAN VALLEY</span><h1>DOH MAIFIP REGION II DASHBOARD</h1><p>Summary of DOH MAIFIP funds and disbursed financial assistance</p></div></div>
    <div class="doh-dashboard-meta"><a class="doh-back-dashboard" href="dashboard.php"><span aria-hidden="true">←</span> Back to Dashboard</a><span class="doh-live-chip"><i></i> Central fund summary</span><span class="doh-save-status" id="dohSaveStatus" role="status"><?= $updatedAt ? 'Last saved '.e((string)$updatedAt) : 'Workbook template loaded' ?></span></div>
  </section>
  <section class="panel doh-sheet-panel">
    <div class="doh-sheet-toolbar"><div class="doh-sheet-title"><span class="section-kicker">EDITABLE WORKSHEET</span><h2>Summary of DOH MAIFIP Funds</h2><p>Workbook-based layout · Facility names, fund downloads, and released-assistance totals are populated from the current FAMS system.</p></div>
      <div class="doh-sheet-actions">
        <?php if($canEdit): ?><button class="doh-tool-button doh-add-row" id="dohAddRow" type="button"><span aria-hidden="true">+</span> Add Row</button><button class="doh-tool-button doh-reset" id="dohReset" type="button"><span aria-hidden="true">↺</span> Reset</button><?php endif; ?>
        <button class="doh-tool-button doh-export" id="dohExport" type="button"><span aria-hidden="true">⇩</span> Export to Excel</button>
      </div>
    </div>
    <?php if(!$canEdit): ?><div class="doh-readonly-notice"><strong>View-only access.</strong> Your user type may view and export this summary but cannot change its contents.</div><?php endif; ?>
    <div class="doh-format-toolbar" id="dohFormatToolbar" aria-label="Cell formatting tools">
      <?php if($canEdit): ?>
      <label>Font <select id="dohFontFamily"><option>Arial</option><option>Aptos</option><option>Calibri</option><option>Times New Roman</option><option>Verdana</option><option>Tahoma</option></select></label>
      <label>Size <select id="dohFontSize"><?php foreach([8,9,10,11,12,14,16,18,20,24,28,32,36] as $fs): ?><option value="<?=$fs?>" <?=$fs===11?'selected':''?>><?=$fs?></option><?php endforeach; ?></select></label>
      <button type="button" class="doh-format-button" id="dohBold" aria-label="Bold" title="Bold"><strong>B</strong></button><button type="button" class="doh-format-button" id="dohItalic" aria-label="Italic" title="Italic"><em>I</em></button><button type="button" class="doh-format-button" id="dohUnderline" aria-label="Underline" title="Underline"><u>U</u></button>
      <label class="doh-color-tool">Text <input type="color" id="dohTextColor" value="#111111" aria-label="Text color"></label><label class="doh-color-tool">Cell <input type="color" id="dohCellColor" value="#ffffff" aria-label="Cell background color"></label>
      <button type="button" class="doh-tool-button doh-format-apply" id="dohApplyFormat">Apply to selected cell(s)</button>
      <?php endif; ?>
      <span class="doh-resize-hint">Drag column and row edges to resize.</span>
    </div>
    <div class="doh-sheet-scroll" role="region" aria-label="DOH funds worksheet; horizontally scrollable" tabindex="0"><div class="doh-column-ruler" id="dohColumnRuler" aria-label="Resize columns"></div><table class="doh-excel-table" id="dohExcelTable" aria-label="Editable summary of DOH MAIFIP funds"><tbody></tbody></table></div>
    <div class="doh-sheet-footnote"><span>Live values: active MAIFIP facilities, fund downloads, and released assistance records</span><span id="dohRowCount"></span></div>
  </section>
</div>
<script>
window.DOH_SHEET_TEMPLATE = <?=json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
window.DOH_SHEET_STATE = <?=json_encode($sheet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
</script>
<script src="assets/doh-dashboard.js?v=<?=e(APP_VERSION)?>" defer></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
