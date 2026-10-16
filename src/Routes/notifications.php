<?php
declare(strict_types=1);
function handleNotificationRoutes(string $path,string $method,PDO $pdo):bool{
 if($path!=='/notifications'&&!str_starts_with($path,'/notifications/'))return false;
 header('Cache-Control: no-store');$user=JwtMiddleware::authenticate();
 try{
  $service=new NotificationService($pdo,$user);
  if($path==='/notifications/recipients'&&$method==='GET')$result=['recipients'=>$service->recipients(),'role'=>$user['role']];
  elseif($path==='/notifications/unread'&&$method==='GET')$result=['unread'=>$service->unread()];
  elseif($path==='/notifications'&&$method==='GET')$result=$service->inbox((int)($_GET['page']??1),($_GET['unread_only']??'')==='1');
  elseif($path==='/notifications/sent'&&$method==='GET')$result=$service->sent((int)($_GET['page']??1));
  elseif($path==='/notifications/read-all'&&$method==='POST')$result=$service->read(null);
  elseif(preg_match('#^/notifications/([a-f0-9-]{36})/read$#i',$path,$m)&&$method==='POST')$result=$service->read($m[1]);
  elseif($path==='/notifications'&&$method==='POST'){$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))throw new NotificationProblem('Datos no válidos.');$result=$service->send($input);http_response_code(201);}
  else throw new NotificationProblem('Método o ruta no permitido.',405);
  echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(NotificationProblem $e){http_response_code($e->status);echo json_encode(['message'=>$e->getMessage()]);}
 catch(Throwable $e){error_log('Notification operation failed: '.get_class($e));http_response_code(500);echo json_encode(['message'=>'No se pudo completar la notificación.']);}
 return true;
}
