<?php
declare(strict_types=1);
function handleAnalyticsRoutes(string $uri,string $method,PDO $pdo):bool {
 $uuid='([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';
 $public=preg_match('#^/public/properties/'.$uuid.'/visits$#iD',$uri,$m);
 if(!$public&&!preg_match('#^/properties/'.$uuid.'/analytics$#iD',$uri,$m))return false;
 header('Cache-Control: no-store');
 try {
  if($public) {
   if($method!=='POST')TenantAccess::deny(405,'Método no permitido.');
   $origins=explode(',',getenv('ANALYTICS_ALLOWED_ORIGINS')?:'https://reygom.com,https://www.reygom.com');
   if(!in_array($_SERVER['HTTP_ORIGIN']??'',$origins,true))TenantAccess::deny(403,'Origen no autorizado.');
   if(($_SERVER['HTTP_DNT']??'')==='1'||($_SERVER['HTTP_SEC_GPC']??'')==='1'||preg_match('/bot|crawler|spider|headless/i',$_SERVER['HTTP_USER_AGENT']??'')){http_response_code(204);return true;}
   $raw=file_get_contents('php://input',false,null,0,4097);if(strlen($raw)>4096)TenantAccess::deny(413,'Petición demasiado grande.');
   $body=json_decode($raw,true);if(!is_array($body))TenantAccess::deny(422,'Petición no válida.');
   PropertyAnalytics::collect($pdo,$m[1],$body);http_response_code(204);return true;
  }
  $user=JwtMiddleware::authenticate();if(!in_array($user['role'],['admin','superadmin'],true))TenantAccess::deny(403,'Solo los administradores pueden consultar las estadísticas.');
  if($method!=='GET')TenantAccess::deny(405,'Método no permitido.');
  $days=filter_var($_GET['days']??30,FILTER_VALIDATE_INT);if(!in_array($days,[7,30,90],true))TenantAccess::deny(422,'Periodo no válido.');
  echo json_encode(PropertyAnalytics::report($pdo,$m[1],$days));return true;
 }catch(Throwable $e){error_log('Property analytics failed: '.$e->getMessage());http_response_code(500);echo json_encode(['message'=>'No se pudieron procesar las estadísticas.']);return true;}
}
