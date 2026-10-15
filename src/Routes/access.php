<?php
declare(strict_types=1);
function handleAccessRoutes(string $uri,string $method,PDO $pdo): bool
{
    if($uri!=='/access'&&!str_starts_with($uri,'/access/'))return false;
    $actor=JwtMiddleware::authenticate(false);
    if($actor['role']!=='superadmin')AccessGuard::fail(403,'Solo el Super admin puede autorizar accesos.');
    if($uri==='/access'&&$method==='GET') {
        $users=$pdo->query("SELECT u.id,u.username,u.role,u.is_active,o.name AS office_name,COALESCE(p.max_devices,1) AS max_devices,COALESCE(p.max_ips,1) AS max_ips FROM users u JOIN organizations o ON o.id=u.organization_id LEFT JOIN user_access_policies p ON p.user_id=u.id WHERE u.role<>'superadmin' ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC);
        $requests=$pdo->query("SELECT r.id,r.user_id,r.device_id,host(r.ip) AS ip,r.status,r.requested_at,d.label,u.username,o.name AS office_name FROM access_requests r JOIN access_devices d ON d.id=r.device_id JOIN users u ON u.id=r.user_id JOIN organizations o ON o.id=u.organization_id ORDER BY (r.status='pending') DESC,r.requested_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $grants=$pdo->query('SELECT g.id,g.user_id,g.device_id,host(g.ip) AS ip,g.expires_at,g.revoked_at,g.created_at,d.label,u.username,o.name AS office_name,(g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at>now())) AS active FROM access_grants g JOIN access_devices d ON d.id=g.device_id JOIN users u ON u.id=g.user_id JOIN organizations o ON o.id=u.organization_id ORDER BY g.created_at DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $connection=['peer'=>$_SERVER['REMOTE_ADDR']??'','ip'=>AccessGuard::ip()];
        echo json_encode(compact('users','requests','grants','connection'));return true;
    }
    if(!in_array($method,['POST','PUT'],true))AccessGuard::fail(405,'Método no permitido.');
    $body=json_decode(file_get_contents('php://input'),true);
    if(!is_array($body))AccessGuard::fail(422,'Datos no válidos.');
    if(preg_match('#^/access/policies/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$#Di',$uri,$m)&&$method==='PUT') {
        $target=AccessGuard::target($pdo,$m[1]);
        $devices=$body['max_devices']??null;$ips=$body['max_ips']??null;
        if(!is_int($devices)||!is_int($ips)||$devices<1||$devices>20||$ips<1||$ips>20)AccessGuard::fail(422,'Los límites deben estar entre 1 y 20.');
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$target['id']]);
            if(!AccessGuard::capacity($pdo,$target['id'],$devices,$ips)) { $pdo->rollBack();AccessGuard::fail(409,'Revoca primero las autorizaciones que superan los nuevos límites.'); }
            $s=$pdo->prepare('INSERT INTO user_access_policies(user_id,max_devices,max_ips) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET max_devices=excluded.max_devices,max_ips=excluded.max_ips');$s->execute([$target['id'],$devices,$ips]);
            AccessGuard::audit($pdo,'access.policy_updated',$actor,$target,['max_devices'=>$devices,'max_ips'=>$ips]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        echo json_encode(['message'=>'Límites guardados.']);return true;
    }
    if(preg_match('#^/access/requests/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/(approve|reject)$#Di',$uri,$m)&&$method==='POST') {
        $s=$pdo->prepare('SELECT user_id FROM access_requests WHERE id=?');$s->execute([$m[1]]);$uid=$s->fetchColumn();
        if(!$uid)AccessGuard::fail(404,'Solicitud no encontrada.');
        $target=AccessGuard::target($pdo,$uid);
        $expires=null;
        if($m[2]==='approve') {
            $duration=$body['duration']??'';
            $hours=['1h'=>1,'8h'=>8,'24h'=>24,'7d'=>168,'30d'=>720];
            if(isset($hours[$duration]))$expires=gmdate('c',time()+$hours[$duration]*3600);
            elseif($duration==='custom') {
                $raw=$body['expires_at']??'';
                if(!is_string($raw)||!preg_match('/T.*(?:Z|[+-]\d{2}:\d{2})$/D',$raw))AccessGuard::fail(422,'Indica una fecha con zona horaria.');
                try{$d=new DateTimeImmutable($raw);}catch(Throwable){AccessGuard::fail(422,'Fecha no válida.');}
                $errors=DateTimeImmutable::getLastErrors();
                if($errors!==false&&($errors['warning_count']||$errors['error_count']))AccessGuard::fail(422,'Fecha no válida.');
                if($d->getTimestamp()<=time())AccessGuard::fail(422,'La caducidad debe estar en el futuro.');
                $expires=$d->format(DATE_ATOM);
            }elseif($duration!=='permanent')AccessGuard::fail(422,'Selecciona la vigencia.');
        }
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT id,is_active FROM users WHERE id=? FOR UPDATE');$s->execute([$uid]);$locked=$s->fetch(PDO::FETCH_ASSOC);
            $s=$pdo->prepare('SELECT * FROM access_requests WHERE id=? FOR UPDATE');$s->execute([$m[1]]);$r=$s->fetch(PDO::FETCH_ASSOC);
            if($m[2]==='approve') {
                $s=$pdo->prepare('SELECT status,deleted_at FROM organizations WHERE id=? FOR SHARE');$s->execute([$target['organization_id']]);$office=$s->fetch(PDO::FETCH_ASSOC);
                if(!$locked['is_active']||$office['status']!=='active'||$office['deleted_at']) { $pdo->rollBack();AccessGuard::fail(409,'Activa primero el usuario y su oficina.'); }
                $s=$pdo->prepare('UPDATE access_grants SET revoked_at=now(),revoked_by=? WHERE device_id=? AND ip=?::inet AND revoked_at IS NULL');$s->execute([$actor['id'],$r['device_id'],$r['ip']]);
                $s=$pdo->prepare('INSERT INTO access_grants(user_id,device_id,ip,expires_at,created_by) VALUES(?,?,?::inet,?,?)');$s->execute([$uid,$r['device_id'],$r['ip'],$expires,$actor['id']]);
                $s=$pdo->prepare('SELECT max_devices,max_ips FROM user_access_policies WHERE user_id=?');$s->execute([$uid]);$policy=$s->fetch(PDO::FETCH_ASSOC)?:['max_devices'=>1,'max_ips'=>1];
                if(!AccessGuard::capacity($pdo,$uid,(int)$policy['max_devices'],(int)$policy['max_ips'])) { $pdo->rollBack();AccessGuard::fail(409,'Se supera el límite de dispositivos o IP. Ajusta los límites o revoca una autorización.'); }
            } else {
                $s=$pdo->prepare('UPDATE access_grants SET revoked_at=now(),revoked_by=? WHERE device_id=? AND ip=?::inet AND revoked_at IS NULL');$s->execute([$actor['id'],$r['device_id'],$r['ip']]);
            }
            $state=$m[2]==='approve'?'approved':'rejected';
            $s=$pdo->prepare('UPDATE access_requests SET status=? WHERE id=?');$s->execute([$state,$r['id']]);
            AccessGuard::audit($pdo,'access.'.$state,$actor,$target,['device_id'=>$r['device_id'],'ip'=>$r['ip'],'expires_at'=>$expires]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        echo json_encode(['message'=>$m[2]==='approve'?'Acceso autorizado.':'Acceso rechazado.']);return true;
    }
    if(preg_match('#^/access/devices/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/revoke$#Di',$uri,$m)&&$method==='POST') {
        $s=$pdo->prepare('SELECT user_id FROM access_devices WHERE id=?');$s->execute([$m[1]]);$uid=$s->fetchColumn();
        if(!$uid)AccessGuard::fail(404,'Dispositivo no encontrado.');$target=AccessGuard::target($pdo,$uid);
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$uid]);
            $s=$pdo->prepare('UPDATE access_grants SET revoked_at=now(),revoked_by=? WHERE device_id=? AND revoked_at IS NULL');$s->execute([$actor['id'],$m[1]]);
            AccessGuard::audit($pdo,'access.device_revoked',$actor,$target,['device_id'=>$m[1]]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        echo json_encode(['message'=>'Dispositivo revocado en todas sus IP.']);return true;
    }
    if(preg_match('#^/access/grants/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/revoke$#Di',$uri,$m)&&$method==='POST') {
        $s=$pdo->prepare('SELECT * FROM access_grants WHERE id=?');$s->execute([$m[1]]);$g=$s->fetch(PDO::FETCH_ASSOC);
        if(!$g)AccessGuard::fail(404,'Autorización no encontrada.');$target=AccessGuard::target($pdo,$g['user_id']);
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->execute([$g['user_id']]);
            $s=$pdo->prepare('UPDATE access_grants SET revoked_at=now(),revoked_by=? WHERE id=? AND revoked_at IS NULL RETURNING id');$s->execute([$actor['id'],$g['id']]);
            if($s->fetchColumn())AccessGuard::audit($pdo,'access.revoked',$actor,$target,['device_id'=>$g['device_id'],'ip'=>$g['ip']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        echo json_encode(['message'=>'Autorización revocada.']);return true;
    }
    AccessGuard::fail(404,'Acción no encontrada.');
}
