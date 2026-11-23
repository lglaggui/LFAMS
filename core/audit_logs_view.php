<?php
/** Shared action-log page UI. Variables are supplied by the workspace adapter. */
$filters=$auditData['filters']; $stats=$auditData['stats']; $rows=$auditData['rows'];
$queryParams=array_filter($filters,static fn($v)=>$v!=='');
$makeAuditPageUrl=static function(int $target) use ($queryParams): string { $q=$queryParams; $q['page']=$target; return 'logs.php?'.http_build_query($q); };
$exportUrl='export_logs.php'.($queryParams?'?'.http_build_query($queryParams):'');
$shown=count($rows); $start=$stats['total'] ? (($auditData['page']-1)*$auditData['per_page'])+1 : 0;
$end=$start+$shown-1;
?>
<section class="panel fams-audit-page" aria-labelledby="famsAuditTitle">
  <div class="panel-heading fams-audit-heading">
    <div><span class="section-kicker">AUDIT TRAIL · <?=e(strtoupper($workspaceLabel))?></span><h3 id="famsAuditTitle">Recorded System Activity</h3><p class="muted">Review account activity, record changes, exports, ticket actions, timestamps, and available client information.</p></div>
    <div class="fams-audit-heading-actions"><span class="fams-audit-range">Showing <?=number_format($start)?>–<?=number_format(max(0,$end))?> of <?=number_format($stats['total'])?></span><a class="secondary-btn" href="<?=e($exportUrl)?>" title="Export the currently filtered results as CSV">Export CSV</a></div>
  </div>
  <div class="fams-audit-stats" aria-label="Filtered activity summary">
    <div class="fams-audit-stat"><span>Matching events</span><strong><?=number_format($stats['total'])?></strong></div>
    <div class="fams-audit-stat"><span>Distinct users</span><strong><?=number_format($stats['users'])?></strong></div>
    <div class="fams-audit-stat"><span>Action types</span><strong><?=number_format($stats['actions'])?></strong></div>
  </div>
  <form class="logs-filter-bar fams-audit-filters" method="get" action="logs.php">
    <div class="logs-filter-field"><label for="logsDateFrom">From date</label><input id="logsDateFrom" type="date" name="date_from" value="<?=e($filters['date_from'])?>"></div>
    <div class="logs-filter-field"><label for="logsDateTo">To date</label><input id="logsDateTo" type="date" name="date_to" value="<?=e($filters['date_to'])?>"></div>
    <div class="logs-filter-field"><label for="logsUser">User</label><select id="logsUser" name="user"><option value="">All users</option><?php foreach($auditData['users'] as $name): ?><option value="<?=e((string)$name)?>" <?=$filters['user']===(string)$name?'selected':''?>><?=e((string)$name)?></option><?php endforeach; ?></select></div>
    <div class="logs-filter-field"><label for="logsAction">Action type</label><select id="logsAction" name="action"><option value="">All actions</option><?php foreach($auditData['actions'] as $action): ?><option value="<?=e((string)$action)?>" <?=$filters['action']===(string)$action?'selected':''?>><?=e((string)$action)?></option><?php endforeach; ?></select></div>
    <div class="logs-filter-field logs-filter-search"><label for="logsSearch">Search all log fields</label><input id="logsSearch" type="search" name="q" value="<?=e($filters['q'])?>" placeholder="User, role, action, description, record ID, IP, host or browser" maxlength="160"></div>
    <div class="logs-filter-actions"><button class="primary-btn" type="submit">Apply filters</button><a class="secondary-btn" href="logs.php">Reset</a></div>
  </form>
  <div class="fams-audit-table-meta"><span>Latest activity appears first.</span><span>Client host names are best-effort network lookups; browser privacy settings may limit details.</span></div>
  <div class="table-wrap fams-data-list-table-wrap fams-audit-table-wrap" role="region" aria-label="Scrollable action log table" tabindex="0">
    <table class="fams-data-list-table fams-standard-table fams-audit-table">
      <thead><tr><th scope="col">Event ID</th><th scope="col">Date / Time</th><th scope="col">User</th><th scope="col">Role</th><th scope="col">Action</th><th scope="col">Description</th><th scope="col">Record ID</th><th scope="col">Client IP</th><th scope="col">Host</th><th scope="col">Browser / Device</th></tr></thead>
      <tbody>
      <?php if(!$rows): ?><tr><td colspan="10" class="empty-cell"><div class="fams-table-empty"><strong>No matching activity</strong><span>Try adjusting the date range or clearing one or more filters.</span></div></td></tr><?php endif; ?>
      <?php foreach($rows as $row): $actionText=(string)($row['action']??''); $actionTone=preg_match('/DELETE|REJECT|CANCEL|DENY/i',$actionText)?'danger':(preg_match('/CREATE|ADD|APPROVE|RELEASE|RESTORE/i',$actionText)?'success':(preg_match('/UPDATE|EDIT|SUBMIT|TICKET/i',$actionText)?'info':'neutral')); ?>
        <tr>
          <td class="fams-audit-id">#<?=number_format((int)$row['id'])?></td>
          <td class="fams-audit-date"><time datetime="<?=e(date('c',strtotime((string)$row['created_at'])))?>"><?=e(date('M d, Y',strtotime((string)$row['created_at'])))?><small><?=e(date('h:i:s A',strtotime((string)$row['created_at'])))?></small></time></td>
          <td><strong><?=e((string)$row['username'])?></strong></td>
          <td><?=e(ucwords(str_replace('_',' ',(string)$row['role'])))?></td>
          <td><span class="fams-audit-action fams-audit-<?=$actionTone?>"><?=e($actionText)?></span></td>
          <td class="fams-audit-description"><span><?=e((string)$row['description'])?></span></td>
          <td><?=!empty($row['record_id'])?'#'.(int)$row['record_id']:'—'?></td>
          <td class="fams-audit-mono"><?=e((string)($row['ip_address']??'—'))?></td>
          <td><?=e((string)($row['client_hostname']??'—'))?></td>
          <td class="fams-audit-agent" title="<?=e((string)($row['user_agent']??''))?>"><?=e((string)($row['user_agent']??'—'))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <nav class="pagination-bar logs-pagination logs-pagination-refined fams-standard-pagination" aria-label="Action log pages">
    <div class="logs-pagination-summary"><strong><?=number_format($stats['total'])?></strong><span>total events</span><small>Page <?=number_format($auditData['page'])?> of <?=number_format($auditData['pages'])?></small></div>
    <div class="logs-page-controls">
      <a class="logs-page-icon <?=$auditData['page']<=1?'is-disabled':''?>" href="<?=e($makeAuditPageUrl(1))?>" aria-label="First page" <?=$auditData['page']<=1?'aria-disabled="true" tabindex="-1"':''?>>«</a>
      <a class="logs-page-icon <?=$auditData['page']<=1?'is-disabled':''?>" href="<?=e($makeAuditPageUrl(max(1,$auditData['page']-1)))?>" aria-label="Previous page" <?=$auditData['page']<=1?'aria-disabled="true" tabindex="-1"':''?>>‹</a>
      <?php $startPage=max(1,min($auditData['page']-2,$auditData['pages']-4));$endPage=min($auditData['pages'],$startPage+4);if($startPage>1): ?><span class="logs-page-ellipsis">…</span><?php endif;for($pg=$startPage;$pg<=$endPage;$pg++): ?><a class="logs-page-number <?=$pg===$auditData['page']?'is-current':''?>" href="<?=e($makeAuditPageUrl($pg))?>" <?=$pg===$auditData['page']?'aria-current="page"':''?>><?=$pg?></a><?php endfor;if($endPage<$auditData['pages']): ?><span class="logs-page-ellipsis">…</span><?php endif; ?>
      <a class="logs-page-icon <?=$auditData['page']>=$auditData['pages']?'is-disabled':''?>" href="<?=e($makeAuditPageUrl(min($auditData['pages'],$auditData['page']+1)))?>" aria-label="Next page" <?=$auditData['page']>=$auditData['pages']?'aria-disabled="true" tabindex="-1"':''?>>›</a>
      <a class="logs-page-icon <?=$auditData['page']>=$auditData['pages']?'is-disabled':''?>" href="<?=e($makeAuditPageUrl($auditData['pages']))?>" aria-label="Last page" <?=$auditData['page']>=$auditData['pages']?'aria-disabled="true" tabindex="-1"':''?>>»</a>
      <form class="logs-page-jump" method="get" action="logs.php"><?php foreach($queryParams as $key=>$value): ?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endforeach; ?><label for="logsJumpPage">Go to</label><input id="logsJumpPage" type="number" name="page" min="1" max="<?=$auditData['pages']?>" value="<?=$auditData['page']?>" aria-label="Go to page"><button type="submit">Go</button></form>
    </div>
  </nav>
</section>
