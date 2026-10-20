<?php
declare(strict_types=1);
function handleAgendaRoutes(string $uri,string $method,PDO $pdo):bool {
 $id=null;
 if(preg_match('#^/agenda/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$#iD',$uri,$m))$id=$m[1];
 elseif(!in_array($uri,['/agenda','/agenda/context'],true))return false;
 header('Cache-Control: no-store');$user=JwtMiddleware::authenticate();
 try {
  if($uri==='/agenda/context'&&$method==='GET'){echo json_encode(AgendaService::context($pdo,$user));return true;}
  if($uri==='/agenda'&&$method==='GET'){
   $owner=$_GET['owner']??null;if(!is_string($owner)&&$owner!==null)throw new AgendaProblem('Usuario no válido.');
   $from=$_GET['from']??'';$to=$_GET['to']??'';if(!is_string($from)||!is_string($to))throw new AgendaProblem('Periodo no válido.');
   echo json_encode(['events'=>AgendaService::list($pdo,$user,$from,$to,$owner)]);return true;
  }
  if(($uri==='/agenda'&&$method==='POST')||($id!==null&&$method==='PUT')){
   $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new AgendaProblem('Petición demasiado grande.',413);
   $body=json_decode($raw,true);if(!is_array($body)||array_is_list($body))throw new AgendaProblem('Datos no válidos.');
   $row=AgendaService::save($pdo,$user,$body,$id);http_response_code($id===null?201:200);echo json_encode($row);return true;
  }
  throw new AgendaProblem('Método no permitido.',405);
 }catch(AgendaProblem $e){http_response_code($e->status);echo json_encode(['message'=>$e->getMessage()]);return true;}
 catch(Throwable $e){error_log('Agenda: '.$e->getMessage());http_response_code(500);echo json_encode(['message'=>'No se pudo completar la operación de agenda.']);return true;}
}
