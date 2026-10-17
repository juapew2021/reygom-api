<?php
declare(strict_types=1);
function handlePanoramaRoutes(string $uri,string $method,PDO $pdo):bool {
 $uuid='([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';
 $public=preg_match('#^/public/properties/'.$uuid.'/panoramas(?:/'.$uuid.'/(image))?$#iD',$uri,$m);
 if(!$public&&!preg_match('#^/properties/'.$uuid.'/panoramas(?:/'.$uuid.'(?:/(image))?)?$#iD',$uri,$m))return false;
 header('Cache-Control: no-store');$property=$m[1];$panorama=$m[2]??null;
 try {
  if($public){
   if($method!=='GET')TenantAccess::deny(405,'Método no permitido.');
   $s=$pdo->prepare("SELECT p.id FROM properties p JOIN organizations o ON o.id=p.organization_id WHERE p.id=? AND p.status='Disponible' AND o.status='active' AND o.deleted_at IS NULL");$s->execute([$property]);
  }else{
   $user=JwtMiddleware::authenticate();
   $s=$pdo->prepare('SELECT id FROM properties WHERE id=?');$s->execute([$property]);
  }
  if(!$s->fetchColumn())TenantAccess::deny(404,'Inmueble no encontrado.');
  if($panorama&&$method==='GET'){
   $s=$pdo->prepare('SELECT filename FROM property_panoramas WHERE id=? AND property_id=? AND removed_at IS NULL');$s->execute([$panorama,$property]);$name=$s->fetchColumn();
   if(!$name||!preg_match('/^[a-f0-9]{32}\.webp$/D',$name)||!is_file('/var/www/media/panoramas/'.$name))TenantAccess::deny(404,'Panorámica no disponible.');
   header('Content-Type: image/webp');header('X-Content-Type-Options: nosniff');readfile('/var/www/media/panoramas/'.$name);return true;
  }
  if($method==='GET'&&!$panorama){echo json_encode(PropertyPanoramas::list($pdo,$property));return true;}
  if(!$public&&$method==='POST'&&!$panorama){
   $rows=PropertyPanoramas::upload($pdo,$property,$_FILES['panorama']??[],(string)($_POST['label']??''));$pdo->exec('RESET ROLE');AuditTrail::event($pdo,'property.panorama_uploaded',$user);TenantAccess::scope($pdo,$user);http_response_code(201);echo json_encode($rows);return true;
  }
  if(!$public&&$method==='DELETE'&&$panorama){
   $s=$pdo->prepare('UPDATE property_panoramas SET removed_at=now() WHERE id=? AND property_id=? AND removed_at IS NULL RETURNING id');$s->execute([$panorama,$property]);
   if(!$s->fetchColumn())TenantAccess::deny(404,'Panorámica no encontrada.');
   $pdo->exec('RESET ROLE');AuditTrail::event($pdo,'property.panorama_removed',$user);TenantAccess::scope($pdo,$user);http_response_code(204);return true;
  }
  TenantAccess::deny(405,'Método no permitido.');
 }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['message'=>$e->getMessage()]);return true;}
 catch(Throwable $e){error_log('Panorama: '.$e->getMessage());http_response_code(500);echo json_encode(['message'=>'No se pudo completar la operación 360°.']);return true;}
}
