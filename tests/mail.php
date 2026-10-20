<?php
declare(strict_types=1);
require_once 'src/Config/database.php';
require_once 'src/Services/MailService.php';
require_once 'src/Services/PropertySubmissionService.php';
if(strtolower((string)getenv('APP_ENV'))!=='dev')throw new RuntimeException('DEV only');
$db=getDatabaseConnection();$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
function reject(callable $fn,int $code,string $label):void{try{$fn();}catch(MailProblem $e){check($e->status===$code,$label);return;}throw new RuntimeException($label);}
$id=null;
try{
 $slug='qamail'.bin2hex(random_bytes(6));$s=$db->prepare("INSERT INTO organizations(name,slug,country_code) VALUES('QA Mail',?,'ES') RETURNING id");$s->execute([$slug]);$id=$s->fetchColumn();
 putenv('MAIL_ENABLED=1');putenv('MAIL_OFFICE_ID='.$id);putenv('MAIL_MASTER_KEY=mock');putenv('MAIL_TOKEN_CIPHER=mock');putenv('MAIL_RESOURCE_ID=mock');
 putenv('SUBMISSIONS_MODE=mail');putenv('PUBLIC_CRM_BASE_URL=https://crm-dev.reygom.com');check(PropertySubmissionService::mode()==='mail','Real verification requires configured mailbox and trusted CRM URL');putenv('PUBLIC_CRM_BASE_URL=http://untrusted.invalid');check(PropertySubmissionService::mode()==='disabled','Untrusted verification URL disables public submissions');putenv('SUBMISSIONS_MODE=test');
 $calls=[];$transport=function($path,$body)use(&$calls){$calls[]=[$path,$body];return [];};$m=new MailService($db,$id,$transport);
 check($m->status()['address']==='info@reygom.com','Configured office account');
 reject(fn()=>(new MailService($db,'00000000-0000-0000-0000-000000000000',$transport))->folders(),404,'Foreign office denied before provider call');
 check(count($calls)===0,'No foreign-office provider request');
 foreach([['to'=>['bad'],'subject'=>'x','text'=>'x'],['to'=>['a@example.invalid'],'subject'=>"x\r\nInjected: yes",'text'=>'x'],['to'=>['a@example.invalid'],'subject'=>'x','text'=>''],['to'=>'a@example.invalid','subject'=>'x','text'=>'x']] as $bad)reject(fn()=>MailService::compose($bad),422,'Invalid message rejected');
 $input=['to'=>['a@example.invalid'],'subject'=>'QA no real mail','text'=>'Only mock transport'];
 check($m->send($input,'qa-mail-request-0001')['status']==='sent','Mock send accepted including empty provider response');
 check($m->send($input,'qa-mail-request-0001')['duplicate']===true,'Duplicate idempotency key is not resent');
 check(count($calls)===1,'Exactly one mock send');
 reject(fn()=>$m->send(array_merge($input,['text'=>'different']),'qa-mail-request-0001'),409,'Same key different content rejected');
 $unknown=new MailService($db,$id,function(){throw new MailProblem('Mock timeout',502);});reject(fn()=>$unknown->send($input,'qa-mail-request-0002'),502,'Uncertain send handled');
 check($m->send($input,'qa-mail-request-0002')['status']==='unknown','Uncertain send cannot be blindly retried');
 check(count($calls)===1,'No retry after unknown delivery');
 $reader=new MailService($db,$id,function($path){return str_ends_with($path,'/text')?['data'=>['html'=>'<b>Hello</b><img src="https://tracker.invalid/x">']]:['data'=>['uid'=>1,'subject'=>'QA']];});
 $message=$reader->message('INBOX',1);check($message['body_text']==='Hello','HTML converted to inert plain text');
 reject(fn()=>$reader->messages('INBOX',0),422,'Invalid page denied');reject(fn()=>$reader->message('INBOX',0),422,'Invalid UID denied');
 // Non-owner runtime cannot inspect another office delivery history.
 $db->exec("SELECT set_config('reygom.organization_id','00000000-0000-0000-0000-000000000000',false)");$db->exec('SET ROLE reygom_office_runtime');
 $s=$db->prepare('SELECT count(*) FROM mail_deliveries WHERE organization_id=?');$s->execute([$id]);check((int)$s->fetchColumn()===0,'Delivery history protected by RLS');$db->exec('RESET ROLE');
 echo "TOTAL $count; external emails sent: 0\n";
}finally{
 $db->exec('RESET ROLE');
 if($id){$db->beginTransaction();$db->exec("SET LOCAL reygom.audit_maintenance='on'");$s=$db->prepare('DELETE FROM mail_deliveries WHERE organization_id=?');$s->execute([$id]);$s=$db->prepare('DELETE FROM organizations WHERE id=?');$s->execute([$id]);$s=$db->prepare('DELETE FROM office_audit WHERE organization_id=?');$s->execute([$id]);$db->commit();}
}
