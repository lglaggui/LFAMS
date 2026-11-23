<?php
require_once __DIR__ . '/config.php';
require_login();
if(!fams_current_type_allows('MAIFIP','view_dashboard')){header('Location: records.php');exit;}
// The analytics Dashboard is reserved for LPGMA users. All other facility users start on the FAMS masterlist.
if (user_facility(current_user() ?: []) !== 'LPGMA') { redirect('records.php'); }
require_role(['admin', 'encoder', 'manager']);

$pageTitle = 'Dashboard';
$activeFacility = active_data_facility($pdo);
$activeFacilityInfo = facility_info($activeFacility);
$isFragment = isset($_GET['fragment']) && $_GET['fragment'] === '1';
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$minYear = $currentYear;
$st=$pdo->prepare("SELECT DISTINCT YEAR(date_submission) AS y FROM assistance_records WHERE facility=? AND date_submission IS NOT NULL ORDER BY y DESC"); $st->execute([$activeFacility]); $yearRows=$st->fetchAll();
foreach ($yearRows as $yr) { $y=(int)$yr['y']; if($y>0) $minYear=min($minYear,$y); }
$st=$pdo->prepare("SELECT DISTINCT YEAR(release_date) AS y FROM assistance_records WHERE facility=? AND release_date IS NOT NULL ORDER BY y DESC"); $st->execute([$activeFacility]); $releaseYears=$st->fetchAll();
foreach ($releaseYears as $yr) { $y=(int)$yr['y']; if($y>0) $minYear=min($minYear,$y); }
$historyYearsSt = $pdo->prepare("SELECT DISTINCT YEAR(sh.changed_at) AS y FROM status_history sh JOIN assistance_records ar ON ar.id=sh.record_id WHERE ar.facility=? AND sh.changed_at IS NOT NULL ORDER BY y DESC"); $historyYearsSt->execute([$activeFacility]); $historyYears=$historyYearsSt->fetchAll();
foreach ($historyYears as $yr) { $y=(int)$yr['y']; if($y>0) $minYear=min($minYear,$y); }

$selectedYear=(int)($_GET['year']??$currentYear);
if($selectedYear<$minYear || $selectedYear>$currentYear) $selectedYear=$currentYear;
$selectedMonth=(int)($_GET['month']??$currentMonth);
if($selectedMonth<1 || $selectedMonth>12) $selectedMonth=$currentMonth;

$today=date('Y-m-d');
$isCurrentMonth=($selectedYear===$currentYear && $selectedMonth===$currentMonth);
$monthMetricLabel=$isCurrentMonth?'Month-to-Date':'Selected Month Total';
$yearMetricLabel=$selectedYear===$currentYear?'Year-to-Date':'Year Total';
$monthStart=sprintf('%04d-%02d-01',$selectedYear,$selectedMonth);
$monthEnd=date('Y-m-t',strtotime($monthStart));
$yearStart=$selectedYear.'-01-01';
$yearEnd=$selectedYear.'-12-31';

$st=$pdo->prepare("SELECT COUNT(*) FROM assistance_records WHERE facility=?"); $st->execute([$activeFacility]); $total=(int)$st->fetchColumn();
$st=$pdo->prepare("SELECT COUNT(*) FROM assistance_records WHERE facility=? AND status='WAITING'"); $st->execute([$activeFacility]); $waiting=(int)$st->fetchColumn();

$stmt=$pdo->prepare("SELECT COUNT(*),COALESCE(SUM(guarantee_amount),0) FROM assistance_records WHERE facility=? AND status='RELEASED' AND release_date=?");
$stmt->execute([$activeFacility,$today]); [$todayClients,$todayAmount]=array_values($stmt->fetch(PDO::FETCH_NUM));
$todayClients=(int)$todayClients; $todayAmount=(float)$todayAmount;

// Reporting totals merge the supplied monthly summary with live record-level releases
// only for months that do not yet have a monthly summary row.
$throughMonth = $selectedYear === $currentYear ? $currentMonth : 12;
$report = reporting_disbursement_summary($pdo, $selectedYear, $throughMonth);
$reportMonthly = $report['monthly'];
$releasedYearClients=(int)$report['clients'];
$releasedYearAmount=(float)$report['amount'];
$monthClients=(int)$reportMonthly[$selectedMonth]['clients'];
$monthAmount=(float)$reportMonthly[$selectedMonth]['amount'];

$stmt=$pdo->prepare("SELECT COUNT(*),COALESCE(SUM(guarantee_amount),0) FROM assistance_records WHERE facility=? AND status='CANCELLED' AND id IN (SELECT DISTINCT record_id FROM status_history WHERE new_status='CANCELLED' AND changed_at BETWEEN ? AND ?)");
$stmt->execute([$activeFacility,$yearStart.' 00:00:00',$yearEnd.' 23:59:59']); [$cancelledYearClients,$cancelledYearAmount]=array_values($stmt->fetch(PDO::FETCH_NUM));
$cancelledYearClients=(int)$cancelledYearClients; $cancelledYearAmount=(float)$cancelledYearAmount;

$totalFinalized=$releasedYearClients+$cancelledYearClients;
$cancellationRate=$totalFinalized>0 ? ($cancelledYearClients/$totalFinalized)*100 : 0;
$avgDisbursement=$releasedYearClients>0 ? $releasedYearAmount/$releasedYearClients : 0;

$stmt=$pdo->prepare("SELECT DAY(release_date) AS day_no, COUNT(*) AS clients, COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE facility=? AND status='RELEASED' AND YEAR(release_date)=? AND MONTH(release_date)=? GROUP BY DAY(release_date) ORDER BY day_no");
$stmt->execute([$activeFacility,$selectedYear,$selectedMonth]);
$dailyRows=$stmt->fetchAll(); $daily=[];
foreach($dailyRows as $r) $daily[(int)$r['day_no']]=['clients'=>(int)$r['clients'],'amount'=>(float)$r['amount']];
$daysInMonth=cal_days_in_month(CAL_GREGORIAN,$selectedMonth,$selectedYear);
$dailyMax=0.0; $dailyMonthAmount=0.0; $dailyMonthClients=0; foreach($daily as $d){ $dailyMax=max($dailyMax,$d['amount']); $dailyMonthAmount += $d['amount']; $dailyMonthClients += $d['clients']; }
$dailySummaryAmount=(float)($reportMonthly[$selectedMonth]['amount']??0); $dailySummaryClients=(int)($reportMonthly[$selectedMonth]['clients']??0); $dailyVariance=$dailySummaryAmount-$dailyMonthAmount; $dailyCountVariance=$dailySummaryClients-$dailyMonthClients; $dailyIsReconciled=abs($dailyVariance)<0.01 && $dailyCountVariance===0;

// Use the same reconciled live monthly figures everywhere on the dashboard.
// This keeps the numerical table, bar chart, KPI cards, and totals synchronized
// after record/status changes.
$monthly=$reportMonthly;
$monthlyMax=max(1.0,...array_column($monthly,'amount'));

// Historical year summary. Released uses release_date. Cancelled uses the recorded cancellation event date.
$summaryYears=range($minYear,$currentYear); $yearly=[];
foreach($summaryYears as $y){
    // Keep the yearly released figures on the same reconciled reporting source used
    // by the monthly dashboard. This prevents the yearly table from disagreeing
    // with the monthly summary when a stored historical monthly figure is used.
    $through = ($y === $currentYear) ? $currentMonth : 12;
    $yrReport = reporting_disbursement_summary($pdo, $y, $through);
    $rc=(int)$yrReport['clients']; $ra=(float)$yrReport['amount'];
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT sh.record_id),COALESCE(SUM(ar.guarantee_amount),0) FROM status_history sh JOIN assistance_records ar ON ar.id=sh.record_id WHERE sh.new_status='CANCELLED' AND ar.facility=? AND YEAR(sh.changed_at)=?");
    $stmt->execute([$activeFacility,$y]); [$cc,$ca]=array_values($stmt->fetch(PDO::FETCH_NUM));
    $yearly[$y]=['released_clients'=>$rc,'released_amount'=>$ra,'cancelled_clients'=>(int)$cc,'cancelled_amount'=>(float)$ca];
}

// Top municipalities for the selected reporting year.
$stmt=$pdo->prepare("SELECT municipality,COUNT(*) AS clients,COALESCE(SUM(guarantee_amount),0) AS amount FROM assistance_records WHERE facility=? AND status='RELEASED' AND release_date BETWEEN ? AND ? GROUP BY municipality ORDER BY amount DESC,clients DESC LIMIT 3");
$stmt->execute([$activeFacility,$yearStart,$yearEnd]); $topMunicipalities=$stmt->fetchAll();

// Revision ticket monitoring.
$ticketCounts=['OPEN'=>0,'RESOLVED'=>0,'REJECTED'=>0];
$ticketSt=$pdo->prepare("SELECT t.status,COUNT(*) AS total FROM revision_tickets t JOIN assistance_records a ON a.id=t.record_id WHERE a.facility=? GROUP BY t.status"); $ticketSt->execute([$activeFacility]); foreach($ticketSt->fetchAll() as $r) $ticketCounts[$r['status']]=(int)$r['total'];

// Recent activity, with a small safety limit for dashboard performance.
$recentActivity=$pdo->query("SELECT id,username,role,action,description,record_id,created_at FROM action_logs ORDER BY created_at DESC,id DESC LIMIT 5")->fetchAll();

$st=$pdo->prepare("SELECT COALESCE(SUM(bill_amount),0) FROM assistance_records WHERE facility=?"); $st->execute([$activeFacility]); $totalBill=(float)$st->fetchColumn();
$st=$pdo->prepare("SELECT COALESCE(SUM(guarantee_amount),0) FROM assistance_records WHERE facility=? AND status='RELEASED'"); $st->execute([$activeFacility]); $totalGuaranteed=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT id,control_number,patient_first_name,patient_last_name,municipality,guarantee_amount,status,date_submission FROM assistance_records WHERE facility=? ORDER BY created_at DESC LIMIT 5"); $st->execute([$activeFacility]); $recent=$st->fetchAll();
$monthNames=[1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$gaugeLabel=static function(float $value): string { if ($value >= 1000000) return '₱'.number_format($value/1000000,1).'M'; if ($value >= 1000) return '₱'.number_format($value/1000,0).'k'; return '₱'.number_format($value,0); };

// The monthly reporting data is dynamic and reconciles with record-level releases.
$manualMonthly=$reportMonthly;
$manualYearAmount=$releasedYearAmount;
$manualYearClients=$releasedYearClients;

$st=$pdo->prepare("SELECT id,downloaded_amount,batch_no,date_downloaded,fund_source FROM fund_downloads WHERE facility=? ORDER BY date_downloaded,id"); $st->execute([$activeFacility]); $fundDownloads=$st->fetchAll();
$fundPosition=maifip_fund_ledger_position($pdo); $totalAllocated=(float)$fundPosition['available'];
$totalUtilized=(float)$fundPosition['disbursed'];
$remainingFund=(float)$fundPosition['remaining'];

$yearOptions=range($currentYear,$minYear,-1);
if (!$isFragment) require __DIR__ . '/partials/header.php';
?>
<?php if ($isFragment): ?><div id="pageView"><div class="dashboard-page-fragment"><?php endif; ?>
<div class="dashboard-layout-grid fams-dashboard" id="dashboardLayout" data-fams-dashboard="1"><section class="dashboard-layout-item span-12" data-dashboard-item="hero" data-title="Hero Banner">
<section class="hero-banner maifip-dashboard-hero">
    <div class="maifip-hero-copy">
        <span class="eyebrow">SYSTEM OVERVIEW</span>
        <h2>FAMS Dashboard</h2>
        <p>Monitor assistance applications, daily disbursements, cancellations, revisions, and yearly financial performance.</p>
    </div>
    <div class="maifip-hero-actions" aria-label="Dashboard actions">
        <?php if (in_array(($user['role'] ?? ''), ['admin','encoder','social_worker'], true)): ?>
        <a class="hero-action" href="records.php?open_add=1">+ Add Financial Assistance Record</a>
        <?php elseif (($user['role'] ?? '') === 'manager'): ?>
        <span class="hero-action hero-action-disabled" aria-label="Managers have read-only access">View-only Manager Access</span>
        <?php endif; ?>
        <?php if (function_exists('user_facility') && user_facility($user ?? []) === 'LPGMA'): ?>
        <a class="hero-action hero-action-secondary doh-fund-dashboard-action" href="doh_dashboard.php"><span aria-hidden="true">▦</span> DOH MAIFIP R2 Fund Dashboard <span aria-hidden="true">↗</span></a>
        <?php endif; ?>
    </div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="reporting-filter" data-title="Reporting Period">
<section class="dashboard-filter panel reporting-period-panel" aria-label="Dashboard reporting period">
    <div class="dashboard-inner-editor-grid reporting-editor-grid" data-editor-container="reporting-inner">
    <div class="dashboard-editor-division span-6" data-editor-item="reporting-controls" data-title="Reporting Controls"><form method="get" class="dashboard-filter-form" data-dashboard-period-form>
        <div><label for="reportingYear">Reporting Year</label><select name="year" id="reportingYear" aria-label="Reporting year"><?php foreach($yearOptions as $y): ?><option value="<?=$y?>" <?=$selectedYear===$y?'selected':''?>><?=$y?></option><?php endforeach; ?></select></div>
        <div><label for="reportingMonth">Reporting Month</label><select name="month" id="reportingMonth" aria-label="Reporting month"><?php foreach($monthNames as $m=>$name): ?><option value="<?=$m?>" <?=$selectedMonth===$m?'selected':''?>><?=e($name)?></option><?php endforeach; ?></select></div>
    </form></div>
    <div class="dashboard-editor-division span-6" data-editor-item="reporting-status" data-title="Reporting Period Status"><div class="dashboard-period"><div class="dashboard-period-heading"><div class="dashboard-period-title"><span class="dashboard-period-kicker">SELECTED REPORTING PERIOD</span><strong><?=e($monthNames[$selectedMonth])?> / <?=$selectedYear?></strong></div><div class="dashboard-period-actions"><a class="dashboard-period-report-icon" target="_blank" rel="noopener" href="monthly_report.php?year=<?= (int)$selectedYear ?>&amp;month=<?= (int)$selectedMonth ?>" aria-label="Display Selected Period Report" title="Display Selected Period Report"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 3.75h9l5 5v11.5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/><path d="M14 3.75v5h5M8 16.5h2.5l1.5-3 2 4 1.2-2H17"/></svg><span class="sr-only">Display Selected Period Report</span></a></div></div><small><?= $dailyIsReconciled ? 'Daily records reconciled with monthly summary.' : 'Daily detail differs from monthly summary by ₱'.number_format(abs($dailyVariance),2).' / '.number_format(abs($dailyCountVariance)).' client(s).' ?></small></div>
    </div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="stats" data-title="Key Statistics">
<section class="stats-grid dashboard-stats fams-kpi-grid dashboard-inner-editor-grid" data-editor-container="stats" aria-label="Key financial and record indicators">
    <div class="dashboard-editor-division span-3" data-editor-item="stat-total" data-title="All Records"><div class="stat-card fams-kpi-card"><span>Total Records</span><strong><?=number_format($total)?></strong><small>All assistance records across this workspace</small></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="stat-waiting" data-title="Waiting"><div class="stat-card fams-kpi-card fams-kpi-waiting"><span>Waiting</span><strong><?=number_format($waiting)?></strong><small>Pending assistance records · all time</small></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="stat-released" data-title="Released"><div class="stat-card fams-kpi-card fams-kpi-released"><span>Released · <?=$selectedYear?></span><strong><?=number_format($releasedYearClients)?></strong><small>₱<?=number_format($releasedYearAmount,2)?> disbursed in selected year</small></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="stat-cancelled" data-title="Cancelled"><div class="stat-card fams-kpi-card fams-kpi-cancelled"><span>Cancelled · <?=$selectedYear?></span><strong><?=number_format($cancelledYearClients)?></strong><small>₱<?=number_format($cancelledYearAmount,2)?> cancelled amount in selected year</small></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="stat-today" data-title="Today's Disbursement"><div class="stat-card fams-kpi-card today-disbursement-card"><span>Today's Disbursement</span><strong>₱<?=number_format($todayAmount,2)?></strong><small><?=number_format($todayClients)?> released client(s) today</small></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="stat-month" data-title="Monthly Disbursement"><div class="stat-card fams-kpi-card"><span><?=e($monthMetricLabel)?></span><strong>₱<?=number_format($monthAmount,2)?></strong><small><?=number_format($monthClients)?> released client(s) in <?=$monthNames[$selectedMonth]?></small></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="stat-year" data-title="Yearly Disbursement"><div class="stat-card fams-kpi-card"><span><?=e($yearMetricLabel)?></span><strong>₱<?=number_format($releasedYearAmount,2)?></strong><small><?=number_format($releasedYearClients)?> released client(s) in <?=$selectedYear?></small></div></div>
</section>

</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="fund-breakdown" data-title="Fund Breakdown">
<div class="dashboard-fund-grid">
    <div class="panel report-card fund-breakdown-card">
        <div class="panel-heading"><div><span class="section-kicker">TOTAL FUND ALLOTMENT</span><h3>Fund Breakdown</h3></div><span class="report-total">₱<?=number_format($totalAllocated,2)?></span></div>
        <div class="dashboard-inner-editor-grid fund-editor-grid" data-editor-container="fund-inner">
        <div class="dashboard-editor-division span-6" data-editor-item="fund-kpis" data-title="Fund KPIs"><div class="fund-kpi-grid">
            <div class="fund-kpi-primary"><span>Total Allocated Funds</span><strong>₱<?=number_format($totalAllocated,2)?></strong></div>
            <div><span>Total Utilized Fund</span><strong>₱<?=number_format($totalUtilized,2)?></strong><small>Through <?=e(date('M d, Y'))?></small></div>
            <div class="fund-kpi-balance"><span>Remaining Fund</span><strong>₱<?=number_format($remainingFund,2)?></strong><small>As of <?=date('M d, Y')?></small></div>
        </div>
        <div class="fund-utilization-strip" aria-label="Fund utilization progress">
            <div class="fund-utilization-meta"><span>Fund utilization</span><strong><?= $totalAllocated>0 ? number_format(($totalUtilized/$totalAllocated)*100,1) : "0.0" ?>%</strong></div>
            <div class="fund-utilization-track"><span style="width:<?= $totalAllocated>0 ? min(100,($totalUtilized/$totalAllocated)*100) : 0 ?>%"></span></div>
            <div class="fund-utilization-foot"><span>Utilized</span><span>Remaining</span></div>
        </div>
        </div>
        <div class="dashboard-editor-division span-6" data-editor-item="fund-downloads" data-title="Downloaded Fund"><div class="fund-download-title">DOWNLOADED FUND</div>
        <div class="fund-download-table">
            <div class="fund-download-head"><span>BATCH NO.</span><span>DOWNLOADED FUND</span><span>DATE DOWNLOADED</span><span>FUND SOURCE</span></div>
            <?php foreach($fundDownloads as $f): ?><div class="fund-download-row"><strong><?=e($f['batch_no'])?></strong><span>₱<?=number_format((float)$f['downloaded_amount'],2)?></span><span><?=e(date('F d, Y',strtotime($f['date_downloaded'])))?></span><span><?=e($f['fund_source'])?></span></div><?php endforeach; ?>
        </div>
        <div class="fund-formula"><span>Allocated</span><b>−</b><span>Utilized</span><b>=</b><strong>Remaining</strong></div></div>
    </div>
</div>
</div>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="monthly-report" data-title="<?=e($activeFacilityInfo['short'])?> Monthly Disbursement">
<section class="panel report-card monthly-report-card monthly-report-feature">
    <div class="panel-heading monthly-report-heading"><div><span class="section-kicker"><?=e(strtoupper($activeFacilityInfo['short']))?> MONTHLY DISBURSEMENT</span><h3><?=$selectedYear?> Monthly Summary</h3></div><div class="monthly-report-actions"><span class="report-total">₱<?=number_format($manualYearAmount,2)?></span><a class="secondary-btn compact-btn" target="_blank" rel="noopener" href="monthly_report.php?year=<?=$currentYear?>&month=<?=$currentMonth?>">Display Current Month Report</a></div></div>
    <div class="monthly-report-layout dashboard-inner-editor-grid" data-editor-container="monthly-report-inner">
        <div class="monthly-report-table dashboard-editor-division span-4" data-editor-item="monthly-table" data-title="Monthly Numerical Table">
            <div class="monthly-report-head"><span>MONTH</span><span>AMOUNT</span><span>NO. OF PATIENTS</span></div>
            <?php foreach($monthNames as $m=>$name): $v=$manualMonthly[$m]; $hasRecord=$v['amount']>0; ?>
            <div class="monthly-report-row <?=($m===$selectedMonth?'is-selected':'')?>"><span><?=e($name)?></span><strong><?=$hasRecord?'₱'.number_format($v['amount'],2):'NO RECORDS YET'?></strong><span><?=number_format($v['clients'])?></span></div>
            <?php endforeach; ?>
            <div class="monthly-report-row monthly-report-total"><strong>TOTAL</strong><strong>₱<?=number_format($manualYearAmount,2)?></strong><strong><?=number_format($manualYearClients)?></strong></div>
        </div>
        <div class="monthly-numeric-chart dashboard-editor-division span-8" data-editor-item="monthly-chart" data-title="Monthly Line Chart">
            <div class="mini-chart-heading"><span class="section-kicker">TREND CHART</span><strong>Monthly Disbursement Trend</strong></div>
            <?php $manualMonthlyMax=max(1.0,...array_column($manualMonthly,'amount')); $plotW=720; $plotH=190; $plotLeft=18; $step=$plotW/11; $points=[]; foreach($monthNames as $idx=>$mName){$amount=(float)$manualMonthly[$idx]['amount'];$x=$plotLeft+(($idx-1)*$step);$y=10+($plotH-(($amount/$manualMonthlyMax)*($plotH-20)));$points[]=['x'=>$x,'y'=>$y,'amount'=>$amount,'clients'=>(int)$manualMonthly[$idx]['clients'],'name'=>$mName];} $linePoints=implode(' ',array_map(fn($p)=>$p['x'].','.$p['y'],$points)); $areaPoints=$linePoints.' '.$points[count($points)-1]['x'].','.(10+$plotH).' '.$points[0]['x'].','.(10+$plotH); ?>
            <div class="line-chart monthly-line-chart" aria-label="Monthly disbursement line chart">
                <div class="line-gauge"><span><?=$gaugeLabel($manualMonthlyMax)?></span><span><?=$gaugeLabel($manualMonthlyMax*.75)?></span><span><?=$gaugeLabel($manualMonthlyMax*.50)?></span><span><?=$gaugeLabel($manualMonthlyMax*.25)?></span><span>₱0</span></div>
                <svg viewBox="0 0 760 225" role="img" aria-label="Monthly disbursement amounts for <?=$selectedYear?>">
                    <defs><linearGradient id="monthlyLineArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4c84b4" stop-opacity=".32"/><stop offset="100%" stop-color="#4c84b4" stop-opacity=".03"/></linearGradient></defs>
                    <line class="line-axis" x1="18" y1="200" x2="738" y2="200"/>
                    <line class="line-grid" x1="18" y1="55" x2="738" y2="55"/><line class="line-grid" x1="18" y1="100" x2="738" y2="100"/><line class="line-grid" x1="18" y1="145" x2="738" y2="145"/>
                    <polygon class="line-area" points="<?=$areaPoints?>"/>
                    <polyline class="line-path" points="<?=$linePoints?>"/>
                    <?php foreach($points as $p): ?>
                        <g><circle class="line-point" cx="<?=$p['x']?>" cy="<?=$p['y']?>" r="4"/><text class="line-value" x="<?=$p['x']?>" y="<?=max(9,$p['y']-8)?>" text-anchor="middle"><?=$p['amount']>0?'₱'.number_format($p['amount']/1000,0).'k':''?></text><text class="line-label" x="<?=$p['x']?>" y="216" text-anchor="middle"><?=e(substr($p['name'],0,3))?></text><title><?=e($p['name'])?> <?=$selectedYear?> — <?=number_format($p['clients'])?> patients — ₱<?=number_format($p['amount'],2)?></title></g>
                    <?php endforeach; ?>
                </svg><div class="fams-chart-legend" aria-label="Chart legend"><span class="fams-chart-legend-mark" aria-hidden="true"></span><span>Released assistance amount</span></div>
            </div>
            <div class="monthly-chart-total"><span>Selected month</span><strong>₱<?=number_format($monthAmount,2)?></strong><small><?=number_format($monthClients)?> patients</small></div>
        </div>
    </div>
    <div class="report-note">The numerical table and line chart use the live reconciled FAMS release records, with the stored <?=e($activeFacilityInfo['short'])?> monthly summary used only when a month has no release records. Use the month/year selectors below to prepare the detailed printable report.</div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="summary" data-title="Summary Metrics">
<section class="summary-grid compact-summary dashboard-inner-editor-grid" data-editor-container="summary">
    <div class="dashboard-editor-division span-3" data-editor-item="summary-released" data-title="Disbursed Clients"><div class="panel summary-card"><span class="section-kicker">DISBURSED CLIENTS</span><strong><?=number_format($releasedYearClients)?></strong><p>Released assistance in <?=$selectedYear?>.</p><div class="summary-amount">₱<?=number_format($releasedYearAmount,2)?></div></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="summary-cancelled" data-title="Cancelled Clients"><div class="panel summary-card"><span class="section-kicker">CANCELLED CLIENTS</span><strong><?=number_format($cancelledYearClients)?></strong><p>Cancelled assistance recorded in <?=$selectedYear?>.</p><div class="summary-amount">₱<?=number_format($cancelledYearAmount,2)?></div></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="summary-rate" data-title="Cancellation Rate"><div class="panel summary-card"><span class="section-kicker">CANCELLATION RATE</span><strong><?=number_format($cancellationRate,1)?>%</strong><p>Cancelled clients ÷ released + cancelled clients.</p></div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="summary-average" data-title="Average Disbursement"><div class="panel summary-card"><span class="section-kicker">AVERAGE DISBURSEMENT</span><strong>₱<?=number_format($avgDisbursement,2)?></strong><p>Average guarantee amount per released client.</p></div></div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="daily-disbursement" data-title="Daily Disbursement">
<section class="panel chart-panel daily-disbursement-panel">
    <div class="panel-heading"><div><span class="section-kicker">DAILY DISBURSEMENT</span><h3><?=e($monthNames[$selectedMonth])?> <?=$selectedYear?></h3></div><div class="chart-total">₱<?=number_format($dailyMonthAmount,2)?><span><?=number_format($dailyMonthClients)?> clients with daily records</span></div></div>
    <div class="dashboard-inner-editor-grid daily-editor-grid" data-editor-container="daily-inner">
    <div class="dashboard-editor-division span-9" data-editor-item="daily-chart" data-title="Daily Line Chart"><div class="line-chart daily-line-chart" aria-label="Daily disbursement line chart">
        <?php
        $dailyPlotW=720; $dailyPlotH=190; $dailyPlotLeft=18;
        $dailyStep=$daysInMonth>1 ? $dailyPlotW/($daysInMonth-1) : $dailyPlotW;
        $dailyPoints=[];
        for($d=1;$d<=$daysInMonth;$d++){
            $amount=(float)($daily[$d]['amount']??0); $clients=(int)($daily[$d]['clients']??0);
            $x=$dailyPlotLeft+(($d-1)*$dailyStep);
            $y=10+($dailyPlotH-(($dailyMax>0?$amount/$dailyMax:0)*($dailyPlotH-20)));
            $dailyPoints[]=['x'=>$x,'y'=>$y,'amount'=>$amount,'clients'=>$clients,'day'=>$d];
        }
        $dailyLinePoints=implode(' ',array_map(fn($pt)=>$pt['x'].','.$pt['y'],$dailyPoints));
        $dailyAreaPoints=$dailyLinePoints.' '.$dailyPoints[count($dailyPoints)-1]['x'].','.(10+$dailyPlotH).' '.$dailyPoints[0]['x'].','.(10+$dailyPlotH);
        ?>
        <div class="line-gauge" aria-label="Daily disbursement amount gauge"><span><?=$gaugeLabel($dailyMax)?></span><span><?=$gaugeLabel($dailyMax*.75)?></span><span><?=$gaugeLabel($dailyMax*.50)?></span><span><?=$gaugeLabel($dailyMax*.25)?></span><span>₱0</span></div>
        <svg viewBox="0 0 760 225" role="img" aria-label="Daily disbursement amounts for <?=e($monthNames[$selectedMonth])?> <?=$selectedYear?>">
            <defs><linearGradient id="dailyLineArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4c84b4" stop-opacity=".30"/><stop offset="100%" stop-color="#4c84b4" stop-opacity=".03"/></linearGradient></defs>
            <line class="line-axis" x1="18" y1="200" x2="738" y2="200"/>
            <line class="line-grid" x1="18" y1="55" x2="738" y2="55"/><line class="line-grid" x1="18" y1="100" x2="738" y2="100"/><line class="line-grid" x1="18" y1="145" x2="738" y2="145"/>
            <polygon class="line-area daily-line-area" points="<?=$dailyAreaPoints?>"/>
            <polyline class="line-path daily-line-path" points="<?=$dailyLinePoints?>"/>
            <?php foreach($dailyPoints as $pt): $showLabel=($pt['day']===1 || $pt['day']===$daysInMonth || $pt['day']%5===0); ?>
                <g><circle class="line-point daily-line-point" cx="<?=$pt['x']?>" cy="<?=$pt['y']?>" r="3.2"/><title><?=e($monthNames[$selectedMonth])?> <?=$pt['day']?>, <?=$selectedYear?> — <?=number_format($pt['clients'])?> clients — ₱<?=number_format($pt['amount'],2)?></title><?php if($pt['amount']>0): ?><text class="line-value daily-line-value" x="<?=$pt['x']?>" y="<?=max(9,$pt['y']-8)?>" text-anchor="middle">₱<?=number_format($pt['amount']/1000,0)?>k</text><?php endif; ?><?php if($showLabel): ?><text class="line-label daily-line-label" x="<?=$pt['x']?>" y="216" text-anchor="middle"><?=$pt['day']?></text><?php endif; ?></g>
            <?php endforeach; ?>
        </svg><div class="fams-chart-legend" aria-label="Chart legend"><span class="fams-chart-legend-mark" aria-hidden="true"></span><span>Released assistance amount</span></div>
    </div></div>
    <div class="dashboard-editor-division span-3" data-editor-item="daily-note" data-title="Daily Reconciliation Note"><div class="chart-note <?= $dailyIsReconciled ? 'reconciled-note' : 'warning-note' ?>"><?= $dailyIsReconciled ? '✓ Daily record total reconciles with the '.e($activeFacilityInfo['short']).' monthly summary.' : '⚠ Daily record total differs from the '.e($activeFacilityInfo['short']).' monthly summary. Check release dates/statuses before finalizing the report.' ?> <strong>Daily: ₱<?=number_format($dailyMonthAmount,2)?> / <?=number_format($dailyMonthClients)?> clients</strong> · <strong>Summary: ₱<?=number_format($dailySummaryAmount,2)?> / <?=number_format($dailySummaryClients)?> clients</strong></div></div>
    </div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="municipalities-tickets" data-title="Municipalities & Tickets">
<section class="dashboard-two-column dashboard-inner-editor-grid" data-editor-container="municipalities-inner">
    <div class="dashboard-editor-division span-6" data-editor-item="top-municipalities" data-title="Top Municipalities"><div class="panel">
        <div class="panel-heading"><div><span class="section-kicker">TOP MUNICIPALITIES</span><h3>Disbursements — <?=$selectedYear?></h3></div><span class="muted">Top 3 by amount</span></div>
        <?php if(!$topMunicipalities): ?><div class="empty-cell">No released records for <?=$selectedYear?>.</div><?php else: ?>
        <div class="mini-ranking"><?php foreach($topMunicipalities as $i=>$row): ?><div class="ranking-row"><span class="rank-number">#<?=($i+1)?></span><div class="rank-main"><strong><?=e($row['municipality'])?></strong><span><?=number_format((int)$row['clients'])?> client(s)</span></div><strong>₱<?=number_format((float)$row['amount'],2)?></strong></div><?php endforeach; ?></div>
        <?php endif; ?>
    </div>

    </div><div class="dashboard-editor-division span-6" data-editor-item="revision-tickets" data-title="Revision Tickets"><div class="panel">
        <div class="panel-heading"><div><span class="section-kicker">REVISION TICKETS</span><h3>Ticket Monitoring</h3></div><a class="text-link" href="tickets.php">Manage →</a></div>
        <div class="ticket-summary"><div><span>OPEN</span><strong class="ticket-open"><?=number_format($ticketCounts['OPEN'])?></strong><small>Needs Admin action</small></div><div><span>RESOLVED</span><strong><?=number_format($ticketCounts['RESOLVED'])?></strong><small>Completed requests</small></div><div><span>REJECTED</span><strong><?=number_format($ticketCounts['REJECTED'])?></strong><small>Not approved</small></div></div>
    </div></div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="yearly-summary" data-title="Yearly Disbursement Summary">
<section class="panel">
    <div class="panel-heading"><div><span class="section-kicker">YEARLY DISBURSEMENT SUMMARY</span><h3>Historical financial performance</h3></div><span class="muted">Automatically rolls into each new year</span></div>
    <div class="table-wrap"><table class="fams-data-list-table"><thead><tr><th>Year</th><th>Released Clients</th><th>Disbursed Amount</th><th>Cancelled Clients</th><th>Cancelled Amount</th></tr></thead><tbody>
    <?php foreach(array_reverse($yearly,true) as $y=>$v): ?><tr><td><strong><?=$y?></strong></td><td><?=number_format($v['released_clients'])?></td><td>₱<?=number_format($v['released_amount'],2)?></td><td><?=number_format($v['cancelled_clients'])?></td><td>₱<?=number_format($v['cancelled_amount'],2)?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="recent-entries" data-title="Recent Entries">
<section class="panel">
    <div class="panel-heading"><div><span class="section-kicker">RECENT ENTRIES</span><h3>Latest FAMS Records</h3></div><a href="records.php" class="text-link">View all records →</a></div>
    <div class="table-wrap"><table class="fams-data-list-table"><thead><tr><th>Control No.</th><th>Date Submitted</th><th>Beneficiary</th><th>Municipality</th><th>Guaranteed Amount</th><th>Status</th></tr></thead><tbody>
    <?php if(!$recent): ?><tr><td colspan="6" class="empty-cell">No records yet.</td></tr><?php endif; ?>
    <?php foreach($recent as $row): ?><tr><td><strong><?=e($row['control_number'])?></strong></td><td><?=e(date('M d, Y',strtotime($row['date_submission'])))?></td><td><?=e($row['patient_first_name'].' '.$row['patient_last_name'])?></td><td><?=e($row['municipality'])?></td><td>₱<?=number_format((float)$row['guarantee_amount'],2)?></td><td><span class="status-badge <?=e(status_class($row['status']))?>"><?=e($row['status'])?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
</section>
<section class="dashboard-layout-item span-12" data-dashboard-item="activity" data-title="Recent Activity & At-a-Glance">
<section class="activity-grid dashboard-inner-editor-grid" data-editor-container="activity-inner">
    <div class="dashboard-editor-division span-6" data-editor-item="recent-activity" data-title="Recent Activity"><div class="panel">
        <div class="panel-heading"><div><span class="section-kicker">RECENT ACTIVITY</span><h3>Latest system actions</h3></div><?php if(($user['role']??'')==='admin'): ?><a class="text-link" href="logs.php">Full audit log →</a><?php endif; ?></div>
        <div class="activity-list">
            <?php if(!$recentActivity): ?><div class="empty-cell">No activity yet.</div><?php endif; ?>
            <?php foreach($recentActivity as $a): ?><div class="activity-row"><div class="activity-dot"></div><div class="activity-main"><strong><?=e($a['description'])?></strong><span><?=e(role_label($a['role']))?> • <?=e($a['username'])?><?php if($a['record_id']): ?> • Record #<?=((int)$a['record_id'])?><?php endif; ?></span></div><time><?=e(date('M d, Y h:i A',strtotime($a['created_at'])))?></time></div><?php endforeach; ?>
        </div>
    </div>
    </div><div class="dashboard-editor-division span-6" data-editor-item="at-a-glance" data-title="At-a-Glance"><div class="panel">
        <div class="panel-heading"><div><span class="section-kicker">AT-A-GLANCE</span><h3>System totals</h3></div></div>
        <div class="glance-list"><div><span>All-Time Bill Amount</span><strong>₱<?=number_format($totalBill,2)?></strong></div><div><span>All-Time Released Amount</span><strong>₱<?=number_format($totalGuaranteed,2)?></strong></div><div><span>Open Revision Tickets</span><strong><?=number_format($ticketCounts['OPEN'])?></strong></div><div><span>Current System Year</span><strong><?=$currentYear?></strong></div></div>
    </div></div>
</section>
</section>
</div>
<?php if ($isFragment): ?></div></div><?php else: require __DIR__ . '/partials/footer.php'; endif; ?>


