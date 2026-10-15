<?php
declare(strict_types=1);
final class MailProblem extends RuntimeException {
 public function __construct(string $message,public int $status=503){parent::__construct($message);}
}
final class MailService {
 public function __construct(private PDO $db,private string $office,private ?Closure $transport=null){}
 public static function configured():bool{return getenv('MAIL_ENABLED')==='1' && getenv('MAIL_OFFICE_ID') && getenv('MAIL_TOKEN_CIPHER') && getenv('MAIL_MASTER_KEY') && getenv('MAIL_RESOURCE_ID');}
 private function allowed():void{if(!self::configured()||!hash_equals((string)getenv('MAIL_OFFICE_ID'),$this->office))throw new MailProblem('Esta oficina no tiene un buzón conectado.',404);}
 public function status():array{$this->allowed();return ['address'=>'info@reygom.com','provider'=>'Hostinger','automated_verification'=>getenv('SUBMISSIONS_MODE')==='mail'];}
 public static function folder(mixed $folder):string{if(!is_string($folder)||!$folder||strlen($folder)>200||preg_match('/[\x00-\x1f]/',$folder))throw new MailProblem('Carpeta no válida.',422);return $folder;}
 public static function compose(array $input):array{
  $to=$input['to']??[];if(!is_array($to)||count($to)<1||count($to)>10)throw new MailProblem('Indica entre uno y diez destinatarios.',422);
  foreach($to as $email)if(!is_string($email)||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))throw new MailProblem('Correo de destino no válido.',422);
  $subject=$input['subject']??'';$text=$input['text']??'';
  if(!is_string($subject)||!trim($subject)||strlen($subject)>300||preg_match('/[\x00-\x1f]/',$subject)||!is_string($text)||!trim($text)||strlen($text)>100000)throw new MailProblem('Revisa el asunto y el contenido del correo.',422);
  $out=['to'=>array_values(array_unique($to)),'subject'=>$subject,'text'=>$text,'displayName'=>'ReyGom'];
  if(isset($input['inReplyTo'])){$r=$input['inReplyTo'];if(!is_array($r)||!is_int($r['uid']??null)||$r['uid']<1)throw new MailProblem('Mensaje de respuesta no válido.',422);$out['inReplyTo']=['uid'=>$r['uid'],'folder'=>self::folder($r['folder']??null)];}
  return $out;
 }
 private function base():string{return '/api/v1/mailboxes/'.rawurlencode((string)getenv('MAIL_RESOURCE_ID'));}
 private function request(string $path,?array $body=null):array{
  $this->allowed();if($this->transport!==null)return ($this->transport)($path,$body);$key=base64_decode((string)getenv('MAIL_MASTER_KEY'),true);$cipher=base64_decode((string)getenv('MAIL_TOKEN_CIPHER'),true);
  if($key===false||strlen($key)!==32||$cipher===false||strlen($cipher)<40)throw new MailProblem('La conexión de correo necesita revisión.');
  $token=sodium_crypto_secretbox_open(substr($cipher,24),substr($cipher,0,24),$key);if($token===false)throw new MailProblem('La conexión de correo necesita revisión.');
  $ch=curl_init('https://api.mail.hostinger.com'.$path);$buffer='';
  curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json'],CURLOPT_USERAGENT=>'ReyGom-CRM/0.5',CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($c,$part)use(&$buffer){if(strlen($buffer)+strlen($part)>4*1024*1024)return 0;$buffer.=$part;return strlen($part);}]);
  if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
  $ok=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);sodium_memzero($token);
  if($ok===false||$code<200||$code>=300)throw new MailProblem($code===429?'El proveedor ha limitado temporalmente las consultas. Espera unos minutos.':'No se pudo confirmar la operación con Hostinger. No repitas un envío sin comprobar antes la carpeta Enviados.',$code===429?429:502);
  if($buffer==='')return []; $data=json_decode($buffer,true);if(!is_array($data))throw new MailProblem('Respuesta de correo no válida.',502);return $data;
 }
 public function folders():array{return $this->request($this->base().'/folders?perPage=100');}
 public function messages(string $folder,int $page):array{if($page<1||$page>1000)throw new MailProblem('Página no válida.',422);return $this->request($this->base().'/folders/'.rawurlencode(self::folder($folder)).'/messages?page='.$page.'&perPage=25&sort=-uid');}
 public function message(string $folder,int $uid):array{
  if($uid<1)throw new MailProblem('Mensaje no válido.',422);$path=$this->base().'/folders/'.rawurlencode(self::folder($folder)).'/messages/'.$uid;
  $meta=$this->request($path)['data']??[];$body=$this->request($path.'/text')['data']??[];
  // Never render provider HTML or load remote tracking images in the CRM.
  $meta['body_text']=$body['text']??'';if(!$meta['body_text'])$meta['body_text']=html_entity_decode(strip_tags((string)($body['html']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
  unset($meta['html'],$meta['text']);return $meta;
 }
 public function send(array $input,string $key,?string $actor=null,string $kind='manual'):array{
  $this->allowed();$body=self::compose($input);if(!preg_match('/^[a-zA-Z0-9-]{16,100}$/',$key))throw new MailProblem('Identificador de envío no válido.',422);
  $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
  $this->db->beginTransaction();
  try{
   $s=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');$s->execute(['mail:'.$this->office]);
   $s=$this->db->prepare('SELECT payload_hash,status FROM mail_deliveries WHERE organization_id=? AND request_key=?');$s->execute([$this->office,$key]);$prior=$s->fetch(PDO::FETCH_ASSOC);
   if($prior){$this->db->commit();if(!hash_equals($prior['payload_hash'],$hash))throw new MailProblem('Este identificador ya corresponde a otro envío.',409);return ['status'=>$prior['status'],'duplicate'=>true];}
   $s=$this->db->prepare("SELECT count(*) FROM mail_deliveries WHERE organization_id=? AND created_at>now()-interval '1 hour'");$s->execute([$this->office]);if((int)$s->fetchColumn()>=50)throw new MailProblem('Se ha alcanzado el límite de 50 envíos por hora para esta oficina.',429);
   $s=$this->db->prepare("INSERT INTO mail_deliveries(organization_id,request_key,payload_hash,actor_id,kind,status) VALUES(?,?,?,?,?,'sending')");$s->execute([$this->office,$key,$hash,$actor,$kind]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  try{$this->request($this->base().'/send',$body);$status='sent';}catch(Throwable $e){$s=$this->db->prepare("UPDATE mail_deliveries SET status='unknown',updated_at=now() WHERE organization_id=? AND request_key=?");$s->execute([$this->office,$key]);throw $e;}
  $s=$this->db->prepare('UPDATE mail_deliveries SET status=?,updated_at=now() WHERE organization_id=? AND request_key=?');$s->execute([$status,$this->office,$key]);
  return ['status'=>$status];
 }
}
