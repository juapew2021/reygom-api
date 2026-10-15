<?php
declare(strict_types=1);

function handleHistoryRoutes(string $uri,string $method,PDO $pdo): bool
{
    if (!in_array($uri,['/audit','/audit/options','/logout'],true)) return false;
    header('Cache-Control: no-store');
    $user=JwtMiddleware::authenticate(false);
    if($uri==='/logout') {
        if($method!=='POST')TenantAccess::deny(405,'Método no permitido.');
        $headers=array_change_key_case(getallheaders(),CASE_LOWER);
        preg_match('/Bearer\s+(.*)$/i',$headers['authorization']??'',$m);
        try{AuditTrail::logout($pdo,$user,trim($m[1]??''));echo json_encode(['message'=>'Sesión cerrada.']);}
        catch(Throwable $e){error_log('Logout failed: '.$e->getMessage());http_response_code(500);echo json_encode(['message'=>'No se pudo cerrar la sesión.']);}
        return true;
    }
    if($user['role']!=='superadmin')TenantAccess::deny(403,'Solo el Super admin puede consultar el historial global.');
    if($method!=='GET')TenantAccess::deny(405,'El historial es de solo lectura.');
    try {
        if($uri==='/audit/options') {
            $users=$pdo->query("SELECT id,username,is_active FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
            // Preserve a filter for users who no longer have a live account.
            $archived=$pdo->query("SELECT DISTINCT ON (a.actor_id) a.actor_id AS id,COALESCE(a.actor_name,'Usuario retirado') AS username,false AS is_active FROM office_audit a LEFT JOIN users u ON u.id=a.actor_id WHERE a.actor_id IS NOT NULL AND u.id IS NULL ORDER BY a.actor_id,a.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            $offices=$pdo->query('SELECT id,name,status FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $actions=$pdo->query('SELECT DISTINCT action FROM office_audit ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
            $since=$pdo->query("SELECT min(created_at) FROM office_audit WHERE action='audit.enabled'")->fetchColumn();
            echo json_encode(['users'=>array_merge($users,$archived),'offices'=>$offices,'actions'=>$actions,'coverage_since'=>$since]);return true;
        }
        $where=[];$args=[];
        foreach(['user'=>'actor_id','office'=>'organization_id'] as $key=>$column) {
            $v=$_GET[$key]??'';
            if($v==='')continue;
            if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$v))throw new InvalidArgumentException('Filtro de usuario u oficina no válido.');
            if($key==='user'){$where[]="(a.actor_id=:actor OR ((a.subject_type='user' OR a.action LIKE 'user.%') AND a.subject_id=:subject))";$args['actor']=$v;$args['subject']=$v;}
            else{$where[]='a.organization_id=:office';$args['office']=$v;}
        }
        $action=$_GET['action']??'';
        if($action!==''){
            if(!is_string($action)||!preg_match('/^[a-z_]+\.[a-z_]+$/',$action))throw new InvalidArgumentException('Acción no válida.');
            $where[]='a.action=:action';$args['action']=$action;
        }
        foreach(['from'=>'>=','to'=>'<'] as $key=>$op){
            $v=$_GET[$key]??'';if($v==='')continue;
            if(!is_string($v)||strlen($v)>40||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',$v))throw new InvalidArgumentException('Fecha no válida.');
            $date=new DateTimeImmutable($v);if($date->format('Y-m-d')!==substr($v,0,10))throw new InvalidArgumentException('Fecha no válida.');$where[]='a.created_at '.$op.' :'.$key;$args[$key]=$date->format(DateTimeInterface::ATOM);
        }
        if(isset($args['from'],$args['to'])&&strtotime($args['from'])>=strtotime($args['to']))throw new InvalidArgumentException('El rango de fechas no es válido.');
        $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false)throw new InvalidArgumentException('Página no válida.');
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $s=$pdo->prepare('SELECT count(*) FROM office_audit a'.$clause);$s->execute($args);$total=(int)$s->fetchColumn();
        $s=$pdo->prepare("SELECT a.id,a.actor_id,COALESCE(a.actor_name,u.username,'Actor no identificado') AS actor_name,a.organization_id,COALESCE(a.office_name,o.name,'Sin oficina') AS office_name,a.action,a.subject_id,a.subject_type,a.subject_ref,a.metadata,a.source,a.created_at FROM office_audit a LEFT JOIN users u ON u.id=a.actor_id LEFT JOIN organizations o ON o.id=a.organization_id".$clause.' ORDER BY a.created_at DESC,a.id DESC LIMIT 50 OFFSET '.(($page-1)*50));
        $s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row)$row['metadata']=json_decode($row['metadata']);
        header('Cache-Control: no-store');echo json_encode(['rows'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>50]);return true;
    }catch(Throwable $e){
        if(!($e instanceof InvalidArgumentException) && get_class($e)!=='Exception' && get_class($e)!=='DateMalformedStringException'){error_log('Audit read failed: '.$e->getMessage());http_response_code(500);echo json_encode(['message'=>'No se pudo consultar el historial.']);}
        else{http_response_code(422);echo json_encode(['message'=>$e instanceof InvalidArgumentException?$e->getMessage():'Fecha no válida.']);}
        return true;
    }
}
