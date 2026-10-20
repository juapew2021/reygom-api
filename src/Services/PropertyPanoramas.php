<?php
declare(strict_types=1);

final class PropertyPanoramas {
 public static function list(PDO $pdo,string $property):array {
  $s=$pdo->prepare('SELECT id,property_id,label,width,height FROM property_panoramas WHERE property_id=? AND removed_at IS NULL ORDER BY created_at,id');$s->execute([$property]);
  return array_map(function($row){$row['image_url']=rtrim(getenv('PANORAMA_PUBLIC_BASE_URL')?:'https://api-dev.reygom.com','/').'/public/properties/'.$row['property_id'].'/panoramas/'.$row['id'].'/image';return $row;},$s->fetchAll(PDO::FETCH_ASSOC));
 }
 public static function validate(string $path,int $size,string $label):array {
  if($size<1||$size>15*1024*1024)throw new InvalidArgumentException('La panorámica debe ocupar como máximo 15 MB.');
  if(!preg_match('/^.{1,80}$/usD',trim($label))||preg_match('/[\x00-\x1f<>]/u',$label))throw new InvalidArgumentException('Indica una estancia de 1 a 80 caracteres, sin etiquetas HTML.');
  $info=@getimagesize($path);
  if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true))throw new InvalidArgumentException('Utiliza una imagen JPG, PNG o WebP.');
  [$w,$h]=$info;
  if($w<1024||$w>8192||$h*2!==$w)throw new InvalidArgumentException('Necesitamos una panorámica esférica completa 2:1, de 1024 × 512 a 8192 × 4096 píxeles. Una foto normal no sirve como vista 360°.');
  return [$w,$h];
 }
 public static function upload(PDO $pdo,string $property,array $file,string $label):array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('No se pudo recibir la panorámica. Máximo 15 MB.');
  [$w,$h]=self::validate($file['tmp_name'],(int)$file['size'],$label);
  $image=@imagecreatefromstring(file_get_contents($file['tmp_name']));
  if(!$image)throw new InvalidArgumentException('La imagen está dañada o no se puede procesar.');
  $width=min(4096,$w);$height=(int)($width/2);
  if($w>$width){$scaled=imagescale($image,$width,$height);imagedestroy($image);$image=$scaled;}
  if(!$image)throw new RuntimeException('No se pudo preparar la imagen.');
  $id=bin2hex(random_bytes(16));$id=substr($id,0,8).'-'.substr($id,8,4).'-'.substr($id,12,4).'-'.substr($id,16,4).'-'.substr($id,20);
  $name=str_replace('-','',$id).'.webp';$dir='/var/www/media/panoramas';
  if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No se pudo preparar el almacenamiento.');
  $path=$dir.'/'.$name;
  try {
   $pdo->beginTransaction();
   $s=$pdo->prepare('SELECT id FROM properties WHERE id=? FOR UPDATE');$s->execute([$property]);if(!$s->fetchColumn())throw new InvalidArgumentException('Inmueble no encontrado.');
   $s=$pdo->prepare('SELECT count(*) FROM property_panoramas WHERE property_id=? AND removed_at IS NULL');$s->execute([$property]);if((int)$s->fetchColumn()>=10)throw new InvalidArgumentException('Se permiten hasta 10 estancias 360° por inmueble.');
   if(!imagewebp($image,$path,85))throw new RuntimeException('No se pudo guardar la panorámica.');
   chmod($path,0640);
   $s=$pdo->prepare('INSERT INTO property_panoramas(id,property_id,label,filename,width,height) VALUES(?,?,?,?,?,?)');$s->execute([$id,$property,trim($label),$name,$width,$height]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(is_file($path))unlink($path);throw $e;}finally{imagedestroy($image);}
  return self::list($pdo,$property);
 }
}
