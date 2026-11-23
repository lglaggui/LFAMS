<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/endpoint-registry.json';$manifest=json_decode((string)file_get_contents($path),true);
$routes=$manifest['routes']??[];$seen=[];$failed=0;
$check=static function(bool $ok,string $label)use(&$failed):void{echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;};
$check(($manifest['manifest_version']??0)>=1,'endpoint manifest has a supported version');
$check(count($routes)>=70,'workspace endpoint inventory contains expected routes');
$valid=true;foreach($routes as $route){$key=$route['path']??'';if($key===''||isset($seen[$key])||empty($route['workspace'])||empty($route['methods_to_verify'])||!array_key_exists('login_required',$route)||empty($route['capability'])||empty($route['ownership_scope'])||empty($route['review_status'])){$valid=false;break;}$seen[$key]=true;}
$check($valid,'every route has unique path and required review metadata');
$check(str_contains((string)($manifest['policy']??''),'not runtime authorization enforcement'),'manifest explicitly warns it is not runtime authorization enforcement');
exit($failed?1:0);
