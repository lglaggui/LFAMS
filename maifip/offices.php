<?php
declare(strict_types=1);
require_once __DIR__.'/config.php'; require_login(); if(!fams_current_type_allows('MAIFIP','manage_offices')){http_response_code(403);exit('Facility management is not permitted.');}
require_role(['admin']);
$pageTitle='Facility Management';

function fm_clean_key(string $v): string { return strtoupper(trim($v)); }
function fm_valid_key(string $v): bool { return (bool)preg_match('/^[A-Z][A-Z0-9_]{1,31}$/',$v); }
function fm_valid_hex(string $v): bool { return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/',$v); }
function fm_dependency_count(PDO $pdo,string $facility): array {
    $tables=['users','assistance_records','fund_downloads','monthly_disbursement_summary','monthly_report_settings']; $out=[];
    foreach($tables as $t){
        try { $st=$pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE facility=?"); $st->execute([$facility]); $out[$t]=(int)$st->fetchColumn(); }
        catch(Throwable $e){ $out[$t]=0; }
    }
    return $out;
}
function fm_logo_upload(?array $file): ?array {
    if(!$file || (int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
    if((int)$file['error']!==UPLOAD_ERR_OK) throw new RuntimeException('The facility logo upload failed.');
    if((int)$file['size']>2*1024*1024) throw new RuntimeException('Facility logos must be 2 MB or smaller.');
    $tmp=(string)($file['tmp_name']??'');
    if($tmp==='' || !is_uploaded_file($tmp)) throw new RuntimeException('The facility logo upload could not be verified.');
    $raw=file_get_contents($tmp); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed=['image/jpeg','image/png','image/webp','image/svg+xml'];
    if(!in_array($mime,$allowed,true) || $raw===false || $raw==='') throw new RuntimeException('Logo must be a valid JPG, PNG, WebP, or SVG image.');
    return [$mime,$raw];
}
function fm_next_sort_order(PDO $pdo): int {
    try { return ((int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM fams_facilities')->fetchColumn())+10; }
    catch(Throwable $e){ return 100; }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'');
    try {
        verify_csrf();
        if($action==='save'){
            $original=fm_clean_key((string)($_POST['original_key']??''));
            $key=fm_clean_key((string)($_POST['facility_key']??''));
            $name=fams_uppercase_text($_POST['name']??'');
            $short=fm_clean_key((string)($_POST['short_name']??''));
            $accent=trim((string)($_POST['accent_color']??''));
            if(!fm_valid_key($key) || $key==='LPGMA') throw new RuntimeException('Use a valid facility code. LPGMA is reserved for the district office.');
            if($name==='' || mb_strlen($name)>160) throw new RuntimeException('Facility name is required.');
            if(!fm_valid_key($short) || mb_strlen($short)>40) throw new RuntimeException('Use a valid short/acronym name.');
            if(!fm_valid_hex($accent)) throw new RuntimeException('Choose a valid 6-digit accent color.');
            $logoUpload=fm_logo_upload($_FILES['logo']??null);

            $logKey=fams_shared_transaction($pdo,function(PDO $db) use($original,$key,$name,$short,$accent,$logoUpload){
                if($original!==''){
                    $st=$db->prepare('SELECT * FROM fams_facilities WHERE facility_key=? LIMIT 1 FOR UPDATE');$st->execute([$original]);$existing=$st->fetch();
                    if(!$existing)throw new RuntimeException('Facility was not found.');
                    if((int)$existing['is_system']===1)throw new RuntimeException('System facilities cannot be edited here.');
                    if($key!==$original)throw new RuntimeException('Facility Code cannot be changed after the facility is created. Edit the facility name, acronym, color, or logo instead.');
                    $st=$db->prepare('SELECT COUNT(*) FROM fams_facilities WHERE id<>? AND (facility_key=? OR short_name=?)');$st->execute([(int)$existing['id'],$key,$short]);
                    if((int)$st->fetchColumn()>0)throw new RuntimeException('Facility code or short name already exists.');
                    $mediaKey=(string)($existing['logo_media_key']??'');
                    if($logoUpload){if($mediaKey==='')$mediaKey='facility_logo_'.bin2hex(random_bytes(10));[$mime,$raw]=$logoUpload;$db->prepare('INSERT INTO system_media(media_key,mime_type,image_data) VALUES(?,?,?) ON DUPLICATE KEY UPDATE mime_type=VALUES(mime_type),image_data=VALUES(image_data),updated_at=CURRENT_TIMESTAMP')->execute([$mediaKey,$mime,$raw]);}
                    $db->prepare('UPDATE fams_facilities SET name=?,short_name=?,accent_color=?,logo_media_key=? WHERE id=?')->execute([$name,$short,$accent,$mediaKey!==''?$mediaKey:null,(int)$existing['id']]);
                }else{
                    $st=$db->prepare('SELECT COUNT(*) FROM fams_facilities WHERE facility_key=? OR short_name=?');$st->execute([$key,$short]);
                    if((int)$st->fetchColumn()>0)throw new RuntimeException('Facility code or short name already exists.');
                    $db->prepare('INSERT INTO fams_facilities(facility_key,name,short_name,accent_color,logo_media_key,is_active,is_system,sort_order) VALUES(?,?,?,?,NULL,1,0,?)')->execute([$key,$name,$short,$accent,fm_next_sort_order($db)]);
                    $newId=(int)$db->lastInsertId();
                    if($logoUpload){$mediaKey='facility_logo_'.bin2hex(random_bytes(10));[$mime,$raw]=$logoUpload;$db->prepare('INSERT INTO system_media(media_key,mime_type,image_data) VALUES(?,?,?)')->execute([$mediaKey,$mime,$raw]);$db->prepare('UPDATE fams_facilities SET logo_media_key=? WHERE id=?')->execute([$mediaKey,$newId]);}
                }
                log_action($db,'FACILITY_SAVE','Saved facility '.$key);return $key;
            });
            if(isset($_GET['popup']) || !empty($_POST['popup_mode'])) { redirect('offices.php?popup=1&saved=1&facility='.rawurlencode($logKey)); } flash('success','Facility saved successfully.'); redirect('offices.php');
        }
        if($action==='toggle'){
            $key=fm_clean_key((string)($_POST['facility_key']??''));
            $new=fams_shared_transaction($pdo,function(PDO $db) use($key){
                $db->query('SELECT id FROM fams_facilities WHERE is_system=0 ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
                $st=$db->prepare('SELECT * FROM fams_facilities WHERE facility_key=? LIMIT 1 FOR UPDATE');$st->execute([$key]);$f=$st->fetch();
                if(!$f||(int)$f['is_system']===1)throw new RuntimeException('That facility cannot be disabled.');
                $next=(int)$f['is_active']===1?0:1;
                if($next===0){$activeCount=(int)$db->query('SELECT COUNT(*) FROM fams_facilities WHERE is_system=0 AND is_active=1')->fetchColumn();if($activeCount<=1)throw new RuntimeException('At least one medical facility must remain enabled. Enable another facility before disabling this one.');}
                $db->prepare('UPDATE fams_facilities SET is_active=? WHERE facility_key=?')->execute([$next,$key]);
                log_action($db,$next?'FACILITY_ENABLE':'FACILITY_DISABLE',($next?'Enabled ':'Disabled ').$key);return $next;
            });
            if(!$new && (string)($_SESSION['fams_facility']??'')===$key)unset($_SESSION['fams_facility']);
            flash('success',$new?'Facility enabled.':'Facility disabled. Existing users of this facility will be blocked until it is enabled again.');redirect('offices.php');
        }
        if($action==='remove'){
            $key=fm_clean_key((string)($_POST['facility_key']??''));
            fams_shared_transaction($pdo,function(PDO $db) use($key){
                $db->query('SELECT id FROM fams_facilities WHERE is_system=0 ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
                $st=$db->prepare('SELECT * FROM fams_facilities WHERE facility_key=? LIMIT 1 FOR UPDATE');$st->execute([$key]);$f=$st->fetch();
                if(!$f||(int)$f['is_system']===1)throw new RuntimeException('That facility cannot be removed.');
                $deps=fm_dependency_count($db,$key);
                if(array_sum($deps)>0){$parts=[];foreach($deps as $table=>$count)if($count>0)$parts[]=$table.': '.$count;throw new RuntimeException('This facility still has dependencies ('.implode(', ',$parts).'). Disable it instead; removal is allowed only after its users and facility data are cleared.');}
                if((int)$f['is_active']===1){$activeCount=(int)$db->query('SELECT COUNT(*) FROM fams_facilities WHERE is_system=0 AND is_active=1')->fetchColumn();if($activeCount<=1)throw new RuntimeException('At least one medical facility must remain enabled. Enable another facility before removing this one.');}
                $media=(string)($f['logo_media_key']??'');$del=$db->prepare('DELETE FROM fams_facilities WHERE facility_key=?');$del->execute([$key]);if($del->rowCount()!==1)throw new RuntimeException('Facility could not be removed.');
                if($media!==''){$st=$db->prepare('SELECT COUNT(*) FROM fams_facilities WHERE logo_media_key=?');$st->execute([$media]);if((int)$st->fetchColumn()===0)$db->prepare('DELETE FROM system_media WHERE media_key=?')->execute([$media]);}
                log_action($db,'FACILITY_REMOVE','Removed facility '.$key);
            });
            unset($_SESSION['fams_facility']);flash('success','Facility removed.');redirect('offices.php');
        }
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); if(!empty($_POST['popup_mode']) || isset($_GET['popup'])) { redirect('offices.php?popup=1&error='.rawurlencode($e->getMessage())); } flash('error',$e->getMessage()); redirect('offices.php'); }
}


// Never let an un-applied facility migration blank the entire application.
// The management page can render a clear setup message until the explicit SQL
// migration has been applied.
if (!facility_table_available($pdo)) {
    include __DIR__.'/partials/header.php';
    ?>
    <section class="page-card facility-management-page">
      <div class="section-heading"><div>
        <span class="eyebrow">ADMINISTRATION</span>
        <h2>Facility Management</h2>
        <p>The facility-management database table has not been installed yet.</p>
      </div></div>
      <div class="form-card">
        <h3>Database setup required</h3>
        <p>Run <strong>upgrade_to_v0_918_facilities.sql</strong> in phpMyAdmin, then reload this page. Your existing FAMS data is not changed by this message.</p>
        <div class="form-actions"><a class="primary-btn" href="offices.php">Reload Facility Management</a></div>
      </div>
    </section>
    <?php
    include __DIR__.'/partials/footer.php';
    exit;
}

$isPopup = isset($_GET['popup']) && $_GET['popup']==='1';
$editKey=fm_clean_key((string)($_GET['edit']??'')); $edit=null;
if($editKey!==''){ $st=$pdo->prepare('SELECT * FROM fams_facilities WHERE facility_key=? LIMIT 1');$st->execute([$editKey]);$edit=$st->fetch()?:null; }
$facilities=$pdo->query('SELECT * FROM fams_facilities ORDER BY sort_order, name')->fetchAll();

if($isPopup){
    if($edit && (int)$edit['is_system']===1){ http_response_code(403); exit('System facilities cannot be edited here.'); }
    $popupTitle = $edit ? 'Edit Facility' : 'Add Facility';
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($popupTitle)?></title><link rel="stylesheet" href="assets/app.css?v=<?=e(APP_VERSION)?>"></head><body class="fams-popup-page">
    <section class="facility-popup-editor" aria-labelledby="facilityPopupTitle">
      <div class="facility-popup-heading"><div><span class="section-kicker">FACILITY MANAGEMENT</span><h2 id="facilityPopupTitle"><?=e($popupTitle)?></h2><p><?= $edit ? 'Update the facility identity and visual settings.' : 'Set up a new medical facility.' ?></p></div></div>
      <form method="post" enctype="multipart/form-data" class="record-form facility-popup-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="original_key" value="<?=e($edit['facility_key']??'')?>"><input type="hidden" name="popup_mode" value="1">
        <div class="form-grid-2">
          <div><label>Facility Code</label><input name="facility_key" maxlength="32" required value="<?=e($edit['facility_key']??'')?>" placeholder="e.g. NMC" <?= $edit ? 'readonly' : '' ?>><small><?= $edit ? 'Stable identifier; cannot be changed after creation.' : 'Uppercase letters, numbers, and underscore only.' ?></small></div>
          <div><label>Short Name / Acronym</label><input name="short_name" maxlength="40" required value="<?=e($edit['short_name']??'')?>" placeholder="e.g. NMC"></div>
        </div>
        <label>Full Facility Name</label><input name="name" maxlength="160" required value="<?=e($edit['name']??'')?>" placeholder="Medical facility full name">
        <div class="form-grid-2">
          <div><label>Accent Color</label><div class="facility-color-control"><input type="color" id="facilityAccentPicker" value="<?=e($edit['accent_color']??'#2b587d')?>"><input name="accent_color" id="facilityAccentText" maxlength="7" value="<?=e($edit['accent_color']??'#2b587d')?>" pattern="^#[0-9A-Fa-f]{6}$" required></div></div>
          <div><label>Facility Logo</label><input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/svg+xml"><small>JPG, PNG, WebP, or SVG; max 2 MB. Leave empty to keep the current logo.</small></div>
        </div>
        <?php if($edit): ?><div class="facility-preview-box"><div class="facility-preview-logo"><?=facility_logo_markup($edit['facility_key'])?></div><div><strong><?=e($edit['short_name'])?></strong><span><?=e($edit['name'])?></span><small>Current accent: <i id="facilityAccentPreview" style="background:<?=e($edit['accent_color'])?>"></i> <span id="facilityAccentPreviewText"><?=e($edit['accent_color'])?></span></small></div></div><?php endif; ?>
        <div class="form-actions"><button class="primary-btn" type="submit"><?= $edit ? 'Save Changes' : 'Add Facility' ?></button><button class="secondary-btn" type="button" onclick="parent.postMessage({type:'fams-close-popup'},'*')">Cancel</button></div>
      </form>
    </section>
    <script>(()=>{const p=document.getElementById('facilityAccentPicker'),t=document.getElementById('facilityAccentText'),dot=document.getElementById('facilityAccentPreview'),txt=document.getElementById('facilityAccentPreviewText');const sync=()=>{if(!p||!t)return;if(/^#[0-9a-f]{6}$/i.test(t.value)){p.value=t.value;if(dot)dot.style.background=t.value;if(txt)txt.textContent=t.value;}};if(p&&t){p.addEventListener('input',()=>{t.value=p.value;if(dot)dot.style.background=p.value;if(txt)txt.textContent=p.value;});t.addEventListener('input',sync);}document.querySelector('form')?.addEventListener('submit',()=>{parent.postMessage({type:'fams-popup-submitting'},'*');});<?php if(isset($_GET['saved']) && $_GET['saved']==='1'): ?>window.parent.postMessage({type:'fams-popup-success',message:'<?=e($edit?'Facility updated successfully.':'Facility added successfully.')?>'},'*');<?php endif; ?><?php if(isset($_GET['error'])): ?>document.querySelector('.facility-popup-form')?.insertAdjacentHTML('afterbegin','<div class="notice error"><?=e((string)($_GET['error']??''))?></div>');<?php endif; ?>})();</script></body></html><?php exit;
}

include __DIR__.'/partials/header.php';
?>
<section class="page-card facility-management-page">
  <div class="section-heading"><div><span class="eyebrow">ADMINISTRATION</span><h2>Facility Management</h2><p>Manage medical facilities, availability, logos, and visual accents. Facility codes remain stable to protect existing records and user assignments.</p></div></div>
  <div class="facility-management-grid">
    <section class="form-card facility-editor-card">
      <div class="facility-editor-card-icon" aria-hidden="true">✦</div>
      <span class="section-kicker">FACILITY EDITOR</span>
      <h3>Add Facility</h3>
      <p class="facility-editor-lead">Create a medical facility with its stable code, official name, acronym, logo, and visual accent. The editor opens in a focused popup so the management list remains visible behind a softened backdrop.</p>
      <div class="facility-editor-features">
        <span>Stable facility code</span><span>Custom logo</span><span>Facility accent</span>
      </div>
      <a class="primary-btn facility-open-editor" href="offices.php?popup=1" data-fams-popup data-popup-title="Add Facility">+ Add Facility</a>
      <small class="facility-editor-note">Facility codes cannot be changed after creation.</small>
    </section>
    <section class="facility-list-card"><div class="section-subheading"><h3>Facilities</h3><span><?=count($facilities)?> configured</span></div>
      <div class="facility-management-list">
      <?php foreach($facilities as $f): $deps=fm_dependency_count($pdo,(string)$f['facility_key']); $depTotal=array_sum($deps); ?>
        <article class="facility-management-item <?=!(int)$f['is_active']?'facility-disabled':''?>">
          <div class="facility-management-logo"><?=facility_logo_markup($f['facility_key'])?></div>
          <div class="facility-management-info"><div class="facility-management-title"><strong><?=e($f['short_name'])?></strong><span class="facility-status-pill <?=((int)$f['is_active']?'enabled':'disabled')?>"><?=((int)$f['is_active']?'Enabled':'Disabled')?></span></div><span><?=e($f['name'])?></span><small><i style="background:<?=e($f['accent_color'])?>"></i><?=e($f['accent_color'])?> · <?=e($f['facility_key'])?><?php if(!(int)$f['is_system'] && $depTotal>0): ?> · <?=e((string)$depTotal)?> dependency records<?php endif; ?></small></div>
          <div class="facility-management-actions">
          <?php if((int)$f['is_system']===1): ?><span class="system-facility-note">System</span><?php else: ?>
            <a class="secondary-btn" href="offices.php?edit=<?=e($f['facility_key'])?>&popup=1" data-fams-popup data-popup-title="Edit Facility">Edit</a>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="facility_key" value="<?=e($f['facility_key'])?>"><button class="secondary-btn" type="submit"><?=((int)$f['is_active']?'Disable':'Enable')?></button>
            </form>
            <form method="post" onsubmit="return confirm('Remove <?=e($f['short_name'])?>? Removal is permanent and allowed only when it has no users or facility data.');">
              <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="facility_key" value="<?=e($f['facility_key'])?>"><button class="danger-btn" type="submit" <?= $depTotal>0 ? 'disabled title="Clear facility dependencies before removal"' : '' ?>>Remove</button>
            </form>
          <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    </section>
  </div>
</section>
<script>
(()=>{
 const p=document.getElementById('facilityAccentPicker'),t=document.getElementById('facilityAccentText'),dot=document.getElementById('facilityAccentPreview'),txt=document.getElementById('facilityAccentPreviewText');
 const sync=()=>{if(!p||!t)return; if(/^#[0-9a-f]{6}$/i.test(t.value)){p.value=t.value; if(dot)dot.style.background=t.value; if(txt)txt.textContent=t.value;}};
 if(p&&t){p.addEventListener('input',()=>{t.value=p.value;if(dot)dot.style.background=p.value;if(txt)txt.textContent=p.value;});t.addEventListener('input',sync);}
})();
</script>
<?php include __DIR__.'/partials/footer.php'; ?>
