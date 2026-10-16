<?php
declare(strict_types=1);
final class NotificationProblem extends RuntimeException{public function __construct(string $message,public int $status=422){parent::__construct($message);}}
final class NotificationService{
 public function __construct(private PDO $db,private array $user){}
 private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
 public static function uuid(mixed $value):bool{return is_string($value)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$value)===1;}
 public function recipients():array{
  $where="u.is_active AND o.status='active' AND o.deleted_at IS NULL";$args=[];
  if($this->user['role']!=='superadmin'){
   // Use the account's actual office, never a client-supplied destination office.
   $where.=" AND u.organization_id=(SELECT organization_id FROM users WHERE id=?)";$args[]=$this->user['id'];
   $where.=$this->user['role']==='admin'?" AND u.role IN ('agent','agente')":" AND u.role IN ('admin','agent','agente')";
  }
  $rows=$this->query('SELECT u.id,u.username,u.role,o.name AS office_name FROM users u JOIN organizations o ON o.id=u.organization_id WHERE '.$where.' ORDER BY o.name,u.username LIMIT 1001',$args)->fetchAll(PDO::FETCH_ASSOC);
  if(count($rows)>1000)throw new NotificationProblem('Hay más de 1000 destinatarios. Contacta con el Super admin para organizar el envío por grupos.',422);
  return $rows;
 }
 public function unread():int{return (int)$this->query('SELECT count(*) FROM internal_notifications WHERE recipient_id=? AND read_at IS NULL',[$this->user['id']])->fetchColumn();}
 public function inbox(int $page,bool $unreadOnly=false):array{
  if($page<1||$page>100000)throw new NotificationProblem('Página no válida.');
  $where='recipient_id=?'.($unreadOnly?' AND read_at IS NULL':'');$total=(int)$this->query('SELECT count(*) FROM internal_notifications WHERE '.$where,[$this->user['id']])->fetchColumn();
  $rows=$this->query('SELECT id,sender_name,sender_role,title,body,created_at,read_at FROM internal_notifications WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.(($page-1)*25),[$this->user['id']])->fetchAll(PDO::FETCH_ASSOC);
  return ['rows'=>$rows,'total'=>$total,'unread'=>$this->unread(),'page'=>$page,'pages'=>max(1,(int)ceil($total/25))];
 }
 public function sent(int $page):array{
  if($page<1||$page>100000)throw new NotificationProblem('Página no válida.');
  $total=(int)$this->query('SELECT count(DISTINCT batch_id) FROM internal_notifications WHERE sender_id=?',[$this->user['id']])->fetchColumn();
  $rows=$this->query('SELECT batch_id,title,body,min(created_at) AS created_at,count(*) AS recipients,count(read_at) AS read_count FROM internal_notifications WHERE sender_id=? GROUP BY batch_id,title,body ORDER BY min(created_at) DESC,batch_id DESC LIMIT 25 OFFSET '.(($page-1)*25),[$this->user['id']])->fetchAll(PDO::FETCH_ASSOC);
  return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25))];
 }
 public function read(?string $id):array{
  if($id!==null&&!self::uuid($id))throw new NotificationProblem('Notificación no válida.',404);
  $sql='UPDATE internal_notifications SET read_at=COALESCE(read_at,now()) WHERE recipient_id=?';$args=[$this->user['id']];
  if($id!==null){$sql.=' AND id=?';$args[]=$id;}else $sql.=' AND read_at IS NULL';
  $s=$this->query($sql,$args);if($id!==null&&$s->rowCount()===0)throw new NotificationProblem('Notificación no encontrada.',404);
  return ['unread'=>$this->unread()];
 }
 public function send(array $data):array{
  $title=$data['title']??null;$body=$data['body']??null;$audience=$data['audience']??'';$key=$data['request_key']??null;
  if(!is_string($title)||!trim($title)||mb_strlen($title)>160||preg_match('/[\x00-\x1f]/',$title)||!is_string($body)||!trim($body)||mb_strlen($body)>4000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$body)||!in_array($audience,['all','selected'],true)||!self::uuid($key))throw new NotificationProblem('Revisa el asunto, el mensaje y los destinatarios.');
  $selected=$data['recipient_ids']??[];
  if(!is_array($selected)||count($selected)>1000)throw new NotificationProblem('Destinatarios no válidos.');
  foreach($selected as $id)if(!self::uuid($id))throw new NotificationProblem('Destinatario no válido.');
  $selected=array_values(array_unique(array_map('strtolower',$selected)));sort($selected);
  if($audience==='selected'&&!$selected)throw new NotificationProblem('Selecciona al menos un destinatario.');
  $hash=hash('sha256',json_encode([trim($title),trim($body),$audience,$selected],JSON_THROW_ON_ERROR));
  $this->db->beginTransaction();
  try{
   $this->query('SELECT pg_advisory_xact_lock(hashtext(?))',['notifications:'.$this->user['id']]);
   $old=$this->query('SELECT id,payload_hash FROM notification_batches WHERE sender_id=? AND request_key=?',[$this->user['id'],$key])->fetch(PDO::FETCH_ASSOC);
   if($old){if(!hash_equals($old['payload_hash'],$hash))throw new NotificationProblem('Este envío ya corresponde a otro mensaje.',409);$n=(int)$this->query('SELECT count(*) FROM internal_notifications WHERE batch_id=? AND sender_id=?',[$old['id'],$this->user['id']])->fetchColumn();$this->db->commit();return ['batch_id'=>$old['id'],'recipients'=>$n,'duplicate'=>true];}
   $allowed=$this->recipients();$lookup=array_column($allowed,null,'id');
   if($audience==='selected'){foreach($selected as $id)if(!isset($lookup[$id]))throw new NotificationProblem('No puedes notificar a alguno de los destinatarios seleccionados.',403);$allowed=array_map(fn($id)=>$lookup[$id],$selected);}
   if(!$allowed)throw new NotificationProblem('No hay destinatarios activos disponibles.');
   $rate=(int)$this->query("SELECT count(*) FROM notification_batches WHERE sender_id=? AND created_at>now()-interval '1 hour'",[$this->user['id']])->fetchColumn();if($rate>=20)throw new NotificationProblem('Has alcanzado el límite de 20 envíos por hora. Inténtalo más tarde.',429);
   $batch=$this->query('INSERT INTO notification_batches(sender_id,request_key,payload_hash) VALUES(?,?,?) RETURNING id',[$this->user['id'],$key,$hash])->fetchColumn();
   foreach($allowed as $to){
    $this->query('INSERT INTO internal_notifications(batch_id,sender_id,sender_name,sender_role,recipient_id,recipient_office_id,title,body) SELECT ?,?,?,?,u.id,u.organization_id,?,? FROM users u WHERE u.id=?',[$batch,$this->user['id'],$this->user['username'],$this->user['role'],trim($title),trim($body),$to['id']]);
   }
   $this->query('SELECT reygom_notification_audit(?)',[$batch]);
   $this->db->commit();return ['batch_id'=>$batch,'recipients'=>count($allowed),'duplicate'=>false];
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
