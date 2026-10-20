<?php
declare(strict_types=1);
require 'src/Config/database.php';require 'src/Services/BrandingService.php';
if(strtolower((string)getenv('APP_ENV'))!=='dev')throw new RuntimeException('DEV only');
$db=getDatabaseConnection();$count=0;$offices=[];$users=[];
function check(bool $condition,string $message):void{global $count;if(!$condition)throw new RuntimeException($message);++$count;echo "PASS $message\n";}
function rejected(callable $fn,int $status,string $message):void{try{$fn();}catch(BrandingProblem $e){check($e->status===$status,$message);return;}throw new RuntimeException($message);}
function query(string $sql,array $params=[]):PDOStatement{global $db;$s=$db->prepare($sql);$s->execute($params);return $s;}
function context(array $user):void{global $db;$db->exec('RESET ROLE');query("SELECT set_config('reygom.user_id',?,false),set_config('reygom.organization_id',?,false)",[$user['id'],$user['organization_id']]);$db->exec('SET ROLE reygom_office_runtime');}
try{
 foreach(['a','b'] as $suffix){$o=query("INSERT INTO organizations(name,slug,country_code) VALUES('QA Marca',?,'ES') RETURNING id",['qabrand'.bin2hex(random_bytes(6))])->fetchColumn();$offices[]=$o;foreach(['admin','agent'] as $role){$id=query('INSERT INTO users(username,password,role,organization_id) VALUES(?,?,?,?) RETURNING id',['qabrand'.bin2hex(random_bytes(6)),password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),$role,$o])->fetchColumn();$users[]=['id'=>$id,'username'=>'QA','role'=>$role,'organization_id'=>$o,'office_name'=>'QA Marca'];}}
 [$adminA,$agentA,$adminB,$agentB]=$users;context($adminA);$service=new BrandingService($db,$adminA);$initial=$service->branding();check($initial['version']===0,'Default branding requires no data rewrite');
 $saved=$service->update(['display_name'=>'QA A','primary_color'=>'#123456','sidebar_color'=>'#ffffff','version'=>0]);check($saved['display_name']==='QA A'&&(int)$saved['version']===1,'Office administrator saves own branding');
 rejected(fn()=>$service->update(['display_name'=>'Lost update','primary_color'=>'#123456','sidebar_color'=>'#ffffff','version'=>0]),409,'Concurrent outdated edit rejected');
 foreach(['red','#123','url(x)','#000000;display:none'] as $bad)rejected(fn()=>BrandingService::colors(['primary_color'=>$bad,'sidebar_color'=>'#ffffff']),422,'Invalid CSS input rejected');
 context($adminB);check((new BrandingService($db,$adminB))->branding()['display_name']==='','Other office retains default brand');check((int)query('SELECT count(*) FROM office_branding')->fetchColumn()===0,'RLS hides foreign branding');
 context($agentA);$agentService=new BrandingService($db,$agentA);check($agentService->branding()['display_name']==='QA A','Office agent sees own office branding');
 rejected(fn()=>$agentService->update(['display_name'=>'Forbidden','primary_color'=>'#123456','sidebar_color'=>'#ffffff','version'=>1]),403,'Agent cannot edit office branding');
 check(query('UPDATE office_branding SET display_name=? WHERE organization_id=?',['Forbidden',$adminA['organization_id']])->rowCount()===0,'Database independently denies agent branding writes');
 rejected(fn()=>BrandingService::image(['error'=>0,'size'=>1,'tmp_name'=>'/etc/passwd'],'photo'),422,'Local file cannot masquerade as an upload');
 $path=tempnam(sys_get_temp_dir(),'brandqa');$image=imagecreatetruecolor(20,10);imagepng($image,$path);imagedestroy($image);$photo=BrandingService::normalizeImage($path,'photo');$logo=BrandingService::normalizeImage($path,'logo');unlink($path);
 $photoInfo=getimagesizefromstring(base64_decode(substr($photo,strpos($photo,',')+1)));$logoInfo=getimagesizefromstring(base64_decode(substr($logo,strpos($logo,',')+1)));
 check($photoInfo[0]===256&&$photoInfo[1]===256&&$photoInfo['mime']==='image/webp','Profile photo normalized to safe square WebP');check($logoInfo[0]===20&&$logoInfo[1]===10,'Logo keeps proportions');
 query('INSERT INTO user_profiles(user_id,organization_id,photo) VALUES(?,?,?)',[$agentA['id'],$agentA['organization_id'],$photo]);check($agentService->profile()['photo']===$photo,'Agent can read own photo');
 context($adminA);check((new BrandingService($db,$adminA))->profile()['photo']===null,'Administrator does not inherit another users photo');check((int)query('SELECT count(*) FROM user_profiles')->fetchColumn()===0,'RLS hides another users profile image');
 context($agentB);check((int)query('SELECT count(*) FROM user_profiles')->fetchColumn()===0,'Other office cannot read profile images');
 $db->exec('RESET ROLE');check((int)query("SELECT count(*) FROM office_audit WHERE action IN ('office.branding_updated','user.photo_updated') AND organization_id=?",[$adminA['organization_id']])->fetchColumn()>=2,'Brand and profile changes audited without image contents');
 echo "TOTAL $count\n";
}finally{
 $db->exec('RESET ROLE');$db->beginTransaction();$db->exec("SET LOCAL reygom.audit_maintenance='on'");
 foreach($offices as $id){query('DELETE FROM user_profiles WHERE organization_id=?',[$id]);query('DELETE FROM office_branding WHERE organization_id=?',[$id]);query('DELETE FROM users WHERE organization_id=?',[$id]);query('DELETE FROM organizations WHERE id=?',[$id]);query('DELETE FROM office_audit WHERE organization_id=?',[$id]);}
 $db->commit();
}
