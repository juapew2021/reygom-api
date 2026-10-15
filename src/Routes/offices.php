<?php
declare(strict_types=1);

function handleOfficeRoutes(string $uri, string $method, PDO $pdo): bool
{
    if (!preg_match('#^/(me|offices|office-users)(?:/([a-f0-9-]{36}))?$#i', $uri, $m)) return false;
    $user=JwtMiddleware::authenticate(false); $kind=$m[1]; $id=$m[2]??null;
    $super=$user['role']==='superadmin'; $manager=in_array($user['role'],['admin','superadmin'],true);
    $query=static function(string $sql,array $params=[])use($pdo):PDOStatement{$s=$pdo->prepare($sql);$s->execute($params);return $s;};
    $audit=static function(string $action,?string $office,?string $subject)use($query,$user):void{$query('INSERT INTO office_audit(actor_id,action,organization_id,subject_id) VALUES(?,?,?,?)',[$user['id'],$action,$office,$subject]);};
    try {
        if($kind==='me') {
            if($method!=='GET'||$id) TenantAccess::deny(405,'Método no permitido.');
            echo json_encode(['user'=>$user]); return true;
        }
        if(!$manager) TenantAccess::deny(403,'Solo el administrador puede gestionar usuarios.');
        if($kind==='offices'&&!$super) TenantAccess::deny(403,'Solo el Super admin puede administrar oficinas.');
        if($method==='GET') {
            if($id) TenantAccess::deny(405,'Método no permitido.');
            $rows=$kind==='offices'
              ?$query('SELECT id,name,slug,country_code,status,created_at FROM organizations WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
              :$query('SELECT id,username,role,is_active,organization_id,created_at FROM users WHERE organization_id=? ORDER BY username',[$user['organization_id']])->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($rows);return true;
        }
        if(!in_array($method,['POST','PUT'],true)||($method==='POST'&&$id)||($method==='PUT'&&!$id)) TenantAccess::deny(405,'Método no permitido.');
        $data=json_decode(file_get_contents('php://input'),true);
        if(!is_array($data)) TenantAccess::deny(400,'Datos no válidos.');
        $pdo->beginTransaction();
        if($kind==='offices') {
            $name=trim((string)($data['name']??'')); $country=$data['country_code']??'';
            $status=$data['status']??'active';
            if(mb_strlen($name)<2||mb_strlen($name)>150||!in_array($country,['ES','CO'],true)||!in_array($status,['active','inactive'],true)) throw new InvalidArgumentException('Indica nombre, país y estado válidos.');
            if($method==='POST') {
                $slug=strtolower(trim((string)($data['slug']??'')));
                if(!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])?$/',$slug)) throw new InvalidArgumentException('El identificador debe contener entre 1 y 64 letras minúsculas, números o guiones.');
                $office=$query('INSERT INTO organizations(name,slug,country_code,status,created_by) VALUES(?,?,?,?,?) RETURNING id,name,slug,country_code,status',[$name,$slug,$country,$status,$user['id']])->fetch(PDO::FETCH_ASSOC);
                $audit('office.created',$office['id'],$office['id']);
            }else{
                $office=$query('UPDATE organizations SET name=?,country_code=?,status=?,updated_at=now(),updated_by=?,version=version+1 WHERE id=? AND deleted_at IS NULL RETURNING id,name,slug,country_code,status',[$name,$country,$status,$user['id'],$id])->fetch(PDO::FETCH_ASSOC);
                if(!$office) throw new OutOfBoundsException('Oficina no encontrada.');
                $audit('office.updated',$id,$id);
            }
            $pdo->commit(); http_response_code($method==='POST'?201:200);echo json_encode($office);return true;
        }
        $office=$user['organization_id'];
        if(isset($data['organization_id'])&&$data['organization_id']!==$office) throw new InvalidArgumentException('No se pueden trasladar usuarios entre oficinas.');
        $query('SELECT id FROM organizations WHERE id=? FOR UPDATE',[$office]);
        $role=$data['role']??'agent'; if($role==='agente')$role='agent';
        if(!in_array($role,$super?['admin','agent']:['agent'],true)) throw new InvalidArgumentException('No puedes asignar ese perfil.');
        if($method==='POST') {
            $name=trim((string)($data['username']??'')); $password=(string)($data['password']??'');
            if(!preg_match('/^[A-Za-z0-9._@+-]{3,50}$/',$name)||strlen($password)<12||strlen($password)>200) throw new InvalidArgumentException('Usuario: 3–50 caracteres. Contraseña inicial: mínimo 12 caracteres.');
            if($query('SELECT 1 FROM users WHERE lower(username)=lower(?)',[$name])->fetchColumn()) throw new InvalidArgumentException('El usuario ya está registrado.');
            $record=$query('INSERT INTO users(username,password,role,organization_id) VALUES(?,?,?,?) RETURNING id,username,role,is_active,organization_id',[$name,password_hash($password,PASSWORD_DEFAULT),$role,$office])->fetch(PDO::FETCH_ASSOC);
            $audit('user.created',$office,$record['id']);
        }else{
            $target=$query('SELECT id,role,is_active FROM users WHERE id=? AND organization_id=? FOR UPDATE',[$id,$office])->fetch(PDO::FETCH_ASSOC);
            if(!$target) throw new OutOfBoundsException('Usuario no encontrado.');
            if($target['role']==='superadmin'||(!$super&&!in_array($target['role'],['agent','agente'],true))) throw new InvalidArgumentException('No puedes modificar a ese administrador.');
            $active=$data['is_active']??$target['is_active']; if(!is_bool($active)) throw new InvalidArgumentException('Estado no válido.');
            if($id===$user['id']) throw new InvalidArgumentException('No puedes cambiar tu propio perfil o desactivarte.');
            if($target['role']==='admin'&&($role!=='admin'||!$active)&&!$query("SELECT 1 FROM users WHERE organization_id=? AND role='admin' AND is_active AND id<>? LIMIT 1",[$office,$id])->fetchColumn()) throw new InvalidArgumentException('La oficina debe conservar al menos un administrador activo.');
            $record=$query('UPDATE users SET role=?,is_active=?,auth_version=auth_version+1 WHERE id=? AND organization_id=? RETURNING id,username,role,is_active,organization_id',[$role,$active?'true':'false',$id,$office])->fetch(PDO::FETCH_ASSOC);
            $audit('user.updated',$office,$id);
        }
        $pdo->commit();http_response_code($method==='POST'?201:200);echo json_encode($record);return true;
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $status=$e instanceof OutOfBoundsException?404:($e instanceof InvalidArgumentException?422:500);
        if($e instanceof PDOException && $e->getCode()==='23505'){$status=409;$message='El identificador o usuario ya existe.';}
        else $message=$status===500?'No se pudo guardar. Inténtalo de nuevo.':$e->getMessage();
        if($status===500)error_log($e->getMessage());
        http_response_code($status);echo json_encode(['message'=>$message]);return true;
    }
}
