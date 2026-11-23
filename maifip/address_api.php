<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

/*
 * FAMS 2.6 — LOCAL PSGC ADDRESS DIRECTORY
 *
 * The dropdowns read from this local MySQL cache. Internet is used only when
 * the cache needs a refresh; normal Province -> Municipality/City -> Barangay
 * selection never depends on an external request.
 *
 * Reference: Philippine Statistics Authority PSGC, latest publication as of
 * 30 June 2026. PSGC Cloud is used only as a convenient machine-readable
 * transport for refreshing the local copy; it is not queried by the browser.
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS psgc_address_cache (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 level_type VARCHAR(24) NOT NULL,
 code VARCHAR(32) NOT NULL,
 name VARCHAR(180) NOT NULL,
 parent_code VARCHAR(32) NULL,
 region_code VARCHAR(32) NULL,
 province_code VARCHAR(32) NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_psgc_level_code(level_type,code),
 KEY idx_psgc_parent(parent_code),
 KEY idx_psgc_province(province_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS psgc_address_meta (
 meta_key VARCHAR(50) PRIMARY KEY,
 meta_value VARCHAR(255) NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Supported address coverage: Region I, Region II and CAR. Region I is enabled
// for expansion now; its full hierarchy is populated by the administrator's refresh.
$regions=[
 ['code'=>'0100000000','name'=>'Region I (Ilocos Region)'],
 ['code'=>'0200000000','name'=>'Region II (Cagayan Valley)'],
 ['code'=>'1400000000','name'=>'Cordillera Administrative Region (CAR)']
];
$regionCodes=array_map(static fn(array $r): string => $r['code'], $regions);
$regionInSql=implode(',', array_map(static fn(string $c): string => $pdo->quote($c), $regionCodes));

function psgc_http(string $url): array {
 $ctx=stream_context_create(['http'=>[
  'method'=>'GET','timeout'=>10,'ignore_errors'=>true,
  'header'=>"Accept: application/json\r\nUser-Agent: LPGMA-FAMS/2.7\r\n"
 ]]);
 $raw=@file_get_contents($url,false,$ctx);
 if(($raw===false || $raw===null) && function_exists('curl_init')){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: LPGMA-FAMS/2.7']]);
  $raw=curl_exec($ch); curl_close($ch);
 }
 if($raw===false || $raw===null)return [];
 $d=json_decode($raw,true);
 return is_array($d)?$d:[];
}
function psgc_items(array $d): array {
 if(isset($d['data']) && is_array($d['data'])) return $d['data'];
 return array_is_list($d)?$d:[];
}
function cache_stale(PDO $pdo): bool {
 $v=$pdo->query("SELECT meta_value FROM psgc_address_meta WHERE meta_key='last_refresh' LIMIT 1")->fetchColumn();
 return !$v || strtotime((string)$v) < time()-30*86400;
}
function save_meta(PDO $pdo,string $key,string $value): void {
 $s=$pdo->prepare("INSERT INTO psgc_address_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()");
 $s->execute([$key,$value]);
}
function cache_upsert(PDO $pdo,string $level,string $code,string $name,?string $parent=null,?string $region=null,?string $province=null): void {
 $code=trim($code); $name=trim($name); if($code===''||$name==='')return;
 $s=$pdo->prepare("INSERT INTO psgc_address_cache(level_type,code,name,parent_code,region_code,province_code,updated_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),parent_code=VALUES(parent_code),region_code=VALUES(region_code),province_code=VALUES(province_code),updated_at=NOW()");
 $s->execute([$level,$code,$name,$parent,$region,$province]);
}
function nested_code(mixed $value): string {
 if(is_array($value)) return trim((string)($value['code']??''));
 return trim((string)$value);
}
function nested_name(mixed $value): string {
 if(is_array($value)) return trim((string)($value['name']??''));
 return trim((string)$value);
}
function seed_bundled_cache(PDO $pdo): bool {
 $file=__DIR__.'/data/psgc_region2_car_2026.json';
 if(!is_file($file)) return false;
 try{
  $raw=file_get_contents($file); if($raw===false) return false;
  $bundle=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
  $records=$bundle['records']??[]; if(!is_array($records)||!$records) return false;
  $count=fams_shared_transaction($pdo,function(PDO $db) use($records,$bundle){
  $pdo=$db;
  $stmt=$pdo->prepare("INSERT INTO psgc_address_cache(level_type,code,name,parent_code,region_code,province_code,updated_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),parent_code=VALUES(parent_code),region_code=VALUES(region_code),province_code=VALUES(province_code),updated_at=NOW()");
  $count=0;
  foreach($records as $r){
   $level=trim((string)($r['level']??'')); $code=trim((string)($r['code']??'')); $name=trim((string)($r['name']??''));
   if($level===''||$code===''||$name==='') continue;
   $stmt->execute([$level,$code,$name,($r['parent_code']??null),($r['region_code']??null),($r['province_code']??null)]); $count++;
  }
  save_meta($pdo,'bundled_seed','PSGC-2Q-2026-Publication-Datafile.xlsx');
  save_meta($pdo,'bundled_reference_date',(string)($bundle['reference_date']??'30 June 2026'));
  save_meta($pdo,'bundled_seeded_at',date('Y-m-d H:i:s'));
  return $count;
  });
  return $count>0;
 }catch(Throwable $e){ return false; }
}


function code9(string $code): string {
 $code=trim($code);
 // The older PSGC mirror uses the 9-digit PSGC code. Current FAMS data uses
 // the 10-digit code, so remove the final trailing zero from province/city
 // codes when using that fallback.
 return strlen($code)===10 ? substr($code,0,9) : $code;
}
function code4(string $code): string {
 $c=trim($code);
 if(strlen($c)===10) return substr($c,0,2).substr($c,2,2);
 if(strlen($c)===9) return substr($c,0,4);
 return $c;
}
function code6(string $code): string {
 $c=trim($code);
 // Some public PSGC v1 endpoints use the 6-digit correspondence/city code.
 if(strlen($c)===10) return substr($c,0,2).substr($c,3,2).substr($c,5,2);
 if(strlen($c)===9) return substr($c,0,6);
 return $c;
}
function correspondence_to_10(string $code,string $parentProvince10='',string $parentMunicipality10=''): string {
 $c=preg_replace('/[^0-9]/','',$code) ?: $code;
 if(strlen($c)===10) return $c;
 if(strlen($c)===9){
  if($parentMunicipality10!=='') return substr($parentMunicipality10,0,7).substr($c,6,3);
  if($parentProvince10!=='') return substr($parentProvince10,0,5).substr($c,4,5);
 }
 return $c;
}
function nowcraft_items(array $d, string $key=''): array {
 // The public dataset API has used both direct arrays and nested data objects.
 // Walk the common wrappers so a response-shape change does not break the
 // Region I barangay cascade.
 if($key!=='' && isset($d[$key]) && is_array($d[$key])) return $d[$key];
 foreach(['data','result','results'] as $wrapper){
  if(isset($d[$wrapper]) && is_array($d[$wrapper])){
   if($key!=='' && isset($d[$wrapper][$key]) && is_array($d[$wrapper][$key])) return $d[$wrapper][$key];
   if(array_is_list($d[$wrapper])) return $d[$wrapper];
  }
 }
 if(array_is_list($d)) return $d;
 return [];
}
function normalize_remote_items(array $items): array {
 $out=[];
 foreach($items as $item){
  if(!is_array($item)) continue;
  $props=(isset($item['properties']) && is_array($item['properties'])) ? $item['properties'] : $item;
  $code=(string)($props['psgc_code']??$props['psgc10DigitCode']??$props['code']??'');
  $name=(string)($props['name']??'');
  $parent='';
  foreach(['city_municipality','cityMunicipality','city_municipality_code','cityMunicipalityCode'] as $pk){
   if(isset($props[$pk])){ $pv=$props[$pk]; $parent=is_array($pv)?(string)($pv['code']??$pv['psgc_code']??$pv['psgc10DigitCode']??''):(string)$pv; if($parent!=='') break; }
  }
  if($code!=='' && $name!=='') $out[]=['code'=>$code,'name'=>$name,'parent_code'=>$parent,'raw'=>$props];
 }
 return $out;
}
function remote_get_items(array $urls, string $key=''): array {
 foreach($urls as $url){
  $d=psgc_http($url);
  $items=normalize_remote_items(nowcraft_items($d,$key));
  if($items) return $items;
 }
 return [];
}
function ensure_region1_municipalities(PDO $pdo,string $provinceCode): int {
 if(strpos($provinceCode,'010')!==0) return 0;
 $nine=code9($provinceCode); $four=code4($provinceCode);
 $items=remote_get_items([
  // Current PSGC Cloud v2 — 10-digit codes.
  'https://psgc.cloud/api/v2/provinces/'.rawurlencode($provinceCode).'/cities-municipalities',
  // Public dataset mirror — accepts 10- and 9-digit forms.
  'https://philippine-datasets-api.nowcraft.ing/api/provinces/'.rawurlencode($provinceCode),
  'https://philippine-datasets-api.nowcraft.ing/api/provinces/'.rawurlencode($nine),
  // PSGC GitLab mirror — 9-digit codes.
  'https://psgc.gitlab.io/api/provinces/'.rawurlencode($nine).'/cities-municipalities/',
  // PSGC Cloud v1 uses correspondence codes.
  'https://psgc.cloud/api/v1/provinces/'.rawurlencode($four).'/cities-municipalities'
 ],'cities');
 // Some endpoints return a direct list without a `cities` wrapper.
 if(!$items){
  $items=remote_get_items([
   'https://psgc.cloud/api/v2/provinces/'.rawurlencode($provinceCode).'/cities-municipalities',
   'https://psgc.gitlab.io/api/provinces/'.rawurlencode($nine).'/cities-municipalities/'
  ]);
 }
 $count=0;
 foreach($items as $c){
  $cc=$c['code']; $cn=$c['name'];
  // Keep the application's canonical 10-digit code whenever a source returns
  // a 9-digit correspondence code.
  $cc=correspondence_to_10($cc,$provinceCode);
  if(strlen($cc)!==10) continue;
  cache_upsert($pdo,'municipality',$cc,$cn,$provinceCode,'0100000000',$provinceCode); $count++;
 }
 return $count;
}
function ensure_region1_barangays(PDO $pdo,string $municipalityCode): int {
 $m=$pdo->prepare("SELECT region_code,province_code FROM psgc_address_cache WHERE level_type='municipality' AND code=? LIMIT 1");
 $m->execute([$municipalityCode]); $parent=$m->fetch();
 if(!$parent || (string)$parent['region_code']!=='0100000000') return 0;
 $ten=preg_replace('/[^0-9]/','',(string)$municipalityCode) ?: (string)$municipalityCode;
 if(strlen($ten)===9) $ten.='0';
 $nine=code9($ten); $six=code6($ten);
 $items=remote_get_items([
  // Current PSGC Cloud v2 — this is the authoritative hierarchy endpoint.
  'https://psgc.cloud/api/v2/cities-municipalities/'.rawurlencode($ten).'/barangays',
  // Public dataset mirror.
  'https://philippine-datasets-api.nowcraft.ing/api/cities/'.rawurlencode($ten),
  'https://philippine-datasets-api.nowcraft.ing/api/cities/'.rawurlencode($nine),
  // PSGC GitLab mirror.
  'https://psgc.gitlab.io/api/cities/'.rawurlencode($nine).'/barangays/',
  // If the city-specific route is unavailable, the province-level PSGC Cloud
  // endpoint returns the same barangays with their city/municipality parent.
  'https://psgc.cloud/api/v2/provinces/'.rawurlencode((string)$parent['province_code']).'/barangays',
  // PSGC Cloud v1 correspondence-code endpoint.
  'https://psgc.cloud/api/v1/cities-municipalities/'.rawurlencode($six).'/barangays'
 ],'barangays');
 if(!$items){
  // Retry without assuming a `barangays` wrapper; several mirrors return a
  // plain JSON list.
  $items=remote_get_items([
   'https://psgc.cloud/api/v2/cities-municipalities/'.rawurlencode($ten).'/barangays',
   'https://psgc.gitlab.io/api/cities/'.rawurlencode($nine).'/barangays/'
  ]);
 }
 $count=0;
 foreach($items as $b){
  $bc=$b['code']; $bn=$b['name'];
  if(!empty($b['parent_code'])){
   $remoteParent=correspondence_to_10((string)$b['parent_code'],(string)$parent['province_code']);
   if($remoteParent!==$municipalityCode) continue;
  }
  $bc=correspondence_to_10($bc,'',$municipalityCode);
  if(strlen($bc)!==10) continue;
  cache_upsert($pdo,'barangay',$bc,$bn,$municipalityCode,'0100000000',(string)$parent['province_code']); $count++;
 }
 return $count;
}

function refresh_cache(PDO $pdo,array $regions): bool {
 $base='https://psgc.cloud/api/v2';
 $count=0;
 try {
  foreach($regions as $r){
   // Use the region CODE, not the display name. This is less fragile with
   // spaces/parentheses and is explicitly supported by the PSGC Cloud v2 API.
   $regionKey=rawurlencode($r['code']);
   $provs=psgc_items(psgc_http($base.'/regions/'.$regionKey.'/provinces'));
   if(!$provs && $r['code']==='0100000000'){
    $rd=psgc_http('https://philippine-datasets-api.nowcraft.ing/api/regions/0100000000');
    $provs=nowcraft_items($rd,'provinces');
    foreach($provs as $p){
     $props=$p['properties']??$p;
     $pc=(string)($props['psgc_code']??$props['code']??''); $pn=(string)($props['name']??'');
     if($pc!==''&&$pn!=='') cache_upsert($pdo,'province',$pc,$pn,$r['code'],$r['code'],$pc);
    }
   }
   if(!$provs) continue;
   $provinceCodes=[];
   foreach($provs as $p){
    $pc=(string)($p['code']??''); $pn=(string)($p['name']??'');
    if($pc===''||$pn==='')continue;
    cache_upsert($pdo,'province',$pc,$pn,$r['code'],$r['code'],$pc); $provinceCodes[$pc]=true; $count++;
   }

   // Province-scoped child requests avoid depending on nested parent object
   // shapes returned by different PSGC Cloud revisions.
   foreach(array_keys($provinceCodes) as $pc){
    $cities=psgc_items(psgc_http($base.'/provinces/'.rawurlencode($pc).'/cities-municipalities'));
    foreach($cities as $c){
     $cc=(string)($c['code']??''); $cn=(string)($c['name']??'');
     $rc=nested_code($c['region']??$r['code']);
     if($rc==='')$rc=$r['code'];
     if($cc===''||$cn==='')continue;
     cache_upsert($pdo,'municipality',$cc,$cn,$pc,$rc,$pc); $count++;
    }
   }
  }

  // Barangays are fetched by city/municipality. First read the now-local
  // municipality list for the two supported regions.
  $mun=$pdo->query("SELECT code,name,parent_code,region_code,province_code FROM psgc_address_cache WHERE level_type='municipality' AND region_code IN ($regionInSql)")->fetchAll();
  foreach($mun as $m){
   $cc=(string)$m['code'];
   $brgys=psgc_items(psgc_http($base.'/cities-municipalities/'.rawurlencode($cc).'/barangays'));
   if(!$brgys && (string)$m['region_code']==='0100000000') $brgys=psgc_items(psgc_http('https://psgc.cloud/api/v2/cities-municipalities/'.rawurlencode($cc).'/barangays'));
   foreach($brgys as $b){
    $bc=(string)($b['code']??''); $bn=(string)($b['name']??'');
    if($bc===''||$bn==='')continue;
    cache_upsert($pdo,'barangay',$bc,$bn,$cc,(string)$m['region_code'],(string)$m['province_code']); $count++;
   }
  }

  $municipalities=(int)$pdo->query("SELECT COUNT(*) FROM psgc_address_cache WHERE level_type='municipality' AND region_code IN ($regionInSql)")->fetchColumn();
  $barangays=(int)$pdo->query("SELECT COUNT(*) FROM psgc_address_cache WHERE level_type='barangay' AND region_code IN ($regionInSql)")->fetchColumn();
  if($municipalities>0 && $barangays>0){
   save_meta($pdo,'last_refresh',date('Y-m-d H:i:s'));
   save_meta($pdo,'source','PSGC Cloud API v2 / PSA PSGC');
   save_meta($pdo,'source_verified','PSA PSGC as of 30 June 2026');
   save_meta($pdo,'region_scope','Region I, Region II and CAR');
   save_meta($pdo,'refresh_interval','90 days (admin-controlled)');
   save_meta($pdo,'municipality_count',(string)$municipalities);
   save_meta($pdo,'barangay_count',(string)$barangays);
   return true;
  }
 }catch(Throwable $e){ /* preserve existing local cache */ }
 return false;
}

// Provinces remain usable even before a first online refresh. These are also
// inserted on existing V2.6 databases so Region I appears immediately.
$fallback=[
 ['0102800000','Ilocos Norte','0100000000'],['0102900000','Ilocos Sur','0100000000'],['0103300000','La Union','0100000000'],['0105500000','Pangasinan','0100000000'],
 ['0200900000','Batanes','0200000000'],['0201500000','Cagayan','0200000000'],['0203100000','Isabela','0200000000'],['0205000000','Nueva Vizcaya','0200000000'],['0205700000','Quirino','0200000000'],
 ['1400100000','Abra','1400000000'],['1401100000','Benguet','1400000000'],['1402700000','Ifugao','1400000000'],['1403200000','Kalinga','1400000000'],['1404400000','Mountain Province','1400000000'],['1408100000','Apayao','1400000000']
];
foreach($fallback as [$code,$name,$region]) cache_upsert($pdo,'province',$code,$name,$region,$region,$code);

// The complete Region II + CAR hierarchy is bundled with FAMS so the
// address selector works immediately, including during an internet outage.
// Internet is used only for an administrator-initiated refresh.
$action=$_GET['action']??'provinces';
if($action==='refresh'){
 if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); header('Allow: POST'); echo json_encode(['ok'=>false,'error'=>'POST is required to refresh the address directory.']); exit; }
 require_role(['admin']);
 $csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
 if($csrf==='' || !hash_equals((string)($_SESSION['csrf_token']??''),$csrf)){ http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Your security token expired. Refresh the page and try again.']); exit; }
 $ok=refresh_cache($pdo,$regions);
 if(!$ok){
  // Never leave a working local directory empty because an external refresh failed.
  seed_bundled_cache($pdo);
 }
 echo json_encode(['ok'=>$ok,'message'=>$ok?'PSGC local directory refreshed from the online source.':'Online PSGC refresh failed; bundled 30 June 2026 PSGC data was retained.']);
 exit;
}

$provinceCount=(int)$pdo->query("SELECT COUNT(*) FROM psgc_address_cache WHERE level_type='province' AND region_code IN ($regionInSql)")->fetchColumn();
$municipalityCount=(int)$pdo->query("SELECT COUNT(*) FROM psgc_address_cache WHERE level_type='municipality' AND region_code IN ($regionInSql)")->fetchColumn();
$barangayCount=(int)$pdo->query("SELECT COUNT(*) FROM psgc_address_cache WHERE level_type='barangay' AND region_code IN ($regionInSql)")->fetchColumn();
if($provinceCount<11 || $municipalityCount<170 || $barangayCount<3489){
 seed_bundled_cache($pdo);
}

try{
 if($action==='provinces'){
  $st=$pdo->query("SELECT code,name FROM psgc_address_cache WHERE level_type='province' ORDER BY name");
  $items=$st->fetchAll();
 }elseif($action==='municipalities'){
  $code=trim((string)($_GET['province']??''));
  $st=$pdo->prepare("SELECT code,name FROM psgc_address_cache WHERE level_type='municipality' AND parent_code=? ORDER BY name");
  $st->execute([$code]); $items=$st->fetchAll();
  if(!$items && str_starts_with($code,'010')){
   ensure_region1_municipalities($pdo,$code);
   $st->execute([$code]); $items=$st->fetchAll();
  }
 }elseif($action==='barangays'){
  $code=trim((string)($_GET['municipality']??''));
  $st=$pdo->prepare("SELECT code,name FROM psgc_address_cache WHERE level_type='barangay' AND parent_code=? ORDER BY name");
  $st->execute([$code]); $items=$st->fetchAll();
  if(!$items){
   ensure_region1_barangays($pdo,$code);
   $st->execute([$code]); $items=$st->fetchAll();
  }
 }elseif($action==='status'){
  $m=$pdo->query("SELECT meta_key,meta_value,updated_at FROM psgc_address_meta ORDER BY meta_key")->fetchAll(); $items=$m;
 }else{http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unknown action.']);exit;}
 $lastRefresh=$pdo->query("SELECT meta_value FROM psgc_address_meta WHERE meta_key='last_refresh'")->fetchColumn();
$nextDue=$lastRefresh ? date('Y-m-d H:i:s',strtotime((string)$lastRefresh)+90*86400) : null;
echo json_encode(['ok'=>true,'items'=>$items,'last_refresh'=>$lastRefresh,'next_refresh_due'=>$nextDue,'region_scope'=>$regions]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Local address cache is unavailable.']);}
