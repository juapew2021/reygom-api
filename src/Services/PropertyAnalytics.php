<?php
declare(strict_types=1);

final class PropertyAnalytics
{
 public static function source(string $referrer): string {
  $host=strtolower((string)parse_url(substr($referrer,0,2048),PHP_URL_HOST));
  if($host==='')return 'Directo o desconocido';
  foreach(['google'=>'Google','bing'=>'Bing','facebook'=>'Facebook','instagram'=>'Instagram','whatsapp'=>'WhatsApp','reygom'=>'ReyGom'] as $domain=>$label)
   if(preg_match('/(^|\.)'.preg_quote($domain,'/').'\.[a-z.]+$/D',$host))return $label;
  return 'Otra web';
 }
 public static function location(string $ip):array {
  // The database is local: visitor IPs are never sent to a geolocation provider.
  $path=getenv('ANALYTICS_GEO_DB')?:'/opt/analytics/dbip-city-lite.mmdb';
  $autoload='/opt/analytics/vendor/autoload.php';
  if(!is_readable($path)||!is_readable($autoload))return [null,null,null];
  require_once $autoload;
  try {
   $reader=new \MaxMind\Db\Reader($path);$geo=$reader->get($ip);$reader->close();
   $name=static fn($value,$max)=>is_string($value)?(function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max)):null;
   return [$name($geo['country']['names']['es']??$geo['country']['names']['en']??null,100),$name($geo['subdivisions'][0]['names']['es']??$geo['subdivisions'][0]['names']['en']??null,120),$name($geo['city']['names']['es']??$geo['city']['names']['en']??null,120)];
  }catch(Throwable $e){error_log('Analytics geo lookup unavailable');return [null,null,null];}
 }
 public static function collect(PDO $pdo,string $propertyId,array $body):void {
  if(($body['consent']??null)!==true)TenantAccess::deny(422,'Se necesita aceptar las estadísticas.');
  $id=$body['visit_id']??'';$token=$body['token']??'';$seconds=$body['seconds']??null;
  if(!is_string($id)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$id)||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!is_int($seconds)||$seconds<0||$seconds>1800)TenantAccess::deny(422,'Visita no válida.');
  $ip=AccessGuard::ip();
  $s=$pdo->prepare("SELECT id FROM properties WHERE id=? AND status='Disponible'");$s->execute([$propertyId]);if(!$s->fetchColumn())TenantAccess::deny(404,'Inmueble no disponible.');
  $hash=hash('sha256',$token);
  $s=$pdo->prepare('SELECT token_hash FROM property_visits WHERE id=? AND property_id=?');$s->execute([$id,$propertyId]);$existing=$s->fetchColumn();
  if($existing!==false) {
   if(!hash_equals($existing,$hash))TenantAccess::deny(404,'Visita no encontrada.');
   $s=$pdo->prepare("UPDATE property_visits SET active_seconds=greatest(active_seconds,least(:seconds,1800,floor(extract(epoch FROM now()-started_at))::integer+2)),updated_at=now() WHERE id=:id AND property_id=:property AND started_at>now()-interval '2 hours'");
   $s->execute(['seconds'=>$seconds,'id'=>$id,'property'=>$propertyId]);return;
  }
  // An update arriving before its start may not manufacture an elapsed visit.
  if($seconds!==0)TenantAccess::deny(409,'La visita no se ha iniciado.');
  $bucket=hash_hmac('sha256',gmdate('Y-m-d-H').'|'.$ip,(string)getenv('JWT_SECRET'));
  $s=$pdo->prepare("INSERT INTO property_visit_limits(bucket,hits,expires_at) VALUES(?,1,now()+interval '2 hours') ON CONFLICT(bucket) DO UPDATE SET hits=property_visit_limits.hits+1 WHERE property_visit_limits.hits<120 RETURNING hits");$s->execute([$bucket]);if(!$s->fetchColumn())TenantAccess::deny(429,'Demasiadas visitas.');
  [$country,$region,$city]=self::location($ip);
  $s=$pdo->prepare('INSERT INTO property_visits(id,property_id,token_hash,source,country,region,city) VALUES(?,?,?,?,?,?,?) ON CONFLICT(id) DO NOTHING');
  $s->execute([$id,$propertyId,$hash,self::source(is_string($body['referrer']??null)?$body['referrer']:''),$country,$region,$city]);
  // Bounded retention housekeeping; no IP addresses or visitor identities are stored.
  $pdo->exec("DELETE FROM property_visit_limits WHERE bucket IN(SELECT bucket FROM property_visit_limits WHERE expires_at<now() LIMIT 200)");
  $pdo->exec("DELETE FROM property_visits WHERE id IN(SELECT id FROM property_visits WHERE started_at<now()-interval '90 days' LIMIT 200)");
 }
 public static function report(PDO $pdo,string $id,int $days):array {
  $s=$pdo->prepare('SELECT id,title,public_reference FROM properties WHERE id=?');$s->execute([$id]);$property=$s->fetch(PDO::FETCH_ASSOC);if(!$property)TenantAccess::deny(404,'Inmueble no encontrado.');
  $where="property_id=:id AND started_at>=now()-make_interval(days => :days)";$args=['id'=>$id,'days'=>$days];
  $s=$pdo->prepare("SELECT count(*)::integer AS visits,coalesce(sum(active_seconds),0)::integer AS active_seconds,coalesce(round(avg(active_seconds)),0)::integer AS average_seconds FROM property_visits WHERE $where");$s->execute($args);$summary=$s->fetch(PDO::FETCH_ASSOC);
  $groups=[];
  foreach(['sources'=>"source",'locations'=>"coalesce(nullif(concat_ws(' · ',city,region,country),''),'Ubicación no disponible')"] as $key=>$expr) {
   $s=$pdo->prepare("SELECT $expr AS label,count(*)::integer AS visits,round(avg(active_seconds))::integer AS average_seconds FROM property_visits WHERE $where GROUP BY 1 ORDER BY visits DESC,label LIMIT 20");$s->execute($args);$groups[$key]=$s->fetchAll(PDO::FETCH_ASSOC);
  }
  $s=$pdo->prepare("SELECT to_char(started_at AT TIME ZONE 'UTC','YYYY-MM-DD') AS day,count(*)::integer AS visits FROM property_visits WHERE $where GROUP BY 1 ORDER BY 1");$s->execute($args);
  $daily=$s->fetchAll(PDO::FETCH_ASSOC);
  $s=$pdo->prepare("SELECT started_at,source,country,region,city,active_seconds FROM property_visits WHERE $where ORDER BY started_at DESC,id DESC LIMIT 50");$s->execute($args);
  return ['property'=>$property,'days'=>$days,'summary'=>$summary,'daily'=>$daily,'recent'=>$s->fetchAll(PDO::FETCH_ASSOC)]+$groups;
 }
}
