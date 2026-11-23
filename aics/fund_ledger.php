<?php
/**
 * AICS Region II fund ledger calculations.
 * BEGINNING BALANCE is an opening anchor, never a monthly download.
 */
function aics_fund_ledger_position(PDO $pdo, string $asOfExclusive): array {
    // 2026 is the unified ledger baseline. Only the verified January 1, 2026
    // carryover is an opening anchor; prior-year anchors are historical and
    // ordinary 2026 receipts must be fund additions, never opening balances.
    if ($asOfExclusive >= '2026-01-01') {
        $currentAnchors=$pdo->prepare("SELECT COUNT(*) FROM aics_fund_downloads WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' AND date_downloaded>='2026-01-01' AND date_downloaded<=?");
        $currentAnchors->execute([$asOfExclusive]);
        $anchorCount=(int)$currentAnchors->fetchColumn();
        $janAnchor=$pdo->prepare("SELECT id,date_downloaded,downloaded_amount FROM aics_fund_downloads WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' AND date_downloaded='2026-01-01' ORDER BY id");
        $janAnchor->execute(); $anchors=$janAnchor->fetchAll(PDO::FETCH_ASSOC);
        if ($anchorCount>1 || ($anchorCount===1 && count($anchors)!==1)) {
            throw new RuntimeException('Invalid AICS opening-balance classification. Keep at most one verified January 1, 2026 carryover; reclassify all other receipts as ordinary fund downloads.');
        }
        $anchor=$anchors[0] ?? null;
        $base=$anchor ? (float)$anchor['downloaded_amount'] : 0.0;
        $add=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM aics_fund_downloads WHERE date_downloaded>='2026-01-01' AND date_downloaded<? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
        $add->execute([$asOfExclusive]);
        $released=$pdo->prepare("SELECT COALESCE(SUM(guarantee_amount),0) FROM aics_assistance_records WHERE UPPER(TRIM(status))='RELEASED' AND release_date>='2026-01-01' AND release_date<?");
        $released->execute([$asOfExclusive]);
        $additions=(float)$add->fetchColumn(); $disbursed=(float)$released->fetchColumn();
        return ['anchor'=>$base,'additions'=>$additions,'disbursed'=>$disbursed,'available'=>$base+$additions,'remaining'=>$base+$additions-$disbursed,'anchor_date'=>'2026-01-01'];
    }
    $duplicateStmt=$pdo->prepare("SELECT COUNT(*) FROM aics_fund_downloads WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' AND date_downloaded <= ?");
    $duplicateStmt->execute([$asOfExclusive]);
    $anchorCount=(int)$duplicateStmt->fetchColumn();
    if($anchorCount>1) throw new RuntimeException('Multiple AICS BEGINNING BALANCE records apply to this period. Review the opening-balance records before relying on this fund balance.');
    $anchorStmt=$pdo->prepare("SELECT id,date_downloaded,downloaded_amount FROM aics_fund_downloads WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' AND date_downloaded <= ? ORDER BY date_downloaded DESC,id DESC LIMIT 1");
    $anchorStmt->execute([$asOfExclusive]);
    $anchor=$anchorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($anchor) {
        $anchorDate=(string)$anchor['date_downloaded'];
        $base=(float)$anchor['downloaded_amount'];
        $add=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM aics_fund_downloads WHERE date_downloaded >= ? AND date_downloaded < ? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
        $add->execute([$anchorDate,$asOfExclusive]);
        $released=$pdo->prepare("SELECT COALESCE(SUM(guarantee_amount),0) FROM aics_assistance_records WHERE UPPER(TRIM(status))='RELEASED' AND release_date >= ? AND release_date < ?");
        $released->execute([$anchorDate,$asOfExclusive]);
        $additions=(float)$add->fetchColumn(); $disbursed=(float)$released->fetchColumn();
        return ['anchor'=>$base,'additions'=>$additions,'disbursed'=>$disbursed,'available'=>$base+$additions,'remaining'=>$base+$additions-$disbursed,'anchor_date'=>$anchorDate];
    }
    // Legacy fallback for years/periods without an explicit opening-balance record.
    $fund=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM aics_fund_downloads WHERE date_downloaded < ? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
    $fund->execute([$asOfExclusive]);
    $release=$pdo->prepare("SELECT COALESCE(SUM(guarantee_amount),0) FROM aics_assistance_records WHERE UPPER(TRIM(status))='RELEASED' AND release_date < ?");
    $release->execute([$asOfExclusive]);
    $available=(float)$fund->fetchColumn(); $disbursed=(float)$release->fetchColumn();
    return ['anchor'=>0.0,'additions'=>$available,'disbursed'=>$disbursed,'available'=>$available,'remaining'=>$available-$disbursed,'anchor_date'=>null];
}
function aics_fund_month(PDO $pdo, int $year, int $month): array {
    $start=sprintf('%04d-%02d-01',$year,$month); $end=date('Y-m-d',strtotime($start.' +1 month'));
    if ($year>=2026) {
        $anchors=$pdo->prepare("SELECT COUNT(*) FROM aics_fund_downloads WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' AND date_downloaded>='2026-01-01' AND date_downloaded<?");
        $anchors->execute([$end]);
        $count=(int)$anchors->fetchColumn();
        $jan=$pdo->prepare("SELECT COUNT(*) FROM aics_fund_downloads WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE' AND date_downloaded='2026-01-01'");
        $jan->execute();
        if ($count>1 || ($count===1 && (int)$jan->fetchColumn()!==1)) throw new RuntimeException('Invalid AICS opening-balance classification. Only one verified January 1, 2026 carryover is allowed; classify other receipts as fund downloads.');
    }
    $position=aics_fund_ledger_position($pdo,$start);
    $st=$pdo->prepare("SELECT COALESCE(SUM(downloaded_amount),0) FROM aics_fund_downloads WHERE date_downloaded >= ? AND date_downloaded < ? AND UPPER(TRIM(COALESCE(batch_no,'')))<>'BEGINNING BALANCE'");
    $st->execute([$start,$end]);
    return ['opening'=>round((float)$position['remaining'],2),'additions'=>round((float)$st->fetchColumn(),2),'start'=>$start,'end'=>$end];
}
