<?php
declare(strict_types=1);
function handleMailRoutes(string $path,string $method,PDO $pdo):bool{
 if(!str_starts_with($path,'/mail/'))return false;
 header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
 $user=JwtMiddleware::authenticate(false);
 if(!in_array($user['role'],['admin','superadmin'],true))TenantAccess::deny(403,'Solo el administrador de oficina puede acceder al correo.');
 TenantAccess::scope($pdo,$user);
 try{
  $service=new MailService($pdo,$user['organization_id']);
  if($method==='GET'&&$path==='/mail/account')$result=$service->status();
  elseif($method==='GET'&&$path==='/mail/folders')$result=$service->folders();
  elseif($method==='GET'&&$path==='/mail/messages')$result=$service->messages((string)($_GET['folder']??'INBOX'),(int)($_GET['page']??1));
  elseif($method==='GET'&&$path==='/mail/message')$result=$service->message((string)($_GET['folder']??'INBOX'),(int)($_GET['uid']??0));
  elseif($method==='POST'&&$path==='/mail/send'){
   $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))throw new MailProblem('Datos no válidos.',422);
   $result=$service->send($input,(string)($input['request_key']??''),$user['id']);
  }else throw new MailProblem('Ruta o método no permitido.',405);
  echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(MailProblem $e){http_response_code($e->status);echo json_encode(['message'=>$e->getMessage()]);}
 catch(Throwable $e){error_log('Mail operation failed: '.get_class($e));http_response_code(500);echo json_encode(['message'=>'No se pudo completar la operación de correo.']);}
 return true;
}
