<?php
declare(strict_types=1);
final class AgendaProblem extends RuntimeException {
 public function __construct(string $message,public int $status=422){parent::__construct($message);}
}
final class AgendaService {
 public const ZONES=['Europe/Madrid','Atlantic/Canary','America/Bogota'];
 public static function text(mixed $value,int $max,bool $required=false):string {
  if(!is_string($value)||strlen($value)>$max*4||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f<>]/u',$value))throw new AgendaProblem('Texto no válido. No se permiten etiquetas HTML.');
  $value=trim($value);if(($required&&$value==='')||preg_match_all('/./us',$value)>$max)throw new AgendaProblem('Revisa la longitud de los campos.');return $value;
 }
 public static function localTime(string $value,string $zone):DateTimeImmutable {
  if(!in_array($zone,self::ZONES,true)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value))throw new AgendaProblem('Fecha o zona horaria no válida.');
  $tz=new DateTimeZone($zone);$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$tz);
  if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new AgendaProblem('Esa hora no existe en la zona horaria elegida.');
  // Reject repeated wall-clock times rather than silently scheduling the wrong DST occurrence.
  $wall=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'))->getTimestamp();$matches=[];
  foreach($tz->getTransitions($date->getTimestamp()-86400,$date->getTimestamp()+86400) as $transition){$candidate=$wall-$transition['offset'];if((new DateTimeImmutable('@'.$candidate))->setTimezone($tz)->format('Y-m-d\TH:i')===$value)$matches[$candidate]=true;}
  if(count($matches)>1)throw new AgendaProblem('Esa hora se repite por el cambio de horario. Elige una hora fuera de ese tramo.');
  return $date;
 }
 public static function validate(array $body):array {
  $zone=$body['timezone']??'';if(!is_string($zone)||!in_array($zone,self::ZONES,true))throw new AgendaProblem('Selecciona una zona horaria válida.');
  $kind=$body['kind']??'';$status=$body['status']??'scheduled';if(!in_array($kind,['visit','meeting','task'],true)||!in_array($status,['scheduled','completed','cancelled'],true))throw new AgendaProblem('Tipo o estado no válido.');
  $start=self::localTime(is_string($body['starts_at']??null)?$body['starts_at']:'',$zone);$end=self::localTime(is_string($body['ends_at']??null)?$body['ends_at']:'',$zone);
  if($end<=$start||$end->getTimestamp()-$start->getTimestamp()>86400)throw new AgendaProblem('La cita debe terminar después de empezar y durar como máximo 24 horas.');
  if((int)$start->format('Y')<2020||(int)$start->format('Y')>2100)throw new AgendaProblem('Fecha fuera del periodo permitido.');
  $property=$body['property_id']??null;if($property==='')$property=null;
  if($property!==null&&(!is_string($property)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$property)))throw new AgendaProblem('Inmueble no válido.');
  if($kind==='visit'&&$status==='scheduled'&&$property===null)throw new AgendaProblem('Selecciona el inmueble de la visita.');
  return ['title'=>self::text($body['title']??'',160,true),'kind'=>$kind,'starts_at'=>$start->format(DATE_ATOM),'ends_at'=>$end->format(DATE_ATOM),'timezone'=>$zone,'property_id'=>$property,'location'=>self::text($body['location']??'',300),'notes'=>self::text($body['notes']??'',2000),'status'=>$status];
 }
 public static function context(PDO $pdo,array $user):array {
  $manager=in_array($user['role'],['admin','superadmin'],true);
  $people=[['id'=>$user['id'],'username'=>$user['username'],'role'=>$user['role']]];
  if($manager){$s=$pdo->prepare('SELECT id,username,role FROM users WHERE organization_id=? AND is_active ORDER BY username');$s->execute([$user['organization_id']]);$people=$s->fetchAll(PDO::FETCH_ASSOC);if(!in_array($user['id'],array_column($people,'id'),true))array_unshift($people,['id'=>$user['id'],'username'=>$user['username'],'role'=>$user['role']]);}
  return ['user_id'=>$user['id'],'can_view_team'=>$manager,'timezone'=>($user['country_code']??'ES')==='CO'?'America/Bogota':'Europe/Madrid','people'=>$people];
 }
 public static function list(PDO $pdo,array $user,string $from,string $to,?string $owner):array {
  if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(Z|[+-]\d{2}:\d{2})$/D',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(Z|[+-]\d{2}:\d{2})$/D',$to))throw new AgendaProblem('Periodo no válido.');
  try{$a=new DateTimeImmutable($from);$errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException();$b=new DateTimeImmutable($to);$errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException();}catch(Throwable){throw new AgendaProblem('Periodo no válido.');}
  if($b<=$a||$b->getTimestamp()-$a->getTimestamp()>93*86400)throw new AgendaProblem('Consulta un periodo de hasta 93 días.');
  if($owner!==null&&!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$owner))throw new AgendaProblem('Usuario no válido.');
  if($owner!==null&&$user['role']==='agent'&&$owner!==$user['id'])throw new AgendaProblem('Solo puedes consultar tu agenda.',403);
  $where=$owner!==null?' AND e.owner_id=:owner':'';
  $s=$pdo->prepare('SELECT e.*,u.username AS owner_name,p.public_reference,p.title AS property_title FROM agenda_events e JOIN users u ON u.id=e.owner_id LEFT JOIN properties p ON p.id=e.property_id WHERE e.starts_at<:to AND e.ends_at>:from'.$where.' ORDER BY e.starts_at,e.id LIMIT 1001');
  $args=['from'=>$a->format(DATE_ATOM),'to'=>$b->format(DATE_ATOM)];if($owner!==null)$args['owner']=$owner;$s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
  if(count($rows)>1000)throw new AgendaProblem('Hay demasiadas citas. Filtra por agente o consulta menos días.');
  foreach($rows as &$row){$row['can_edit']=$row['owner_id']===$user['id'];$row['starts_at']=(new DateTimeImmutable($row['starts_at']))->format(DATE_ATOM);$row['ends_at']=(new DateTimeImmutable($row['ends_at']))->format(DATE_ATOM);}return $rows;
 }
 public static function save(PDO $pdo,array $user,array $body,?string $id):array {
  foreach(['owner_id','organization_id','created_by'] as $field)if(array_key_exists($field,$body))throw new AgendaProblem('No puedes cambiar el responsable ni la oficina.');
  $data=self::validate($body);
  if($data['property_id']!==null){$s=$pdo->prepare('SELECT id FROM properties WHERE id=?');$s->execute([$data['property_id']]);if(!$s->fetchColumn())throw new AgendaProblem('El inmueble no pertenece a tu oficina.',404);}
  $pdo->beginTransaction();
  try {
   $s=$pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$s->execute([$user['organization_id'].':'.$user['id']]);
   if($id!==null){$s=$pdo->prepare('SELECT owner_id FROM agenda_events WHERE id=?');$s->execute([$id]);$owner=$s->fetchColumn();if(!$owner)throw new AgendaProblem('Cita no encontrada.',404);if($owner!==$user['id'])throw new AgendaProblem('Solo puedes modificar tus propias citas.',403);}
   if($data['status']==='scheduled'){
    $s=$pdo->prepare("SELECT id FROM agenda_events WHERE owner_id=:owner AND status='scheduled' AND starts_at<:end AND ends_at>:start AND (CAST(:id AS uuid) IS NULL OR id<>CAST(:other_id AS uuid)) LIMIT 1");
    $s->execute(['owner'=>$user['id'],'start'=>$data['starts_at'],'end'=>$data['ends_at'],'id'=>$id,'other_id'=>$id]);if($s->fetchColumn())throw new AgendaProblem('Ya tienes otra cita en ese horario. Elige otra hora o cancela la anterior.',409);
   }
   if($id===null){
    $sql='INSERT INTO agenda_events(organization_id,owner_id,title,kind,starts_at,ends_at,timezone,property_id,location,notes,status) VALUES(:organization,:owner,:title,:kind,:starts_at,:ends_at,:timezone,:property_id,:location,:notes,:status) RETURNING *';$data+=['organization'=>$user['organization_id'],'owner'=>$user['id']];
   }else{$sql='UPDATE agenda_events SET title=:title,kind=:kind,starts_at=:starts_at,ends_at=:ends_at,timezone=:timezone,property_id=:property_id,location=:location,notes=:notes,status=:status,updated_at=now() WHERE id=:id RETURNING *';$data['id']=$id;}
   $s=$pdo->prepare($sql);$s->execute($data);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new AgendaProblem('Cita no encontrada.',404);
   // The protected mutation has completed. Audit with the application role, then restore office scope.
   $pdo->exec('RESET ROLE');AuditTrail::event($pdo,$id===null?'agenda.created':'agenda.updated',$user);TenantAccess::scope($pdo,$user);$pdo->commit();$row['can_edit']=true;$row['starts_at']=(new DateTimeImmutable($row['starts_at']))->format(DATE_ATOM);$row['ends_at']=(new DateTimeImmutable($row['ends_at']))->format(DATE_ATOM);return $row;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
}
