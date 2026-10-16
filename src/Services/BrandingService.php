<?php
declare(strict_types=1);
final class BrandingProblem extends RuntimeException{
 public function __construct(string $message,public int $status=422){parent::__construct($message);}
}
final class BrandingService{
 public function __construct(private PDO $db,private array $user){}
 public static function colors(array $input):array{
  $result=[];
  foreach(['primary_color','sidebar_color'] as $name){$color=$input[$name]??null;if(!is_string($color)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$color))throw new BrandingProblem('Selecciona colores válidos.');$result[$name]=strtolower($color);}
  return $result;
 }
 public static function image(array $file,string $kind):string{
  if(($file['error']??-1)!==UPLOAD_ERR_OK||($file['size']??0)<=0||$file['size']>2*1024*1024||!is_uploaded_file($file['tmp_name']??''))throw new BrandingProblem('Sube una imagen JPG, PNG o WebP de hasta 2 MB.');
  return self::normalizeImage($file['tmp_name'],$kind);
 }
 public static function normalizeImage(string $path,string $kind):string{
  $info=@getimagesize($path);
  if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||$info[0]*$info[1]>8000000||$info[0]<1||$info[1]<1)throw new BrandingProblem('La imagen debe ser JPG, PNG o WebP y no superar 8 megapíxeles.');
  $src=match($info['mime']){'image/jpeg'=>@imagecreatefromjpeg($path),'image/png'=>@imagecreatefrompng($path),'image/webp'=>@imagecreatefromwebp($path)};
  if(!$src)throw new BrandingProblem('No se pudo leer la imagen.');
  try{
   if($kind==='photo'){$side=min($info[0],$info[1]);$sx=(int)(($info[0]-$side)/2);$sy=(int)(($info[1]-$side)/2);$sw=$sh=$side;$w=$h=256;}
   else{$sx=$sy=0;$sw=$info[0];$sh=$info[1];$scale=min(1,512/$sw,256/$sh);$w=max(1,(int)round($sw*$scale));$h=max(1,(int)round($sh*$scale));}
   $dst=imagecreatetruecolor($w,$h);imagealphablending($dst,false);imagesavealpha($dst,true);imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
   imagecopyresampled($dst,$src,0,0,$sx,$sy,$w,$h,$sw,$sh);
   ob_start();$ok=imagewebp($dst,null,85);$bytes=ob_get_clean();imagedestroy($dst);
   if(!$ok||!is_string($bytes)||strlen($bytes)>140000)throw new BrandingProblem('La imagen es demasiado compleja. Prueba con otra de menor tamaño.');
   return 'data:image/webp;base64,'.base64_encode($bytes);
  }finally{imagedestroy($src);}
 }
 private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
 private function manager():void{if(!in_array($this->user['role'],['admin','superadmin'],true))throw new BrandingProblem('Solo el administrador puede personalizar la inmobiliaria.',403);}
 public function branding():array{
  $r=$this->query('SELECT display_name,primary_color,sidebar_color,logo,version FROM office_branding WHERE organization_id=?',[$this->user['organization_id']])->fetch(PDO::FETCH_ASSOC);
  $r=$r?:['display_name'=>'','primary_color'=>'#e02424','sidebar_color'=>'#000000','logo'=>null,'version'=>0];$r['office_name']=$this->user['office_name'];$r['can_edit']=in_array($this->user['role'],['admin','superadmin'],true);return $r;
 }
 public function update(array $data):array{
  $this->manager();$colors=self::colors($data);$name=$data['display_name']??'';
  if(!is_string($name)||mb_strlen(trim($name))>150||preg_match('/[\x00-\x1f]/',$name)||!isset($data['version'])||!is_int($data['version'])||$data['version']<0)throw new BrandingProblem('Revisa el nombre comercial y vuelve a cargar la página si es necesario.');
  $this->db->beginTransaction();
  try{
   $this->query('SELECT pg_advisory_xact_lock(hashtext(?))',['branding:'.$this->user['organization_id']]);$old=$this->branding();
   if((int)$old['version']!==$data['version'])throw new BrandingProblem('Otro administrador ha cambiado la marca. Actualiza la página antes de guardar.',409);
   $this->query('INSERT INTO office_branding(organization_id,display_name,primary_color,sidebar_color) VALUES(?,?,?,?) ON CONFLICT(organization_id) DO UPDATE SET display_name=excluded.display_name,primary_color=excluded.primary_color,sidebar_color=excluded.sidebar_color,version=office_branding.version+1,updated_at=now()',[$this->user['organization_id'],trim($name),$colors['primary_color'],$colors['sidebar_color']]);
   $result=$this->branding();$this->db->commit();return $result;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function logo(array $file):array{
  $this->manager();$image=self::image($file,'logo');
  $this->query('INSERT INTO office_branding(organization_id,logo) VALUES(?,?) ON CONFLICT(organization_id) DO UPDATE SET logo=excluded.logo,version=office_branding.version+1,updated_at=now()',[$this->user['organization_id'],$image]);return $this->branding();
 }
 public function profile():array{
  $photo=$this->query('SELECT photo FROM user_profiles WHERE user_id=?',[$this->user['id']])->fetchColumn();
  return ['username'=>$this->user['username'],'role'=>$this->user['role'],'office_name'=>$this->user['office_name'],'photo'=>$photo?:null];
 }
 public function photo(array $file):array{
  $image=self::image($file,'photo');
  // The office of the authenticated account is authoritative, even while Super admin views another office.
  $this->query('INSERT INTO user_profiles(user_id,organization_id,photo) SELECT id,organization_id,? FROM users WHERE id=? ON CONFLICT(user_id) DO UPDATE SET photo=excluded.photo,updated_at=now()',[$image,$this->user['id']]);return $this->profile();
 }
}
