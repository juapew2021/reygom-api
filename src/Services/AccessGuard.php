<?php
declare(strict_types=1);

final class AccessGuard
{
    public const COOKIE = '__Host-reygom_device';

    public static function fail(int $status, string $message, array $detail=[]): never
    {
        http_response_code($status);
        echo json_encode(['message'=>$message]+$detail);
        exit;
    }

    public static function ip(): string
    {
        $peer=$_SERVER['REMOTE_ADDR']??'';
        $trusted=array_filter(array_map('trim',explode(',',getenv('ACCESS_TRUSTED_PROXIES')?:'')));
        if(getenv('ACCESS_REQUIRE_PROXY')==='1'&&!in_array($peer,$trusted,true)) self::fail(503,'El proxy de acceso necesita ser verificado por el administrador.');
        if(in_array($peer,$trusted,true)) {
            $value=$_SERVER['HTTP_X_REAL_IP']??'';
            if(!filter_var($value,FILTER_VALIDATE_IP)) self::fail(503,'No se ha podido verificar la IP de acceso.');
        } else {
            if(!filter_var($peer,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) self::fail(503,'El proxy de acceso necesita ser verificado por el administrador.');
            $value=$peer;
        }
        if(!filter_var($value,FILTER_VALIDATE_IP)) self::fail(503,'No se ha podido verificar la IP de acceso.');
        return inet_ntop(inet_pton($value));
    }

    public static function audit(PDO $pdo,string $action,array $actor,array $target,array $metadata): void
    {
        $s=$pdo->prepare("INSERT INTO office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,subject_ref,metadata,source) VALUES(?,?,?,?,?,?,'user',?,?,'application')");
        $s->execute([$actor['id'],$actor['username'],$target['organization_id'],$target['office_name']??null,$action,$target['id'],$target['username'],json_encode($metadata)]);
    }

    public static function device(PDO $pdo,array $user,bool $create): ?array
    {
        $secret=$_COOKIE[self::COOKIE]??'';
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret)) {
            if(!$create) return null;
            $secret=bin2hex(random_bytes(32));
            setcookie(self::COOKIE,$secret,['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        }
        $s=$pdo->prepare('SELECT id FROM access_devices WHERE user_id=? AND secret_hash=?');
        $s->execute([$user['id'],hash('sha256',$secret)]);$device=$s->fetch(PDO::FETCH_ASSOC);
        if(!$device&&$create) {
            // Serialise creation and cap unapproved devices to avoid unbounded credential-holder requests.
            $pdo->beginTransaction();
            try {
                $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$user['id']]);
                $s=$pdo->prepare('SELECT id FROM access_devices WHERE user_id=? AND secret_hash=?');$s->execute([$user['id'],hash('sha256',$secret)]);$device=$s->fetch(PDO::FETCH_ASSOC);
                if(!$device) {
                    $s=$pdo->prepare('SELECT count(*) FROM access_devices WHERE user_id=?');$s->execute([$user['id']]);
                    if((int)$s->fetchColumn()>=100) { $pdo->rollBack();self::fail(429,'Hay demasiados dispositivos registrados. Contacta con el Super admin.'); }
                    $ua=preg_replace('/[\x00-\x1f\x7f]/','',$_SERVER['HTTP_USER_AGENT']??'Navegador sin identificar');
                    $s=$pdo->prepare('INSERT INTO access_devices(user_id,secret_hash,label) VALUES(?,?,?) RETURNING id');
                    $s->execute([$user['id'],hash('sha256',$secret),substr($ua,0,150)]);$device=$s->fetch(PDO::FETCH_ASSOC);
                }
                $pdo->commit();
            } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        return $device?:null;
    }

    public static function check(PDO $pdo,array $user,?array $claims=null): ?string
    {
        if($user['role']==='superadmin') return null;
        $device=self::device($pdo,$user,$claims===null);
        if(!$device||($claims!==null&&($claims['device_id']??'')!==$device['id']))
            self::fail(401,'Inicia sesión desde este navegador para solicitar autorización.',['code'=>'ACCESS_LOGIN_REQUIRED']);
        $ip=self::ip();
        $s=$pdo->prepare('SELECT id FROM access_grants WHERE user_id=? AND device_id=? AND ip=?::inet AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>now())');
        $s->execute([$user['id'],$device['id'],$ip]);
        if($s->fetchColumn()) return $device['id'];
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$user['id']]);
            $s=$pdo->prepare('SELECT count(*) FROM access_requests WHERE user_id=?');$s->execute([$user['id']]);
            if((int)$s->fetchColumn()>=1000) { $pdo->rollBack();self::fail(429,'Hay demasiadas solicitudes. Contacta con el Super admin.'); }
            $s=$pdo->prepare("INSERT INTO access_requests(user_id,device_id,ip) VALUES(?,?,?::inet) ON CONFLICT(device_id,ip) DO UPDATE SET status='pending',requested_at=now() WHERE access_requests.status='approved' RETURNING id,status");
            $s->execute([$user['id'],$device['id'],$ip]);$request=$s->fetch(PDO::FETCH_ASSOC);
            if($request) self::audit($pdo,'access.requested',$user,$user,['device_id'=>$device['id'],'ip'=>$ip]);
            else {
                $s=$pdo->prepare('SELECT id,status FROM access_requests WHERE device_id=? AND ip=?::inet');$s->execute([$device['id'],$ip]);$request=$s->fetch(PDO::FETCH_ASSOC);
            }
            $pdo->commit();
        } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        self::fail(403,$request['status']==='rejected'?'El Super admin ha rechazado este acceso. Contacta con él.':'Este dispositivo o IP necesita la aprobación del Super admin. Una vez aprobado, inicia sesión de nuevo.',
            ['code'=>'ACCESS_APPROVAL_REQUIRED','request_id'=>$request['id'],'status'=>$request['status'],'ip'=>$ip]);
    }

    public static function target(PDO $pdo,string $id): array
    {
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$id)) self::fail(422,'Usuario no válido.');
        $s=$pdo->prepare('SELECT u.id,u.username,u.role,u.is_active,u.organization_id,o.name AS office_name,o.status AS office_status,o.deleted_at FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.id=?');
        $s->execute([$id]);$u=$s->fetch(PDO::FETCH_ASSOC);
        if(!$u||$u['role']==='superadmin') self::fail(422,'Selecciona un administrador de oficina o agente.');
        return $u;
    }

    public static function capacity(PDO $pdo,string $user,int $devices,int $ips): bool
    {
        $s=$pdo->prepare('SELECT device_id,count(DISTINCT ip) AS ips FROM access_grants WHERE user_id=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>now()) GROUP BY device_id');
        $s->execute([$user]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        return count($rows)<=$devices&&!array_filter($rows,fn($r)=>(int)$r['ips']>$ips);
    }
}
