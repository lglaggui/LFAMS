<?php
require_once __DIR__.'/config.php';
require_login();if(!fams_current_type_allows('MAIFIP','edit_records')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
$user=current_user();
$activeFacility=active_data_facility($pdo);
if(!in_array($user['role'],['admin','encoder'],true)){http_response_code(403);exit('Access denied.');}

function backup_database_before_import(PDO $pdo): string{
    $backupDir=__DIR__.'/backups';
    if(!is_dir($backupDir) && !mkdir($backupDir,0775,true) && !is_dir($backupDir)){
        throw new RuntimeException('Database backup could not be created because the backups folder is not writable. Import was not started.');
    }
    $date=date('Y-m-d');
    $base="Database backup {$date} - Version 1";
    $file=$backupDir.'/'.$base.'.sql';
    if(is_file($file)){
        $file=$backupDir.'/'.$base.' - '.date('His').'.sql';
    }

    $sql="-- FAMS Database Backup\n-- Created before sheet import/merge\n-- Date: ".date('Y-m-d H:i:s')."\n-- Version: 1\n-- Database: lpgma_fams\n\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table){
        $qTable='`'.str_replace('`','``',(string)$table).'`';
        $create=$pdo->query('SHOW CREATE TABLE '.$qTable)->fetch(PDO::FETCH_NUM);
        if(!$create || !isset($create[1])) continue;
        $sql.="DROP TABLE IF EXISTS {$qTable};\n".$create[1].";\n\n";
        $stmt=$pdo->query('SELECT * FROM '.$qTable);
        $columns=[];
        for($i=0;$i<$stmt->columnCount();$i++){$meta=$stmt->getColumnMeta($i);$columns[]='`'.str_replace('`','``',(string)($meta['name']??$i)).'`';}
        $colSql=implode(',',$columns);
        while($row=$stmt->fetch(PDO::FETCH_NUM)){
            $vals=[];
            foreach($row as $value){
                if($value===null){$vals[]='NULL';}
                else{$vals[]=$pdo->quote((string)$value);}
            }
            $sql.='INSERT INTO '.$qTable.' ('.$colSql.') VALUES ('.implode(',',$vals).');'."\n";
        }
        $sql.="\n";
    }
    $sql.="SET FOREIGN_KEY_CHECKS=1;\n";
    if(@file_put_contents($file,$sql,LOCK_EX)===false){
        throw new RuntimeException('Database backup could not be written. Import was not started.');
    }
    return $file;
}

function sheet_clean_header($v){
    $v=strtoupper(trim((string)$v));
    $v=preg_replace('/[^A-Z0-9]+/','_', $v);
    return trim($v,'_');
}
function sheet_date($v){
    $v=trim((string)$v); if($v==='') return null;
    if(is_numeric($v) && (float)$v>20000 && (float)$v<70000){ $ts=((float)$v-25569)*86400; return gmdate('Y-m-d',$ts); }
    foreach(['Y-m-d','m/d/Y','n/j/Y','m-d-Y','n-j-Y','M d, Y','F d, Y','d/m/Y'] as $f){$d=DateTime::createFromFormat($f,$v);if($d instanceof DateTime)return $d->format('Y-m-d');}
    $ts=strtotime($v); return $ts!==false?date('Y-m-d',$ts):null;
}
function sheet_num($v){
    if($v===null||$v==='')return 0; $v=str_replace([',','₱','PHP',' '],'',(string)$v); return is_numeric($v)?(float)$v:0;
}
function parse_csv_sheet($path){
    $fh=fopen($path,'rb'); if(!$fh) throw new RuntimeException('Unable to open CSV file.');
    $headers=fgetcsv($fh); if(!$headers) throw new RuntimeException('The sheet has no header row.');
    $headers=array_map('sheet_clean_header',$headers); $rows=[];
    while(($line=fgetcsv($fh))!==false){ if(count(array_filter($line,fn($x)=>trim((string)$x)!==''))===0) continue; $row=[]; foreach($headers as $i=>$h)$row[$h]=$line[$i]??''; $row['_source_row']=count($rows)+2; $rows[]=$row; }
    fclose($fh); return $rows;
}
function xlsx_shared_strings($zip){
    $out=[]; if(($xml=$zip->getFromName('xl/sharedStrings.xml'))===false)return $out; $sx=simplexml_load_string($xml); if(!$sx)return $out;
    foreach($sx->si as $si){$s=''; foreach($si->xpath('.//t') as $t)$s.=(string)$t; $out[]=$s;} return $out;
}
function xlsx_cell_value($cell,$shared){
    $type=(string)$cell['t']; $v=(string)$cell->v;
    if($type==='s')return $shared[(int)$v]??'';
    if($type==='inlineStr')return implode('',array_map('strval',$cell->xpath('.//t')));
    if($type==='b')return $v==='1'?'TRUE':'FALSE'; return $v;
}
function xml_text_clean($v){$v=html_entity_decode((string)$v,ENT_QUOTES|ENT_XML1,'UTF-8');return trim($v);}
function xlsx_shared_strings_xml($xml){$out=[];if($xml===false)return $out;if(preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is',$xml,$mis)){foreach($mis[1] as $si){$text='';if(preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is',$si,$ts))foreach($ts[1] as $t)$text.=xml_text_clean(strip_tags($t));$out[]=$text;}}return $out;}
function xlsx_cell_value_xml($cell,$shared){$type='';if(preg_match('/\bt="([^"]+)"/i',$cell,$m))$type=$m[1];$v='';if(preg_match('/<v\b[^>]*>(.*?)<\/v>/is',$cell,$m))$v=xml_text_clean($m[1]);if($type==='s')return $shared[(int)$v]??'';if($type==='inlineStr'){if(preg_match_all('/<t\b[^>]*>(.*?)<\/t>/is',$cell,$ts)){return xml_text_clean(implode('',array_map(fn($x)=>strip_tags($x),$ts[1])));}return '';}if($type==='b')return $v==='1'?'TRUE':'FALSE';return $v;}
function xlsx_sheet_target_xml($workbook,$rels){
    if($workbook===false||$rels===false)return 'xl/worksheets/sheet1.xml';
    $map=[];if(preg_match_all('/<Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"[^>]*\/?>(?:<\/Relationship>)?/is',$rels,$ms)){foreach($ms[1] as $i=>$id)$map[$id]=$ms[2][$i];}
    if(preg_match_all('/<sheet\b([^>]*)\/?>(?:<\/sheet>)?/is',$workbook,$ms)){foreach($ms[1] as $attrs){if(preg_match('/\bname="([^"]+)"/i',$attrs,$nm)&&strtoupper(trim(html_entity_decode($nm[1])))==='FAMS'&&preg_match('/(?:\br:id|\bid)="([^"]+)"/i',$attrs,$rid)){$target=$map[$rid[1]]??'worksheets/sheet1.xml';$target=ltrim($target,'/');if(!str_starts_with($target,'xl/'))$target='xl/'.ltrim($target,'./');return $target;}}}
    return 'xl/worksheets/sheet1.xml';
}
function xlsx_rows_from_xml($xml,$shared){$sheet=[];if(!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is',$xml,$rm))return $sheet;foreach($rm[1] as $rowXml){$vals=[];if(preg_match_all('/<c\b([^>]*?)(?:\/\s*>|>(.*?)<\/c>)/is',$rowXml,$cm,PREG_SET_ORDER)){foreach($cm as $c){$attrs=$c[1];$inner=$c[2]??'';if(!preg_match('/\br="([A-Z]+)\d+"/i',$attrs,$ref))continue;$col=0;foreach(str_split(strtoupper($ref[1])) as $ch)$col=$col*26+(ord($ch)-64);$cell='<c'.$attrs.'>'.($inner??'').'</c>';$vals[$col-1]=xlsx_cell_value_xml($cell,$shared);}}if($vals)$sheet[]=$vals;}return $sheet;}
function xlsx_read_file_zip($path){
    if(!class_exists('ZipArchive'))return null;$zip=new ZipArchive();if($zip->open($path)!==true)return null;$wb=$zip->getFromName('xl/workbook.xml');$rels=$zip->getFromName('xl/_rels/workbook.xml.rels');$target=xlsx_sheet_target_xml($wb,$rels);$shared=xlsx_shared_strings_xml($zip->getFromName('xl/sharedStrings.xml'));$xml=$zip->getFromName($target);$zip->close();if($xml===false)return null;return xlsx_rows_from_xml($xml,$shared);
}
function xlsx_zip_entries_fallback($path){
    $data=@file_get_contents($path);if($data===false)throw new RuntimeException('Unable to read the XLSX upload.');
    $eocd=strrpos($data,"PK\x05\x06");if($eocd===false)throw new RuntimeException('The uploaded XLSX file is not a valid ZIP workbook.');
    $disk=unpack('v',substr($data,$eocd+4,2))[1];$cdDisk=unpack('v',substr($data,$eocd+6,2))[1];$entries=unpack('v',substr($data,$eocd+10,2))[1];$cdSize=unpack('V',substr($data,$eocd+12,4))[1];$cdOffset=unpack('V',substr($data,$eocd+16,4))[1];
    if($entries===0)throw new RuntimeException('The XLSX workbook contains no ZIP entries.');
    $pos=$cdOffset;$map=[];
    for($i=0;$i<$entries;$i++){
        if(substr($data,$pos,4)!=="PK\x01\x02")throw new RuntimeException('Unable to read the XLSX ZIP directory.');
        $comp=unpack('v',substr($data,$pos+10,2))[1];$csize=unpack('V',substr($data,$pos+20,4))[1];$usize=unpack('V',substr($data,$pos+24,4))[1];$nlen=unpack('v',substr($data,$pos+28,2))[1];$elen=unpack('v',substr($data,$pos+30,2))[1];$clen=unpack('v',substr($data,$pos+32,2))[1];$offset=unpack('V',substr($data,$pos+42,4))[1];$name=substr($data,$pos+46,$nlen);
        $local=substr($data,$offset,4);if($local!=="PK\x03\x04")throw new RuntimeException('Unable to read XLSX entry: '.$name);
        $ln=unpack('v',substr($data,$offset+26,2))[1];$le=unpack('v',substr($data,$offset+28,2))[1];$raw=substr($data,$offset+30+$ln+$le,$csize);
        if($comp===0)$content=$raw;elseif($comp===8){$content=@gzinflate($raw);if($content===false)throw new RuntimeException('Unable to decompress XLSX entry: '.$name);}else{throw new RuntimeException('Unsupported XLSX compression method for '.$name.'.');}
        $map[$name]=$content;$pos+=46+$nlen+$elen+$clen;
    }
    return $map;
}
function xlsx_read_file_fallback($path,$name){$entries=xlsx_zip_entries_fallback($path);return $entries[$name]??false;}
function xlsx_unzip_dir($path){
    $tmp=sys_get_temp_dir().'/fams_xlsx_'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Unable to create temporary XLSX workspace.');
    $cmd='';$out=[];$code=1;
    if(function_exists('exec')){
        if(strtoupper(substr(PHP_OS,0,3))==='WIN'){$cmd='powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '.escapeshellarg("Expand-Archive -LiteralPath '".str_replace("'","''",$path)."' -DestinationPath '".str_replace("'","''",$tmp)."' -Force");}
        else{$cmd='unzip -qq -o '.escapeshellarg($path).' -d '.escapeshellarg($tmp).' 2>&1';}
        exec($cmd,$out,$code);
    }
    if($code===0)return $tmp;
    if(function_exists('exec'))@exec((strtoupper(substr(PHP_OS,0,3))==='WIN'?'rmdir /S /Q ':'rm -rf ').escapeshellarg($tmp));
    throw new RuntimeException('Unable to extract XLSX with the server archive tools.');
}
function xlsx_read_xml_file($dir,$rel){$file=$dir.'/'.$rel;return is_file($file)?file_get_contents($file):false;}
function parse_xlsx_sheet($path){
    $sheet=xlsx_read_file_zip($path);$dir=null;
    if($sheet===null){
        try{$dir=xlsx_unzip_dir($path);$wb=xlsx_read_xml_file($dir,'xl/workbook.xml');$rels=xlsx_read_xml_file($dir,'xl/_rels/workbook.xml.rels');$target=xlsx_sheet_target_xml($wb,$rels);$shared=xlsx_shared_strings_xml(xlsx_read_xml_file($dir,'xl/sharedStrings.xml'));$xml=xlsx_read_xml_file($dir,$target);$sheet=xlsx_rows_from_xml($xml,$shared);if(function_exists('exec'))@exec((strtoupper(substr(PHP_OS,0,3))==='WIN'?'rmdir /S /Q ':'rm -rf ').escapeshellarg($dir));}
        catch(Throwable $archiveError){
            $wb=xlsx_read_file_fallback($path,'xl/workbook.xml');$rels=xlsx_read_file_fallback($path,'xl/_rels/workbook.xml.rels');$target=xlsx_sheet_target_xml($wb,$rels);$shared=xlsx_shared_strings_xml(xlsx_read_file_fallback($path,'xl/sharedStrings.xml'));$xml=xlsx_read_file_fallback($path,$target);$sheet=xlsx_rows_from_xml($xml,$shared);
        }
    }
    if(!$sheet)throw new RuntimeException('The XLSX workbook contains no readable rows.');
    $headerIndex=0;$best=-1;foreach($sheet as $i=>$line){$norm=array_map('sheet_clean_header',$line);$score=0;foreach(['DATE_OF_SUBMISSION','DATE_OF_ENDORSEMENT','PATIENT_FIRST_NAME','PATIENT_LAST_NAME','MUNICIPALITY','GUARANTEE_AMOUNT','STATUS'] as $need)if(in_array($need,$norm,true))$score++;if($score>$best){$best=$score;$headerIndex=$i;}}if($best<2)throw new RuntimeException('No FAMS data header row was found. Expected fields such as DATE OF SUBMISSION, PATIENT NAME, MUNICIPALITY, GUARANTEE AMOUNT and STATUS.');$headers=array_map('sheet_clean_header',$sheet[$headerIndex]);$rows=[];foreach(array_slice($sheet,$headerIndex+1) as $offset=>$line){$row=[];foreach($headers as $i=>$h){if($h!=='')$row[$h]=$line[$i]??'';}if(count(array_filter($row,fn($x)=>trim((string)$x)!==''))>0){$row['_source_row']=$headerIndex+2+$offset;$rows[]=$row;}}return $rows;
}
function normalize_sheet_row($r){
    $get=function($aliases)use($r){foreach($aliases as $a){$k=sheet_clean_header($a);if(array_key_exists($k,$r)&&trim((string)$r[$k])!=='')return trim((string)$r[$k]);}return '';};
    $out=[
      'control_number'=>$get(['CONTROL NO','CONTROL NUMBER','CONTROL_NO','CONTROL_NUMBER']),
      'legacy_no'=>(int)sheet_num($get(['NO','NUMBER','LEGACY NO','LEGACY NUMBER'])),
      'date_submission'=>sheet_date($get(['DATE SUBMISSION','DATE OF SUBMISSION','DATE_SUBMISSION','DATE OF ENDORSEMENT','DATE_ENDORSEMENT','ENDORSEMENT DATE','ENDORSEMENT_DATE'])),
      'patient_first_name'=>$get(['FIRST NAME','PATIENT FIRST NAME','PATIENT_FIRST_NAME','PATIENT FIRST']),
      'patient_middle_name'=>$get(['MIDDLE NAME','PATIENT MIDDLE NAME','PATIENT_MIDDLE_NAME']),
      'patient_last_name'=>$get(['LAST NAME','PATIENT LAST NAME','PATIENT_LAST_NAME','PATIENT LAST']),
      'patient_name_extension'=>$get(['EXT','EXT.','EXTENSION','NAME EXTENSION','PATIENT NAME EXTENSION (JR, SR, II...)','PATIENT_NAME_EXTENSION_JR_SR_II']),
      'barangay'=>$get(['BARANGAY','ADDRESS BARANGAY','ADDRESS_BARANGAY']), 'municipality'=>$get(['MUNICIPALITY','CITY/MUNICIPALITY','CITY / MUNICIPALITY','ADDRESS MUNICIPALITY','ADDRESS_MUNICIPALITY']), 'province'=>$get(['PROVINCE','ADDRESS PROVINCE','ADDRESS_PROVINCE']),
      'guardian'=>$get(['GUARDIAN','CLIENT']), 'contact_number'=>$get(['CONTACT','CONTACT NUMBER','CONTACT_NUMBER','MOBILE NUMBER']),
      'bill_amount'=>sheet_num($get(['BILL AMOUNT','BILL_AMOUNT'])), 'guarantee_amount'=>sheet_num($get(['GUARANTEE AMOUNT','GUARANTEE_AMOUNT','GUARANTEE'])),
      'remarks'=>$get(['REMARKS','REMARK']), 'status'=>strtoupper($get(['STATUS'])) ?: 'WAITING', 'release_date'=>sheet_date($get(['RELEASE DATE','RELEASE_DATE']))
    ];
    return fams_uppercase_fields($out,['control_number','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
}

function import_key($v){
    return strtoupper(preg_replace('/[^A-Z0-9]+/','',trim((string)$v)));
}
function import_name_key($n){
    return import_key(trim($n['patient_first_name'].' '.$n['patient_middle_name'].' '.$n['patient_last_name'].' '.$n['patient_name_extension']));
}
function import_money_ok($v){ return is_numeric($v) && (float)$v>=0; }
function import_detail_checks($n,$sourceRow,$pdo,&$seenControl,&$seenLegacy,&$seenIdentity){
    global $activeFacility;
    $messages=[];$blocking=[];$warnings=[];
    $name=trim($n['patient_first_name'].' '.$n['patient_middle_name'].' '.$n['patient_last_name']);
    if(!$n['date_submission'])$blocking[]='Invalid or missing Date of Submission.';
    if($n['patient_first_name']===''||$n['patient_last_name']==='')$blocking[]='First Name and Last Name are required.';
    if($n['municipality']==='')$blocking[]='Municipality/City is required.';
    if($n['province']==='')$warnings[]='Province is blank; ISABELA will be used if this row is added.';
    if(!import_money_ok($n['bill_amount']))$blocking[]='Bill Amount is invalid or negative.';
    if(!import_money_ok($n['guarantee_amount']))$blocking[]='Guarantee Amount is invalid or negative.';
    if($n['guarantee_amount']>$n['bill_amount'] && $n['bill_amount']>0)$warnings[]='Guarantee Amount is greater than Bill Amount; verify the figures.';
    if(!in_array($n['status'],['WAITING','RELEASED','CANCELLED'],true))$blocking[]='Status is invalid.';
    if($n['status']==='RELEASED' && !$n['release_date'])$blocking[]='RELEASED records must have a Release Date.';
    if($n['status']!=='RELEASED' && $n['release_date'])$warnings[]='Release Date is filled while status is '.$n['status'].'; verify this detail.';
    if($n['contact_number']!=='' && !preg_match('/^[0-9+()\-\s]{7,25}$/',$n['contact_number']))$warnings[]='Contact Number format should be checked.';

    $control=trim($n['control_number']);
    if($control!==''){
        if(isset($seenControl[import_key($control)]))$blocking[]='Duplicate Control No. in this uploaded sheet (also row '.$seenControl[import_key($control)].').';
        else $seenControl[import_key($control)]=$sourceRow;
    }
    $legacy=(int)$n['legacy_no'];
    if($legacy>0){
        if(isset($seenLegacy[$legacy]))$blocking[]='Duplicate sheet NO / legacy number in this upload (also row '.$seenLegacy[$legacy].').';
        else $seenLegacy[$legacy]=$sourceRow;
    }
    $identity=import_name_key($n).'|'.$n['date_submission'].'|'.import_key($n['municipality']).'|'.number_format((float)$n['guarantee_amount'],2,'.','');
    if(isset($seenIdentity[$identity]))$blocking[]='Possible duplicate row in this upload (same patient, date, municipality and guarantee amount; also row '.$seenIdentity[$identity].').';
    else $seenIdentity[$identity]=$sourceRow;

    $existing=null;$matchType='NEW';
    if($control!==''){
        $q=$pdo->prepare('SELECT * FROM assistance_records WHERE control_number=? AND facility=? LIMIT 1 FOR UPDATE');$q->execute([$control,$activeFacility]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing)$matchType='UPDATE';
    }
    if(!$existing && $legacy>0){
        $q=$pdo->prepare('SELECT * FROM assistance_records WHERE legacy_no=? AND facility=? ORDER BY id LIMIT 1 FOR UPDATE');$q->execute([$legacy,$activeFacility]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing)$matchType='UPDATE';
    }
    if($existing){
        if($control!=='' && $legacy>0 && (int)$existing['legacy_no']!==$legacy)$warnings[]='Control No. matches an existing record, but the sheet NO / legacy number differs.';
        if($control!=='' && (int)$existing['id']!==0){
            $q=$pdo->prepare('SELECT COUNT(*) FROM assistance_records WHERE legacy_no=? AND facility=? AND id<>?');$q->execute([$legacy,$activeFacility,(int)$existing['id']]);
            if($legacy>0 && (int)$q->fetchColumn()>0)$blocking[]='The sheet NO / legacy number belongs to another existing FAMS record.';
        }
    }else{
        // A strong existing-record duplicate check: same patient + date + municipality + guarantee amount.
        $q=$pdo->prepare("SELECT id,control_number,legacy_no FROM assistance_records WHERE facility=? AND date_submission=? AND municipality=? AND patient_first_name=? AND patient_last_name=? AND guarantee_amount=? LIMIT 5");
        $q->execute([$activeFacility,$n['date_submission'],$n['municipality'],$n['patient_first_name'],$n['patient_last_name'],number_format((float)$n['guarantee_amount'],2,'.','')]);
        $dupes=$q->fetchAll(PDO::FETCH_ASSOC);
        if($dupes){$refs=array_map(fn($d)=>($d['control_number']?:('#'.$d['id'])), $dupes);$blocking[]='Possible existing duplicate: same patient, date, municipality and guarantee amount ('.implode(', ',$refs).').';}
    }
    if($blocking)$messages=array_merge($messages,$blocking);
    if($warnings)$messages=array_merge($messages,$warnings);
    return ['existing'=>$existing,'match_type'=>$matchType,'blocking'=>$blocking,'warnings'=>$warnings,'messages'=>$messages];
}
function next_import_control_map($pdo,$rows){
    global $activeFacility;
    $next=[]; $q=$pdo->prepare("SELECT YEAR(date_submission) y, COALESCE(MAX(CAST(SUBSTRING_INDEX(control_number,'-',-1) AS UNSIGNED)),0) mx FROM assistance_records WHERE facility=? AND control_number REGEXP '^[0-9]{4}-[0-9]+$' AND LEFT(control_number,4)=CAST(YEAR(date_submission) AS CHAR) GROUP BY YEAR(date_submission)");
    $q->execute([$activeFacility]);
    foreach($q as $r) $next[(int)$r['y']]=(int)$r['mx'];
    foreach($rows as $i=>&$r){
        if(($r['match_type']??'NEW')!=='NEW'){$r['proposed_control']=$r['existing_control']??$r['control_number'];continue;}
        if(($r['check_status']??'BLOCKED')==='BLOCKED') continue;
        $sourceControl=trim((string)($r['control_number']??''));
        if($sourceControl!=='' && preg_match('/^(\d{4})-(\d{1,})$/',$sourceControl,$m)){
            $sourceYear=(int)$m[1]; $sourceSeq=(int)$m[2];
            $q=$pdo->prepare('SELECT COUNT(*) FROM assistance_records WHERE facility=? AND control_number=?');
            $q->execute([$activeFacility,$sourceControl]);
            if((int)$q->fetchColumn()===0){$r['proposed_control']=$sourceControl;$next[$sourceYear]=max((int)($next[$sourceYear]??0),$sourceSeq);continue;}
        }
        $year=(int)date('Y',strtotime($r['date_submission'])); $next[$year]=($next[$year]??0)+1; $r['proposed_control']=$year.'-'.str_pad((string)$next[$year],4,'0',STR_PAD_LEFT);
    }
    unset($r); return $rows;
}

$action=$_POST['action']??''; $rows=[]; $errors=[]; $sourceName=''; $checkStats=['ready'=>0,'update'=>0,'warning'=>0,'blocked'=>0];
$importDraft = $_SESSION['fams_import_draft'] ?? null;
$importIdempotencyKey=(string)($_SESSION['fams_import_idempotency_key']??'');
if($importIdempotencyKey===''){ $importIdempotencyKey=fams_shared_idempotency_key(); $_SESSION['fams_import_idempotency_key']=$importIdempotencyKey; }

// Keep the current import table in the session when an Edit/Delete operation
// refreshes the page. This prevents the temporary upload rows from disappearing
// before Recheck or Apply is pressed.
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='save_draft') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        verify_csrf();
        $payload=json_decode($_POST['payload']??'[]',true);
        $sourceName=trim((string)($_POST['source_name']??'uploaded sheet'));
        if(!is_array($payload)) throw new RuntimeException('Invalid import draft payload.');
        $_SESSION['fams_import_draft']=['rows'=>$payload,'source_name'=>$sourceName];$_SESSION['fams_import_idempotency_key']=fams_shared_idempotency_key();$importIdempotencyKey=$_SESSION['fams_import_idempotency_key'];
        echo json_encode(['ok'=>true,'count'=>count($payload)]);
    } catch(Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='preview'){
    verify_csrf();
    if(empty($_FILES['sheet']['tmp_name']) || ($_FILES['sheet']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($_FILES['sheet']['tmp_name'])){$errors[]='Please choose a valid uploaded CSV or XLSX sheet file.';}elseif((int)($_FILES['sheet']['size']??0)<1||(int)$_FILES['sheet']['size']>20*1024*1024){$errors[]='The uploaded sheet must be between 1 byte and 20 MB.';}else{
      $sourceName=basename((string)$_FILES['sheet']['name']); $ext=strtolower(pathinfo($sourceName,PATHINFO_EXTENSION));if(!in_array($ext,['csv','xlsx'],true)){$errors[]='Supported formats are CSV and XLSX.';}elseif($ext==='xlsx'&&file_get_contents($_FILES['sheet']['tmp_name'],false,null,0,2)!=="PK"){$errors[]='The XLSX file signature is invalid.';}elseif($ext==='csv'&&str_contains((string)file_get_contents($_FILES['sheet']['tmp_name'],false,null,0,4096),"\0")){$errors[]='The CSV file contains binary data.';}else{
      try{
        $raw=$ext==='csv'?parse_csv_sheet($_FILES['sheet']['tmp_name']):($ext==='xlsx'?parse_xlsx_sheet($_FILES['sheet']['tmp_name']):throw new RuntimeException('Supported formats are CSV and XLSX.'));
        $seenControl=[];$seenLegacy=[];$seenIdentity=[];
        foreach($raw as $rawIndex=>$r){
            $sourceRow=(int)($r['_source_row']??($rawIndex+2));$n=normalize_sheet_row($r);$n['source_row']=$sourceRow;
            $n['check_status']='BLOCKED';$n['match_type']='NEW';$n['proposed_control']='';$n['check_messages']=[];
            if(!$n['date_submission']||$n['patient_first_name']===''||$n['patient_last_name']===''||$n['municipality']===''){ $missing=[]; if(!$n['date_submission'])$missing[]='Date of Submission / Date of Endorsement'; if($n['patient_first_name']==='')$missing[]='First Name'; if($n['patient_last_name']==='')$missing[]='Last Name'; if($n['municipality']==='')$missing[]='Municipality/City'; $n['check_messages'][]='Required detail missing: '.implode(', ',$missing).'.'; $rows[]=$n;continue;}
            if(!in_array($n['status'],['WAITING','RELEASED','CANCELLED'],true)){$n['status']='WAITING';$n['check_messages'][]='Invalid status was changed to WAITING for preview; verify the source sheet.';}
            $c=import_detail_checks($n,$sourceRow,$pdo,$seenControl,$seenLegacy,$seenIdentity);
            $n['match_type']=$c['match_type'];$n['existing_control']=$c['existing']['control_number']??'';$n['existing_record_id']=(int)($c['existing']['id']??0);$n['existing_record_version']=(int)($c['existing']['record_version']??0);$n['check_messages']=array_merge($n['check_messages'],$c['messages']);
            $n['check_status']=$c['blocking']?'BLOCKED':($c['match_type']==='UPDATE'?'UPDATE':($c['warnings']?'READY • CHECK':'READY'));
            $rows[]=$n;
        }
        $rows=next_import_control_map($pdo,$rows);
        foreach($rows as $r){if($r['check_status']==='BLOCKED')$checkStats['blocked']++;elseif($r['check_status']==='UPDATE')$checkStats['update']++;elseif($r['check_status']==='READY • CHECK')$checkStats['warning']++;else $checkStats['ready']++;}
      }catch(Throwable $e){$errors[]=$e->getMessage();}
      }
    }
    if($rows){ $_SESSION['fams_import_draft']=['rows'=>$rows,'source_name'=>$sourceName];$_SESSION['fams_import_idempotency_key']=fams_shared_idempotency_key();$importIdempotencyKey=$_SESSION['fams_import_idempotency_key']; }
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='recheck'){
    verify_csrf();
    $payload=json_decode($_POST['payload']??'[]',true);
    $sourceName=trim($_POST['source_name']??'uploaded sheet');
    $checkedRows=[];
    $resultRows=[];
    if(!is_array($payload) || !$payload){$errors[]='There are no table entries to recheck.';}
    else{
      try{
        $seenControl=[];$seenLegacy=[];$seenIdentity=[];
        foreach($payload as $rawIndex=>$r){
          if(!is_array($r)) continue;
          // Keep the submitted table values untouched. A normalized copy is used
          // only for validation so Recheck never silently changes the table data.
          $original=$r;
          $sourceRow=(int)($r['source_row']??$r['_source_row']??($rawIndex+2));
          // The import table stores rows in FAMS' canonical field names after the
          // initial upload (for example date_submission, patient_first_name, etc.).
          // Re-normalizing those canonical rows through normalize_sheet_row() would
          // look for spreadsheet headers again and turn every required field into blank.
          // Use the canonical values directly when they are already present; only
          // normalize raw spreadsheet-shaped payloads as a fallback.
          $canonicalKeys=['control_number','legacy_no','date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','bill_amount','guarantee_amount','remarks','status','release_date'];
          $hasCanonical=false; foreach($canonicalKeys as $ck){if(array_key_exists($ck,$r)){ $hasCanonical=true; break; }}
          if($hasCanonical){
            $n=[];
            foreach($canonicalKeys as $ck){$n[$ck]=$r[$ck]??'';}
            $n['legacy_no']=(int)$n['legacy_no'];
            $n['bill_amount']=sheet_num($n['bill_amount']);
            $n['guarantee_amount']=sheet_num($n['guarantee_amount']);
            $n['date_submission']=sheet_date($n['date_submission']);
            $n['release_date']=sheet_date($n['release_date']);
            $n['status']=strtoupper(trim((string)$n['status'])) ?: 'WAITING';
            foreach(['control_number','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'] as $ck){$n[$ck]=trim((string)$n[$ck]);}
          } else {
            $n=normalize_sheet_row($r);
          }
          $n['source_row']=$sourceRow;
          $messages=[];$status='BLOCKED';$matchType='NEW';$existingControl='';$c=['existing'=>null];$proposedControl=(string)($original['proposed_control']??'');
          if(!$n['date_submission']||$n['patient_first_name']===''||$n['patient_last_name']===''||$n['municipality']===''){
            $missing=[];if(!$n['date_submission'])$missing[]='Date of Submission / Date of Endorsement';if($n['patient_first_name']==='')$missing[]='First Name';if($n['patient_last_name']==='')$missing[]='Last Name';if($n['municipality']==='')$missing[]='Municipality/City';
            $messages[]='Required detail missing: '.implode(', ',$missing).'.';
          } else {
            if(!in_array($n['status'],['WAITING','RELEASED','CANCELLED'],true)) $messages[]='Invalid status: '.($n['status']?:'blank').'.';
            $c=import_detail_checks($n,$sourceRow,$pdo,$seenControl,$seenLegacy,$seenIdentity);
            $matchType=$c['match_type'];$existingControl=$c['existing']['control_number']??'';$messages=array_merge($messages,$c['messages']);
            $status=$c['blocking']?'BLOCKED':($c['match_type']==='UPDATE'?'UPDATE':($c['warnings']?'READY • CHECK':'READY'));
          }
          $checkedRows[]=$original;
          $resultRows[]=['index'=>(int)$rawIndex,'source_row'=>$sourceRow,'check_status'=>$status,'match_type'=>$matchType,'existing_control'=>$existingControl,'existing_record_id'=>(int)($c['existing']['id']??0),'existing_record_version'=>(int)($c['existing']['record_version']??0),'proposed_control'=>$proposedControl,'check_messages'=>$messages];
          if($status==='BLOCKED')$checkStats['blocked']++;elseif($status==='UPDATE')$checkStats['update']++;elseif($status==='READY • CHECK')$checkStats['warning']++;else $checkStats['ready']++;
        }
        // Persist the exact current table contents together with only the new checker results.
        foreach($resultRows as $result){
          $i=$result['index'];
          if(!isset($checkedRows[$i])) continue;
          $checkedRows[$i]['check_status']=$result['check_status'];
          $checkedRows[$i]['match_type']=$result['match_type'];
          $checkedRows[$i]['existing_control']=$result['existing_control'];
          $checkedRows[$i]['existing_record_id']=$result['existing_record_id'];
          $checkedRows[$i]['existing_record_version']=$result['existing_record_version'];
          $checkedRows[$i]['check_messages']=$result['check_messages'];
        }
        $_SESSION['fams_import_draft']=['rows'=>$checkedRows,'source_name'=>$sourceName];$_SESSION['fams_import_idempotency_key']=fams_shared_idempotency_key();$importIdempotencyKey=$_SESSION['fams_import_idempotency_key'];
      }catch(Throwable $e){$errors[]=$e->getMessage();}
    }
    if(!empty($_POST['ajax'])){
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>empty($errors),'errors'=>$errors,'results'=>$resultRows,'source_name'=>$sourceName,'stats'=>$checkStats],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if($_SERVER['REQUEST_METHOD']!=='POST' && is_array($importDraft) && !empty($importDraft['rows'])){
    $rows=$importDraft['rows']; $sourceName=(string)($importDraft['source_name']??'uploaded sheet');
    foreach($rows as $r){if(($r['check_status']??'')==='BLOCKED')$checkStats['blocked']++;elseif(($r['check_status']??'')==='UPDATE')$checkStats['update']++;elseif(($r['check_status']??'')==='READY • CHECK')$checkStats['warning']++;else $checkStats['ready']++;}
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='apply'){
    verify_csrf(); $data=json_decode($_POST['payload']??'[]',true); $sourceName=trim($_POST['source_name']??'uploaded sheet');
    if(!is_array($data)||!$data){flash('error','No valid rows were supplied for import.');redirect('import_sheet.php');}
    // Safety rule: create a complete database backup BEFORE any import/merge changes begin.
    // If the backup cannot be created, do not import anything.
    $backupFile='';$sequenceLockName='fams:maifip:'.substr(hash('sha256',$activeFacility),0,48);$sequenceLockAcquired=false;
    try{
      $backupFile=backup_database_before_import($pdo);
      $sequenceLock=$pdo->prepare('SELECT GET_LOCK(?,8)');$sequenceLock->execute([$sequenceLockName]);if((int)$sequenceLock->fetchColumn()!==1)throw new RuntimeException('The facility control-number sequence is busy. Please retry.');$sequenceLockAcquired=true;
      fams_shared_ensure_idempotency_schema($pdo);
      $importResult=fams_shared_transaction($pdo,function(PDO $db) use($data,$sourceName,$user,$activeFacility,$backupFile){
      $pdo=$db;
      if(!fams_shared_idempotency_claim($pdo,'maifip.import.apply',(string)($_POST['idempotency_key']??''),(int)$user['id'],$_POST))return ['duplicate'=>true];
      $added=0;$updated=0;$skipped=0;$blocked=0;
      $seenControl=[];$seenLegacy=[];$seenIdentity=[];
      foreach($data as $row){
        if(!is_array($row)){$skipped++;continue;}
        $sourceRow=(int)($row['source_row']??$row['_source_row']??0);
        $proposedControl=trim((string)($row['proposed_control']??''));
        // Apply the exact canonical import-table fields when the current table
        // already contains normalized FAMS fields. Re-normalizing these rows as
        // spreadsheet headers would blank the required fields and cause every
        // valid row to be reported as skipped.
        $canonicalKeys=['control_number','legacy_no','date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','bill_amount','guarantee_amount','remarks','status','release_date'];
        $hasCanonical=false;
        foreach($canonicalKeys as $ck){if(array_key_exists($ck,$row)){$hasCanonical=true;break;}}
        if($hasCanonical){
          $n=[];
          foreach($canonicalKeys as $ck){$n[$ck]=$row[$ck]??'';}
          $n['legacy_no']=(int)$n['legacy_no'];
          $n['bill_amount']=sheet_num($n['bill_amount']);
          $n['guarantee_amount']=sheet_num($n['guarantee_amount']);
          $n['date_submission']=sheet_date($n['date_submission']);
          $n['release_date']=sheet_date($n['release_date']);
          $n['status']=strtoupper(trim((string)$n['status'])) ?: 'WAITING';
          foreach(['control_number','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'] as $ck){$n[$ck]=trim((string)$n[$ck]);}
        } else {
          $n=normalize_sheet_row($row);
        }
        $n['source_row']=$sourceRow;
        $n['proposed_control']=$proposedControl;
        if(!$n['date_submission']||$n['patient_first_name']===''||$n['patient_last_name']===''||$n['municipality']===''){$skipped++;continue;}
        if(!in_array($n['status'],['WAITING','RELEASED','CANCELLED'],true))throw new RuntimeException('Import stopped: invalid status detected during final checking.');
        // Imports are not a status-transition API. Non-admin users may only
        // create/update WAITING rows; release/cancellation follows its own workflow.
        if(($user['role']??'')!=='admin' && $n['status']!=='WAITING'){
          throw new RuntimeException('Import stopped: non-administrator imports may only create or retain WAITING records. Use the normal status/ticket workflow for finalized statuses.');
        }
        if($n['status']==='RELEASED' && !$n['release_date'])throw new RuntimeException('Import stopped: a RELEASED row is missing its Release Date.');
        if(!import_money_ok($n['bill_amount'])||!import_money_ok($n['guarantee_amount']))throw new RuntimeException('Import stopped: a row contains an invalid or negative amount.');
        $c=import_detail_checks($n,$sourceRow,$pdo,$seenControl,$seenLegacy,$seenIdentity);
        if($c['blocking']){$blocked++;continue;}
        $existing=$c['existing'];$previewExistingId=(int)($row['existing_record_id']??0);$previewExistingVersion=(int)($row['existing_record_version']??0);$currentExistingId=(int)($existing['id']??0);$currentExistingVersion=(int)($existing['record_version']??0);
        if($previewExistingId!==$currentExistingId || ($previewExistingId>0 && $previewExistingVersion!==$currentExistingVersion))throw new RuntimeException('The MAIFIP record match or version changed after the import preview. No import was applied; recheck the sheet and try again.');
        if($existing){if(!can_edit_existing_record($existing))throw new RuntimeException('Import stopped: record '.$existing['control_number'].' is locked for this account/status.');$expectedVersion=(int)($existing['record_version']??1);if($expectedVersion<1)throw new RuntimeException('The existing MAIFIP record has an invalid version. Reload the import and try again.');$oldStatus=$existing['status'];$update=$pdo->prepare('UPDATE assistance_records SET date_submission=?,patient_first_name=?,patient_middle_name=?,patient_last_name=?,patient_name_extension=?,barangay=?,municipality=?,province=?,guardian=?,contact_number=?,bill_amount=?,guarantee_amount=?,remarks=?,status=?,release_date=?,updated_by=?,record_version=record_version+1 WHERE id=? AND facility=? AND record_version=?');$update->execute([$n['date_submission'],$n['patient_first_name'],$n['patient_middle_name'],$n['patient_last_name'],$n['patient_name_extension'],$n['barangay'],$n['municipality'],$n['province']?:'ISABELA',$n['guardian'],$n['contact_number'],$n['bill_amount'],$n['guarantee_amount'],$n['remarks'],$n['status'],$n['release_date'],(int)$user['id'],(int)$existing['id'],$activeFacility,$expectedVersion]);if($update->rowCount()!==1)throw new RuntimeException('The MAIFIP record changed during import. No overwrite was applied; refresh the sheet and run the checker again.');if($oldStatus!==$n['status']){fams_status_history_write($pdo,'maifip',(int)$existing['id'],$oldStatus,$n['status'],(int)$user['id'],'Updated by sheet import.');}$updated++;
        }else{$year=(int)date('Y',strtotime($n['date_submission']));$requestedControl=trim((string)($n['proposed_control']??$n['control_number']??''));$control='';if($requestedControl!==''&&preg_match('/^\d{4}-\d{1,}$/',$requestedControl)){$control=$requestedControl;}$s=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(control_number,'-',-1) AS UNSIGNED)) FROM assistance_records WHERE facility=? AND control_number REGEXP '^[0-9]{4}-[0-9]+$' AND LEFT(control_number,4)=?");$s->execute([$activeFacility,(string)$year]);$seq=(int)$s->fetchColumn();if($control===''){$control=$year.'-'.str_pad((string)($seq+1),4,'0',STR_PAD_LEFT);}$collision=$pdo->prepare('SELECT COUNT(*) FROM assistance_records WHERE facility=? AND control_number=?');$collision->execute([$activeFacility,$control]);if((int)$collision->fetchColumn()>0)throw new RuntimeException('Import stopped: Control No. '.$control.' is already used by the active facility.');$pdo->prepare('INSERT INTO assistance_records(facility,control_number,date_submission,patient_first_name,patient_middle_name,patient_last_name,patient_name_extension,legacy_no,barangay,municipality,province,guardian,contact_number,bill_amount,guarantee_amount,remarks,status,release_date,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$activeFacility,$control,$n['date_submission'],$n['patient_first_name'],$n['patient_middle_name'],$n['patient_last_name'],$n['patient_name_extension'],(!empty($n['legacy_no'])?(int)$n['legacy_no']:null),$n['barangay'],$n['municipality'],$n['province']?:'ISABELA',$n['guardian'],$n['contact_number'],$n['bill_amount'],$n['guarantee_amount'],$n['remarks'],$n['status'],$n['release_date'],(int)$user['id'],(int)$user['id']]);$id=(int)$pdo->lastInsertId();fams_status_history_write($pdo,'maifip',$id,null,$n['status'],(int)$user['id'],'Created by sheet import.');$added++;}
      }
      if($blocked>0)throw new RuntimeException("Import stopped: {$blocked} row(s) failed the final duplicate/detail checker. No changes were applied.");
      log_action($pdo,'IMPORT_SHEET',"Imported {$sourceName}: {$added} added, {$updated} updated, {$skipped} skipped. Backup: ".basename($backupFile));
      return ['duplicate'=>false,'added'=>$added,'updated'=>$updated,'skipped'=>$skipped];
      });
      $releaseLock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$releaseLock->execute([$sequenceLockName]);$sequenceLockAcquired=false;
      if(!empty($importResult['duplicate'])){unset($_SESSION['fams_import_draft'],$_SESSION['fams_import_idempotency_key']);flash('success','This sheet import was already applied. No duplicate import was performed.');redirect('records.php');}
      unset($_SESSION['fams_import_draft'],$_SESSION['fams_import_idempotency_key']);flash('success',"Sheet import complete: {$importResult['added']} added, {$importResult['updated']} updated, {$importResult['skipped']} skipped. Database backup created BEFORE import: ".basename($backupFile));redirect('records.php');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($sequenceLockAcquired){$releaseLock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$releaseLock->execute([$sequenceLockName]);}$sequenceLockAcquired=false;unset($_SESSION['fams_import_draft']);flash('error','Import not completed: '.$e->getMessage());redirect('import_sheet.php');}
}
$pageTitle='Import / Update FAMS Sheet'; require __DIR__.'/partials/header.php';
?>
<div class="panel import-sheet-panel">
  <div class="panel-heading import-page-heading">
    <div><span class="section-kicker">SHEET IMPORT • ACTIVE FACILITY: <?=e(facility_label($activeFacility))?></span><h3>Import / Update FAMS Records</h3><p class="muted">Upload a Google Sheets export as <strong>.xlsx</strong> or <strong>.csv</strong>. The checker reviews numbering, required details, amounts, status/release-date consistency, duplicate rows, and matches against existing FAMS records before anything is applied.</p></div>
    <a class="ghost-btn import-back-btn" href="records.php">← Back to FAMS</a>
  </div>
  <div class="import-checker-rules">
    <div><strong>1. Numbering</strong><span>Shows the source sheet row, existing Control No., and the next Control No. proposed for new records.</span></div>
    <div><strong>2. Detail Check</strong><span>Checks required names/date/location, amounts, status, release date, and contact format.</span></div>
    <div><strong>3. Duplicate Check</strong><span>Checks duplicates inside the upload and possible matches already stored in FAMS.</span></div>
  </div>
  <form method="post" enctype="multipart/form-data" class="sheet-upload-form">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="preview">
    <label>Current Google Sheets file</label><input type="file" name="sheet" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
    <button class="primary-btn" type="submit">Run Careful Checker</button>
  </form>
  <?php foreach($errors as $er):?><div class="alert alert-danger"><?=e($er)?></div><?php endforeach;?>
  <?php if($rows):?>
    <div class="import-check-summary" id="importCheckSummary">
      <div><strong><?=count($rows)?></strong><span>Rows checked</span></div><div class="ok"><strong><?=$checkStats['ready']?></strong><span>Ready</span></div><div class="update"><strong><?=$checkStats['update']?></strong><span>Updates</span></div><div class="warn"><strong><?=$checkStats['warning']?></strong><span>Check warnings</span></div><div class="bad"><strong><?=$checkStats['blocked']?></strong><span>Blocked</span></div>
    </div>
    <div class="import-preview">
      <div class="import-preview-head" id="importPreviewHead"><strong><?=count($rows)?> row(s) checked</strong><span><?=e($sourceName)?></span></div>
      <div class="table-wrap import-check-table"><table class="fams-data-list-table"><thead><tr><th>Sheet Row</th><th>Existing / Proposed Control No.</th><th>Date</th><th>Beneficiary</th><th>Location</th><th>Bill</th><th>Guarantee</th><th>Status</th><th>Checker Result</th><th>Details / Duplicate Check</th><th>Actions</th></tr></thead><tbody id="importCheckBody">
      <?php foreach($rows as $rowIndex=>$r):?>
        <?php $resultClass=$r['check_status']==='BLOCKED'?'bad':($r['check_status']==='UPDATE'?'update':($r['check_status']==='READY • CHECK'?'warn':'ok')); ?>
        <tr class="import-row-<?=$resultClass?>">
          <td><strong>#<?=e($r['source_row'])?></strong></td>
          <td><strong><?=e($r['control_number']?:($r['existing_control']??'NEW'))?></strong><?php if(($r['match_type']??'NEW')==='NEW'):?><br><small>→ <?=e($r['proposed_control']?:'—')?></small><?php else:?><br><small>Existing record</small><?php endif;?></td>
          <td><?=e($r['date_submission']?:'—')?></td><td><?=e(trim($r['patient_first_name'].' '.$r['patient_middle_name'].' '.$r['patient_last_name']))?></td><td><?=e(trim($r['barangay'].', '.$r['municipality'].', '.$r['province']))?></td><td>₱<?=number_format((float)$r['bill_amount'],2)?></td><td>₱<?=number_format((float)$r['guarantee_amount'],2)?></td><td><?=e($r['status'])?><?=($r['release_date']?' / '.e($r['release_date']):'')?></td>
          <td><span class="import-check-badge <?=$resultClass?>"><?=e($r['check_status'])?></span></td>
          <td><?php if($r['check_messages']):?><ul class="import-check-list"><?php foreach($r['check_messages'] as $m):?><li><?=e($m)?></li><?php endforeach;?></ul><?php else:?><span class="muted">All checks passed.</span><?php endif;?></td>
          <td class="import-row-actions"><button type="button" class="table-action import-edit-btn" data-row-index="<?=$rowIndex?>">Edit</button><button type="button" class="table-action danger import-delete-btn" data-row-index="<?=$rowIndex?>">Delete</button></td>
        </tr>
      <?php endforeach;?></tbody></table></div>
      <?php $hasBlocked=$checkStats['blocked']>0; ?>
      <form method="post" class="import-apply-form" id="importRecheckForm">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="recheck"><input type="hidden" name="source_name" value="<?=e($sourceName)?>"><textarea name="payload" id="importRecheckPayload" hidden><?=e(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></textarea>
        <button class="secondary-btn" type="submit" id="importRecheckButton">↻ Recheck</button>
        <?php if($hasBlocked): ?><button class="danger-btn" type="button" id="importDeleteBlockedButton">Delete All Blocked</button><?php endif; ?>
      </form>
      <form method="post" class="import-apply-form">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="apply"><input type="hidden" name="idempotency_key" value="<?=e($importIdempotencyKey)?>"><input type="hidden" name="source_name" value="<?=e($sourceName)?>"><textarea name="payload" id="importApplyPayload" hidden><?=e(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></textarea>
        <button class="primary-btn" type="submit" id="importApplyButton" <?=$hasBlocked?'disabled title="Fix blocked rows and run the checker again before applying."':''?>><?= $hasBlocked ? 'Fix Blocked Rows Before Applying' : 'Apply Checked Sheet Updates' ?></button>
        <a class="ghost-btn" href="records.php">Cancel</a>
      </form>
    </div>
  <?php endif;?>
</div>
<?php if($rows): ?>
<div class="fams-shared-dialog-backdrop import-edit-modal" id="importEditModal" hidden>
  <section class="fams-shared-dialog import-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="importEditTitle">
    <div class="fams-shared-dialog-head"><strong class="fams-shared-dialog-title" id="importEditTitle">Edit Import Entry</strong><button type="button" class="fams-shared-dialog-close" id="importEditClose" aria-label="Close">×</button></div>
    <form id="importEditForm" class="import-edit-form">
      <div class="import-edit-grid">
        <label>Control No.<input name="control_number"></label><label>Sheet NO / Legacy No.<input name="legacy_no" type="number" min="0"></label>
        <label>Date of Submission / Endorsement<input name="date_submission" type="date"></label><label>Status<select name="status"><option>WAITING</option><option>RELEASED</option><option>CANCELLED</option></select></label>
        <label>First Name<input name="patient_first_name" required></label><label>Middle Name<input name="patient_middle_name"></label><label>Last Name<input name="patient_last_name" required></label><label>Name Extension<input name="patient_name_extension"></label>
        <label>Barangay<input name="barangay"></label><label>Municipality / City<input name="municipality" required></label><label>Province<input name="province"></label><label>Client<input name="guardian"></label>
        <label>Contact Number<input name="contact_number"></label><label>Bill Amount<input name="bill_amount" type="number" step="0.01" min="0"></label><label>Guarantee Amount<input name="guarantee_amount" type="number" step="0.01" min="0"></label><label>Release Date<input name="release_date" type="date"></label>
        <label class="import-edit-wide">Remarks<textarea name="remarks" rows="3"></textarea></label>
      </div>
      <div class="import-edit-note">Edited entries are checked automatically when saved. Use <strong>Recheck</strong> to run the checks again for the current table.</div>
      <div class="import-edit-actions"><button type="button" class="ghost-btn" id="importEditCancel">Cancel</button><button type="submit" class="primary-btn">Save Entry to Table</button></div>
    </form>
  </section>
</div>
<script>
(function(){
const modal=document.getElementById('importEditModal'),form=document.getElementById('importEditForm'),close=document.getElementById('importEditClose'),cancel=document.getElementById('importEditCancel');
const recheckForm=document.getElementById('importRecheckForm'),recheckPayload=document.getElementById('importRecheckPayload'),applyPayload=document.getElementById('importApplyPayload');
const deleteBlockedButton=document.getElementById('importDeleteBlockedButton');
const summary=document.getElementById('importCheckSummary'),body=document.getElementById('importCheckBody'),previewHead=document.getElementById('importPreviewHead'),applyButton=document.getElementById('importApplyButton'),recheckButton=document.getElementById('importRecheckButton');
if(!modal||!form||!recheckForm||!recheckPayload||!applyPayload||!body)return;
let rows=[];try{rows=JSON.parse(recheckPayload.value||'[]')}catch(e){rows=[]}let editingIndex=-1;
const names=['control_number','legacy_no','date_submission','status','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','bill_amount','guarantee_amount','release_date','remarks'];
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const money=v=>Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
function stats(){const o={ready:0,update:0,warning:0,blocked:0};rows.forEach(r=>{if(r.check_status==='BLOCKED')o.blocked++;else if(r.check_status==='UPDATE')o.update++;else if(r.check_status==='READY • CHECK')o.warning++;else o.ready++});return o}
function sync(){const j=JSON.stringify(rows);recheckPayload.value=j;applyPayload.value=j;return j}
function render(){
const st=stats();
summary.innerHTML='<div><strong>'+rows.length+'</strong><span>Rows checked</span></div><div class="ok"><strong>'+st.ready+'</strong><span>Ready</span></div><div class="update"><strong>'+st.update+'</strong><span>Updates</span></div><div class="warn"><strong>'+st.warning+'</strong><span>Check warnings</span></div><div class="bad"><strong>'+st.blocked+'</strong><span>Blocked</span></div>';
previewHead.innerHTML='<strong>'+rows.length+' row(s) checked</strong><span>'+esc(document.querySelector('#importRecheckForm input[name="source_name"]')?.value||'uploaded sheet')+'</span>';
body.innerHTML=rows.map((r,i)=>{const c=r.check_status==='BLOCKED'?'bad':r.check_status==='UPDATE'?'update':r.check_status==='READY • CHECK'?'warn':'ok';const control=r.control_number||r.existing_control||'NEW';const proposed=(r.match_type||'NEW')==='NEW'?'<br><small>→ '+esc(r.proposed_control||'—')+'</small>':'<br><small>Existing record</small>';const msgs=Array.isArray(r.check_messages)?r.check_messages:[];const detail=msgs.length?'<ul class="import-check-list">'+msgs.map(m=>'<li>'+esc(m)+'</li>').join('')+'</ul>':'<span class="muted">All checks passed.</span>';return '<tr class="import-row-'+c+'"><td><strong>#'+esc(r.source_row)+'</strong></td><td><strong>'+esc(control)+'</strong>'+proposed+'</td><td>'+esc(r.date_submission||'—')+'</td><td>'+esc([r.patient_first_name,r.patient_middle_name,r.patient_last_name].filter(Boolean).join(' '))+'</td><td>'+esc([r.barangay,r.municipality,r.province].filter(Boolean).join(', '))+'</td><td>₱'+money(r.bill_amount)+'</td><td>₱'+money(r.guarantee_amount)+'</td><td>'+esc(r.status||'')+(r.release_date?' / '+esc(r.release_date):'')+'</td><td><span class="import-check-badge '+c+'">'+esc(r.check_status||'')+'</span></td><td>'+detail+'</td><td class="import-row-actions"><button type="button" class="table-action import-edit-btn" data-row-index="'+i+'">Edit</button> <button type="button" class="table-action danger import-delete-btn" data-row-index="'+i+'">Delete</button></td></tr>'}).join('');
applyButton.disabled=rows.length===0||st.blocked>0;applyButton.title=st.blocked>0?'Fix blocked rows and run the checker again before applying.':'';applyButton.textContent=st.blocked>0?'Fix Blocked Rows Before Applying':'Apply Checked Sheet Updates';sync();bind();
}
async function persist(){const d=new URLSearchParams();d.set('csrf_token',document.querySelector('input[name="csrf_token"]')?.value||'');d.set('action','save_draft');d.set('source_name',document.querySelector('#importRecheckForm input[name="source_name"]')?.value||'uploaded sheet');d.set('payload',sync());const r=await fetch('import_sheet.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d});const j=await r.json().catch(()=>({ok:false,error:'Unable to save the import draft.'}));if(!r.ok||!j.ok)throw new Error(j.error||'Unable to save the import draft.');}
function updateCheckDisplay(results){
const byIndex=new Map((Array.isArray(results)?results:[]).map(x=>[Number(x.index),x]));
body.querySelectorAll('tr').forEach((tr,i)=>{
  const r=byIndex.get(i); if(!r)return;
  const c=r.check_status==='BLOCKED'?'bad':r.check_status==='UPDATE'?'update':r.check_status==='READY • CHECK'?'warn':'ok';
  tr.className='import-row-'+c;
  const badge=tr.querySelector('.import-check-badge'); if(badge){badge.className='import-check-badge '+c;badge.textContent=r.check_status||'';}
  const detailCell=tr.children[9];
  if(detailCell){const msgs=Array.isArray(r.check_messages)?r.check_messages:[];detailCell.innerHTML=msgs.length?'<ul class="import-check-list">'+msgs.map(m=>'<li>'+esc(m)+'</li>').join('')+'</ul>':'<span class="muted">All checks passed.</span>';}
});
const st=stats();
summary.innerHTML='<div><strong>'+rows.length+'</strong><span>Rows checked</span></div><div class="ok"><strong>'+st.ready+'</strong><span>Ready</span></div><div class="update"><strong>'+st.update+'</strong><span>Updates</span></div><div class="warn"><strong>'+st.warning+'</strong><span>Check warnings</span></div><div class="bad"><strong>'+st.blocked+'</strong><span>Blocked</span></div>';
applyButton.disabled=rows.length===0||st.blocked>0;applyButton.title=st.blocked>0?'Fix blocked rows and run the checker again before applying.':'';applyButton.textContent=st.blocked>0?'Fix Blocked Rows Before Applying':'Apply Checked Sheet Updates';
sync();
}
async function runRecheck(){
if(!rows.length){await persist();return}
const d=new URLSearchParams(new FormData(recheckForm));d.set('ajax','1');d.set('payload',sync());
const r=await fetch('import_sheet.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d});
const j=await r.json().catch(()=>({ok:false,errors:['The checker returned an invalid response.']}));
if(!r.ok||!j.ok)throw new Error((j.errors||[]).join('\n')||'Unable to recheck the current table.');
const results=Array.isArray(j.results)?j.results:[];
results.forEach(x=>{const i=Number(x.index);if(!rows[i])return;rows[i].check_status=x.check_status;rows[i].match_type=x.match_type;rows[i].existing_control=x.existing_control||'';rows[i].existing_record_id=Number(x.existing_record_id||0);rows[i].existing_record_version=Number(x.existing_record_version||0);rows[i].check_messages=Array.isArray(x.check_messages)?x.check_messages:[];});
updateCheckDisplay(results);
}
function hide(){modal.hidden=true;document.body.classList.remove('fams-modal-open')}function open(i){editingIndex=i;const r=rows[i]||{};names.forEach(n=>{const e=form.elements[n];if(e)e.value=r[n]??''});modal.hidden=false;document.body.classList.add('fams-modal-open');form.elements.patient_first_name?.focus()}
function bind(){body.querySelectorAll('.import-edit-btn').forEach(b=>b.addEventListener('click',()=>open(+b.dataset.rowIndex)));body.querySelectorAll('.import-delete-btn').forEach(b=>b.addEventListener('click',async()=>{const i=+b.dataset.rowIndex;if(!rows[i]||!confirm('Delete this entry from the import table? It will NOT affect the database until an import is applied.'))return;const removed=rows.splice(i,1)[0];render();try{await runRecheck()}catch(e){rows.splice(i,0,removed);render();alert(e.message||'Unable to recheck the current table.')}}))}
bind();
deleteBlockedButton?.addEventListener('click',async()=>{
  const blockedCount=rows.filter(r=>r.check_status==='BLOCKED').length;
  if(!blockedCount)return;
  if(!confirm('Delete all '+blockedCount+' blocked entries from the import table? This will NOT affect the database until an import is applied.'))return;
  const oldRows=rows.slice();
  rows=rows.filter(r=>r.check_status!=='BLOCKED');
  render();
  try{await persist();}
  catch(err){rows=oldRows;render();alert(err.message||'Unable to save the updated import table.');}
});
form.addEventListener('submit',async e=>{e.preventDefault();if(editingIndex<0||!rows[editingIndex])return;const index=editingIndex,old=JSON.parse(JSON.stringify(rows[index]));names.forEach(n=>{const el=form.elements[n];if(el)rows[index][n]=el.value.trim()});rows[index].check_status='BLOCKED';rows[index].check_messages=['Checking edited entry…'];render();const b=form.querySelector('button[type="submit"]');if(b)b.disabled=true;try{await runRecheck();hide()}catch(err){rows[index]=old;render();alert(err.message||'Unable to check the edited entry.')}finally{if(b)b.disabled=false}});
recheckForm.addEventListener('submit',async e=>{e.preventDefault();if(!rows.length){alert('There are no table entries to recheck.');return}if(recheckButton)recheckButton.disabled=true;try{await runRecheck()}catch(err){alert(err.message||'Unable to recheck the current table.')}finally{if(recheckButton)recheckButton.disabled=false}});
close?.addEventListener('click',hide);cancel?.addEventListener('click',hide);modal.addEventListener('click',e=>{if(e.target===modal)hide()});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)hide()});
})();
</script>
<?php endif; ?>
<?php require __DIR__.'/partials/footer.php'; ?>
