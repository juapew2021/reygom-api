<?php
declare(strict_types=1);
function handleBrandingRoutes(string $path,string $method,PDO $pdo):bool{
 if(!in_array($path,['/office-branding','/office-branding/logo','/profile','/profile/photo'],true))return false;
 header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
 $user=JwtMiddleware::authenticate();
 try{
  $service=new BrandingService($pdo,$user);
  if($path==='/office-branding'&&$method==='GET')$result=$service->branding();
  elseif($path==='/profile'&&$method==='GET')$result=$service->profile();
  elseif($path==='/office-branding'&&$method==='PUT'){$data=json_decode(file_get_contents('php://input'),true);if(!is_array($data))throw new BrandingProblem('Datos no válidos.');$result=$service->update($data);}
  elseif($path==='/office-branding/logo'&&$method==='POST')$result=$service->logo($_FILES['image']??[]);
  elseif($path==='/profile/photo'&&$method==='POST')$result=$service->photo($_FILES['image']??[]);
  else throw new BrandingProblem('Método no permitido.',405);
  echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(BrandingProblem $e){http_response_code($e->status);echo json_encode(['message'=>$e->getMessage()]);}
 catch(Throwable $e){error_log('Branding operation failed: '.get_class($e));http_response_code(500);echo json_encode(['message'=>'No se pudo guardar la personalización.']);}
 return true;
}
