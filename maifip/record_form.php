<?php
require_once __DIR__ . '/config.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.'); }
require_role(['admin','manager','encoder','social_worker']);
$formIdempotencyKey = fams_shared_idempotency_key();
if ((current_user()['role'] ?? '') === 'social_worker' && empty($_GET['id'])) { http_response_code(403); exit('SWAD / medical facility users cannot add entries. Use the ticket workflow to request changes.'); }

$activeFacility=active_data_facility($pdo);
$isPopup = isset($_GET['popup']) && $_GET['popup']==='1';
$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
if(!$editing && !fams_current_type_allows('MAIFIP','create_records')){http_response_code(403);exit('Your user type does not have permission to create assistance records. Contact the Site Administrator if this access is required.');}
$record = [
    'control_number'=>'', 'date_submission'=>date('Y-m-d'), 'patient_first_name'=>'',
    'patient_middle_name'=>'', 'patient_last_name'=>'', 'patient_name_extension'=>'',
    'barangay'=>'', 'municipality'=>'', 'province'=>'ISABELA', 'guardian'=>'',
    'contact_number'=>'', 'bill_amount'=>'', 'guarantee_amount'=>'', 'remarks'=>'',
    'status'=>'WAITING', 'release_date'=>'', 'record_version'=>1
];

if ($editing) {
    $s = $pdo->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=?');
    $s->execute([$id,$activeFacility]);
    $record = $s->fetch();
    if (!$record) { http_response_code(404); exit('Record not found in your assigned facility. Facility-level access protects beneficiary privacy and accountability. If the assignment appears incorrect, contact the LPGMA Site Administrator for review.'); }
    if (($userRole = (current_user()['role'] ?? '')) === 'social_worker') { redirect('ticket_create.php?id='.$id); }
    if (!can_edit_existing_record($record)) {
        http_response_code(403);
        exit('This record is finalized. Only the LPGMA Admin / Site Admin can edit released or cancelled records. Please use Submit Ticket to request a revision.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-check the workspace capability on every mutation request.
    if (!fams_current_type_allows('MAIFIP', $editing ? 'edit_records' : 'create_records')) { http_response_code(403); exit('Your user type is not authorized to perform this record operation.'); }
    if ($editing && (!is_array($record) || !can_edit_existing_record($record))) { http_response_code(403); exit('This record is outside your editing permissions or is locked.'); }
    verify_csrf();
    $data = [];
    foreach (['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'] as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
    }
    $billAmountRaw = trim((string)($_POST['bill_amount'] ?? ''));
    $data['bill_amount'] = $billAmountRaw === '' ? null : (float)$billAmountRaw;
    $data['guarantee_amount'] = (float)($_POST['guarantee_amount'] ?? 0);
    $data = fams_uppercase_fields($data, ['patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
    $submittedVersion = max(0, (int)($_POST['record_version'] ?? ($record['record_version'] ?? 1)));

    if ($billAmountRaw === '' || !is_numeric($billAmountRaw) || (float)$billAmountRaw < 0) {
        flash('error', 'Bill Amount is required and must be zero or greater.');
        redirect($isPopup ? ('record_form.php?'.($editing ? 'id='.$id.'&' : '').'popup=1') : ($editing ? "record_form.php?id=$id" : 'record_form.php'));
    }
    $data['bill_amount'] = (float)$billAmountRaw;

    if ($data['date_submission'] === '' || $data['date_submission'] > date('Y-m-d')) {
        flash('error', 'Date of submission cannot be later than today.');
        redirect($isPopup ? ('record_form.php?'.($editing ? 'id='.$id.'&' : '').'popup=1') : ($editing ? "record_form.php?id=$id" : 'record_form.php'));
    }
    if ($data['patient_first_name']==='' || $data['patient_last_name']==='' || $data['municipality']==='') {
        flash('error','First name, last name, and municipality are required.');
        redirect($isPopup ? ('record_form.php?'.($editing ? 'id='.$id.'&' : '').'popup=1') : ($editing ? "record_form.php?id=$id" : 'record_form.php'));
    }
    if ($editing && !can_edit_existing_record($record)) {
        http_response_code(403); exit('Record is locked for this user.');
    }

    if ($editing) {
        try{fams_shared_transaction($pdo,function(PDO $db) use($data,$id,$activeFacility,$submittedVersion){
            $lock=$db->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE');$lock->execute([$id,$activeFacility]);$lockedRecord=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$lockedRecord)throw new RuntimeException('Record not found in your assigned facility.');
            if(!can_edit_existing_record($lockedRecord))throw new RuntimeException('This record is outside your editing permissions or is locked.');
            if((int)($lockedRecord['record_version'] ?? 1)!==$submittedVersion)throw new RuntimeException('This record was changed by another user while you were editing it. Reload the record, review the newer values, and then save again.');
            $q=$db->prepare("UPDATE assistance_records SET date_submission=?,patient_first_name=?,patient_middle_name=?,patient_last_name=?,patient_name_extension=?,barangay=?,municipality=?,province=?,guardian=?,contact_number=?,bill_amount=?,guarantee_amount=?,remarks=?,updated_by=?,record_version=record_version+1 WHERE id=? AND facility=? AND record_version=? AND (status=? OR ?=1)");
            $isAdmin=(current_user()['role'] ?? '')==='admin' ? 1 : 0;
            $q->execute([$data['date_submission'],$data['patient_first_name'],$data['patient_middle_name'],$data['patient_last_name'],$data['patient_name_extension'],$data['barangay'],$data['municipality'],$data['province'],$data['guardian'],$data['contact_number'],$data['bill_amount'],$data['guarantee_amount'],$data['remarks'],current_user()['id'],$id,$activeFacility,$submittedVersion,'WAITING',$isAdmin]);
            if($q->rowCount()!==1)throw new RuntimeException('This record was changed by another user while you were editing it. Reload the record, review the newer values, and then save again.');
            log_action($db,'UPDATE_RECORD','Updated assistance record data.',$id);
        });}catch(Throwable $e){flash('error',$e->getMessage());redirect($isPopup?"record_form.php?id=$id&popup=1":"record_form.php?id=$id");}
        flash('success','Record updated successfully.');
    } else {
        ensure_control_number_schema($pdo);
        $controlYear=(int)date('Y',strtotime($data['date_submission']));
        $controlPrefix=$controlYear.'-';
        try {
            $sequenceLockName='fams:maifip:'.substr(hash('sha256',$activeFacility),0,48);$sequenceLock=$pdo->prepare('SELECT GET_LOCK(?,8)');$sequenceLock->execute([$sequenceLockName]);if((int)$sequenceLock->fetchColumn()!==1)throw new RuntimeException('The facility control-number sequence is busy. Please retry.');
            try{$submission = fams_shared_idempotent_transaction($pdo, 'maifip.record.create', (string)($_POST['idempotency_key'] ?? ''), (int)current_user()['id'], function(PDO $db) use ($activeFacility, $data, $controlYear): array {
                $controlStmt=$db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(control_number,'-',-1) AS UNSIGNED)),0) FROM assistance_records WHERE facility=? AND control_number REGEXP '^[0-9]{4}-[0-9]+$' AND LEFT(control_number,4)=?");
                $controlStmt->execute([$activeFacility, (string)$controlYear]);
                $nextControl=(int)$controlStmt->fetchColumn()+1;
                if ($nextControl>9999) throw new RuntimeException('Control number limit reached for '.$controlYear.'.');
                $control=$controlYear.'-'.str_pad((string)$nextControl,4,'0',STR_PAD_LEFT);
                $q=$db->prepare('INSERT INTO assistance_records(facility,control_number,date_submission,patient_first_name,patient_middle_name,patient_last_name,patient_name_extension,barangay,municipality,province,guardian,contact_number,bill_amount,guarantee_amount,remarks,status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$activeFacility,$control,$data['date_submission'],$data['patient_first_name'],$data['patient_middle_name'],$data['patient_last_name'],$data['patient_name_extension'],$data['barangay'],$data['municipality'],$data['province'],$data['guardian'],$data['contact_number'],$data['bill_amount'],$data['guarantee_amount'],$data['remarks'],'WAITING',current_user()['id'],current_user()['id']]);
                $newId=(int)$db->lastInsertId();
                fams_status_history_write($db,'maifip',$newId,null,'WAITING',(int)current_user()['id'],'Initial record encoding');
                log_action($db,'CREATE_RECORD','Created assistance record '.$control,$newId);
                return ['id'=>$newId,'control'=>$control];
            });}finally{$releaseLock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$releaseLock->execute([$sequenceLockName]);}
            if ($submission['duplicate']) {
                flash('success','This record submission was already processed. No duplicate record was created.');
                redirect('records.php');
            }
            flash('success','Record added successfully.');
        } catch(Throwable $e) {
            if($e instanceof PDOException && $e->getCode()==='23000'){
                flash('error','A control number conflict occurred while creating the record. Please reload and try again.');
                redirect($isPopup ? 'record_form.php?popup=1' : 'record_form.php');
            }
            throw $e;
        }
    }
    if ($isPopup) {
        $successMessage = $editing ? 'Record updated successfully.' : 'Record added successfully.';
        $messageJson = json_encode($successMessage, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Saved</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="fams-popup-page"><script>parent.postMessage({type:"fams-popup-success",message:'.$messageJson.'},"*");</script></body></html>';
        exit;
    }
    redirect('records.php');
}

$pageTitle=$editing?'Edit FAMS Record':'New FAMS Record';
if ($isPopup) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($pageTitle).'</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="fams-popup-page">';
} else {
    require __DIR__.'/partials/header.php';
}
?>
<section class="panel form-panel record-entry-panel">
<div class="offline-form-notice" data-offline-form-status role="status" aria-live="polite" hidden></div>
<div class="panel-heading"><div><span class="section-kicker"><?= $editing?'DATA REVISION / ADMIN EDIT':'NEW DATA ENTRY' ?></span><h3><?=e($pageTitle)?></h3></div><a class="ghost-btn" href="records.php">← Back</a></div>
<?php if($editing): ?><div class="notice"><strong>Control No.:</strong> <?=e($record['control_number'])?> &nbsp; <span class="status-badge <?=e(status_class($record['status']))?>"><?=e($record['status'])?></span><br><small><?php if(($user['role']??'')==='admin'): ?>Admin can directly edit all record details.<?php elseif(strtoupper((string)$record['status'])==='WAITING'): ?>Waiting records may be directly edited by LPGMA Staff and Social Workers.<?php else: ?>Released and cancelled records are locked for direct editing. Use <strong>Submit Ticket</strong> to request detail and/or status updates.<?php endif; ?></small></div><?php endif; ?>
<form method="post" class="record-form" data-offline-record-form="1" data-record-id="<?= (int)$id ?>" data-record-version="<?= (int)($record['record_version'] ?? 1) ?>"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="idempotency_key" value="<?=e($formIdempotencyKey)?>"><input type="hidden" name="record_version" value="<?= (int)($record['record_version'] ?? 1) ?>">
<div class="form-section-title">Submission & Patient Information</div><div class="form-grid">
<div><label>Date of Submission</label><input type="date" name="date_submission" max="<?=e(date('Y-m-d'))?>" required value="<?=e($record['date_submission'])?>"></div>
<div><label>First Name</label><input name="patient_first_name" required value="<?=e($record['patient_first_name'])?>"></div>
<div><label>Middle Name</label><input name="patient_middle_name" value="<?=e($record['patient_middle_name'])?>"></div>
<div><label>Last Name</label><input name="patient_last_name" required value="<?=e($record['patient_last_name'])?>"></div>
<div><label>Name Extension</label><input name="patient_name_extension" placeholder="JR, SR, II..." value="<?=e($record['patient_name_extension'] ?? '')?>"></div>
</div>
<div class="form-section-title">Address & Contact</div><div class="form-grid address-hierarchy-grid">
<div><label for="province">Province</label><select id="province" name="province" required><option value="">Select province</option></select></div>
<div><label for="municipality">Municipality / City</label><select id="municipality" name="municipality" required disabled><option value="">Select province first</option></select></div>
<div><label for="barangay">Barangay</label><select id="barangay" name="barangay" disabled><option value="">Select municipality/city first</option></select></div>
<div><label>Guardian</label><input name="guardian" value="<?=e($record['guardian'])?>"></div><div><label>Contact Number</label><input name="contact_number" inputmode="tel" autocomplete="tel" maxlength="30" placeholder="e.g. 0965 032 7116" value="<?=e($record['contact_number'])?>"></div></div>
<div class="form-section-title">Financial Assistance</div><div class="form-grid"><div><label>Bill Amount <span class="field-required">*</span></label><input type="number" step="0.01" min="0" name="bill_amount" required value="<?=e((string)$record['bill_amount'])?>"></div><div><label>Guarantee Amount</label><input type="number" step="0.01" min="0" name="guarantee_amount" value="<?=e((string)$record['guarantee_amount'])?>"></div><div class="span-2"><label>Remarks</label><textarea name="remarks" rows="4"><?=e($record['remarks'])?></textarea></div></div>
<div class="form-actions"><a class="ghost-btn" href="records.php">Cancel</a><button class="primary-btn" type="submit"><?= $editing?'Save Changes':'Create Record' ?></button></div></form></section>
<script>
(function(){
 const province=document.getElementById('province'), municipality=document.getElementById('municipality'), barangay=document.getElementById('barangay');
 if(!province||!municipality||!barangay)return;
 const old={province:<?=json_encode($record['province']??'')?>, municipality:<?=json_encode($record['municipality']??'')?>, barangay:<?=json_encode($record['barangay']??'')?>};
 const api='address_api.php';
 const getJson=async(params)=>{const r=await fetch(api+'?'+params,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const d=await r.json().catch(()=>({}));if(!r.ok||d.ok===false)throw new Error(d.error||'Address request failed');return d;};
 const remoteItems=async(urls,preferredKey)=>{for(const url of urls){try{const r=await fetch(url,{mode:'cors',cache:'no-store',headers:{Accept:'application/json'}});if(!r.ok)continue;const d=await r.json();let items=[];if(preferredKey&&Array.isArray(d?.[preferredKey]))items=d[preferredKey];else if(Array.isArray(d?.data))items=d.data;else if(Array.isArray(d?.result))items=d.result;else if(Array.isArray(d?.results))items=d.results;else if(Array.isArray(d))items=d;items=items.map(x=>{const p=(x&&typeof x.properties==='object'&&x.properties)?x.properties:x;return {code:String(p?.psgc_code||p?.psgc10DigitCode||p?.code||''),name:String(p?.name||'')}}).filter(x=>x.code&&x.name);if(items.length)return items;}catch(e){/* try next public source */}}return [];};
 const province9=code=>String(code||'').length===10?String(code).slice(0,9):String(code||'');
 const province4=code=>{const c=String(code||'');return c.length===10?c.slice(0,2)+c.slice(2,4):c.slice(0,4)};
 const city9=code=>String(code||'').length===10?String(code).slice(0,9):String(code||'');
 const city6=code=>{const c=String(code||'');return c.length===10?c.slice(0,6):c.slice(0,6)};
 const loadRemoteBarangays=async code=>remoteItems([
   'https://psgc.cloud/api/v2/cities-municipalities/'+encodeURIComponent(code)+'/barangays',
   'https://psgc.cloud/api/v1/cities-municipalities/'+encodeURIComponent(city6(code))+'/barangays',
   'https://psgc.gitlab.io/api/cities/'+encodeURIComponent(city9(code))+'/barangays/'
 ],'barangays');
 const loadRemoteMunicipalities=async code=>remoteItems([
   'https://psgc.cloud/api/v2/provinces/'+encodeURIComponent(code)+'/cities-municipalities',
   'https://psgc.cloud/api/v1/provinces/'+encodeURIComponent(province4(code))+'/cities-municipalities',
   'https://psgc.gitlab.io/api/provinces/'+encodeURIComponent(province9(code))+'/cities-municipalities/'
 ],'cities');
 const setOptions=(el,items,placeholder)=>{el.innerHTML='';const ph=document.createElement('option');ph.value='';ph.textContent=placeholder;el.appendChild(ph);(items||[]).forEach(x=>{const name=String(x.name||x.code||'');const o=document.createElement('option');o.value=name;o.textContent=name;o.dataset.code=String(x.code||'');el.appendChild(o);});};
 const findOption=(el,value)=>{const t=String(value||'').trim().toUpperCase();return [...el.options].find(o=>String(o.value).trim().toUpperCase()===t||String(o.textContent).trim().toUpperCase()===t);};
 async function loadBarangays(code,selected){
   barangay.disabled=true;setOptions(barangay,[],'Loading barangays…');
   try{
     let d=await getJson('action=barangays&municipality='+encodeURIComponent(code));
     let items=d.items||[];
     if(!items && Array.isArray(items)) items=[];
     if(!items.length && String(code).startsWith('010')) items=await loadRemoteBarangays(code);
     setOptions(barangay,items,'Select barangay');barangay.disabled=false;
     if(selected){const o=findOption(barangay,selected);if(o)barangay.value=o.value;}
   }catch(e){
     let items=[];if(String(code).startsWith('010')) items=await loadRemoteBarangays(code);
     setOptions(barangay,items,'Select barangay');barangay.disabled=false;
     if(selected){const o=findOption(barangay,selected);if(o)barangay.value=o.value;}
     if(!items.length)setOptions(barangay,[],'No barangays available — refresh local PSGC data');console.warn(e);
   }
 }
 async function loadMunicipalities(code,selected,selectedBarangay){
   municipality.disabled=true;barangay.disabled=true;setOptions(municipality,[],'Loading municipalities / cities…');setOptions(barangay,[],'Select municipality / city first');
   try{
     const d=await getJson('action=municipalities&province='+encodeURIComponent(code));
     let items=d.items||[];
     if(!items.length && String(code).startsWith('010')) items=await loadRemoteMunicipalities(code);
     setOptions(municipality,items,'Select municipality / city');municipality.disabled=false;
     if(selected){const o=findOption(municipality,selected);if(o){municipality.value=o.value;await loadBarangays(o.dataset.code||o.value,selectedBarangay);}}
   }catch(e){
     let items=[];if(String(code).startsWith('010')) items=await loadRemoteMunicipalities(code);
     setOptions(municipality,items,'Select municipality / city');municipality.disabled=false;
     if(selected){const o=findOption(municipality,selected);if(o){municipality.value=o.value;await loadBarangays(o.dataset.code||o.value,selectedBarangay);}}
     if(!items.length)setOptions(municipality,[],'No municipalities / cities available — refresh local PSGC data');console.warn(e);
   }
 }
 async function loadProvinces(){
   try{const d=await getJson('action=provinces');setOptions(province,d.items||[],'Select province');province.disabled=false;if(old.province){const o=findOption(province,old.province);if(o){province.value=o.value;await loadMunicipalities(o.dataset.code||o.value,old.municipality,old.barangay);}}}catch(e){setOptions(province,[],'Address list unavailable — contact admin');province.disabled=false;console.warn(e);}
 }
 province.addEventListener('change',()=>{const o=province.selectedOptions[0];setOptions(municipality,[],'Select province first');setOptions(barangay,[],'Select municipality / city first');if(o&&o.dataset.code)loadMunicipalities(o.dataset.code);});
 municipality.addEventListener('change',()=>{const o=municipality.selectedOptions[0];setOptions(barangay,[],'Select municipality / city first');if(o&&o.dataset.code)loadBarangays(o.dataset.code);});
 loadProvinces();
})();
</script>
<?php if ($isPopup): ?><script>document.querySelector('.record-form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));</script></body></html><?php else: require __DIR__.'/partials/footer.php'; endif; ?>
