<?php
declare(strict_types=1);

// Exercises the real public route and gallery queries with synthetic data only.
require_once __DIR__.'/../src/Models/PropertyImage.php';
require_once $argv[1] ?? __DIR__.'/../src/Routes/public.php';

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE organizations(id TEXT PRIMARY KEY,status TEXT,deleted_at TEXT)');
$db->exec("INSERT INTO organizations VALUES ('active','active',NULL),('inactive','inactive',NULL),('deleted','active','2026-10-01')");
$columns=['reference_id','public_reference','title','description','price','operation_type','property_type','status','cover_image','country_code','country','state_code','state','postal_code','city','province','bedrooms','bathrooms','built_area','features','created_at'];
$db->exec('CREATE TABLE properties(id TEXT PRIMARY KEY,organization_id TEXT,'.implode(',',array_map(fn($c)=>$c.' TEXT',$columns)).')');
$db->exec('CREATE TABLE property_images(id TEXT PRIMARY KEY,property_id TEXT,image_url TEXT,position INTEGER,is_cover INTEGER,created_at TEXT)');
$ids=[];
foreach(['active','inactive','deleted','unavailable'] as $i=>$kind){
 $id='11111111-1111-4111-8111-'.str_pad((string)($i+1),12,'0',STR_PAD_LEFT);$ids[$kind]=$id;
 $q=$db->prepare('INSERT INTO properties(id,organization_id,reference_id,public_reference,title,status,created_at) VALUES(?,?,?,?,?,?,?)');
 $q->execute([$id,$kind==='unavailable'?'active':$kind,'LEGACY'.($i+1),'RG'.str_pad((string)($i+1),6,'0',STR_PAD_LEFT),'Synthetic '.$kind,$kind==='unavailable'?'Vendido':'Disponible','2026-10-01']);
}
$q=$db->prepare('INSERT INTO property_images VALUES(?,?,?,?,?,?)');
$q->execute(['second',$ids['active'],'https://example.test/second.webp',0,0,'2026-10-01']);
$q->execute(['cover',$ids['active'],'https://example.test/cover.webp',1,1,'2026-10-01']);
$q->execute(['other-office',$ids['inactive'],'https://example.test/private.webp',0,1,'2026-10-01']);

$count=0;$failures=[];
function check(bool $ok,string $label):void{global $count,$failures;++$count;if(!$ok)$failures[]=$label;}
function request(string $path,string $method='GET'):array{global $db;http_response_code(200);ob_start();$handled=handlePublicRoutes($path,$method,$db);$body=ob_get_clean();return [$handled,http_response_code(),json_decode($body,true),$body];}

[, $status,$body]=request('/public/properties');
check($status===200,'catalog status');
check(count($body)===1&&$body[0]['id']===$ids['active'],'catalog excludes inactive, deleted and unavailable properties');
foreach(['/public/properties/'.$ids['active'],'/public/properties/reference/LEGACY1','/public/properties/reference/RG000001','/public/properties/reference/RG1'] as $path){
 [, $status,$body]=request($path);
 check($status===200,'published detail accessible');
 check(isset($body['images'])&&count($body['images'])===2,'detail retains full gallery');
 check(array_column($body['images']??[],'id')===['cover','second'],'gallery is scoped to property and cover is first');
}
foreach(['inactive','deleted','unavailable'] as $kind){
 [, $status]=request('/public/properties/'.$ids[$kind]);check($status===404,$kind.' detail hidden');
}
foreach(['LEGACY2','RG000002','LEGACY3','RG000003'] as $ref){
 [, $status]=request('/public/properties/reference/'.$ref);check($status===404,'hidden office cannot be found by reference');
}
[, $status]=request('/public/properties','POST');check($status===405,'public catalog remains read only');
[$handled,,,$raw]=request('/unrelated');check($handled===false&&$raw==='','unrelated route is untouched');
if($failures){fwrite(STDERR,implode("\n",array_map(fn($label)=>'FAIL: '.$label,$failures))."\n");exit(1);}
echo "PASS: $count public catalog checks\n";
