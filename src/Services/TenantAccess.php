<?php
declare(strict_types=1);

final class TenantAccess
{
    public static function deny(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['message' => $message]);
        exit;
    }

    public static function identity(PDO $pdo, array $claims, array $headers): array
    {
        $s = $pdo->prepare('SELECT u.id,u.username,u.role,u.organization_id,u.is_active,u.auth_version,o.name AS office_name,o.country_code,o.status AS office_status,o.deleted_at FROM users u LEFT JOIN organizations o ON o.id=u.organization_id WHERE u.id=:id');
        $s->execute(['id' => $claims['id'] ?? '00000000-0000-0000-0000-000000000000']);
        $user = $s->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['is_active'] || (int)($claims['auth_version'] ?? 1) !== (int)$user['auth_version']) self::deny(401, 'La sesión ya no está activa. Inicia sesión de nuevo.');
        $user['role'] = strtolower($user['role']) === 'agente' ? 'agent' : strtolower($user['role']);
        if (!in_array($user['role'], ['superadmin','admin','agent'], true)) self::deny(403, 'Perfil sin acceso.');
        $officeHeader = '';
        foreach ($headers as $key => $value) if (strtolower($key) === 'x-office-id') $officeHeader = trim((string)$value);
        if ($user['role'] !== 'superadmin' && $officeHeader !== '' && $officeHeader !== $user['organization_id']) self::deny(403, 'No tienes acceso a esa oficina.');
        if ($user['role'] === 'superadmin' && $officeHeader !== '') {
            if (!preg_match('/^[a-f0-9-]{36}$/i', $officeHeader)) self::deny(400, 'Oficina no válida.');
            $s = $pdo->prepare('SELECT id,name,country_code,status,deleted_at FROM organizations WHERE id=:id');
            $s->execute(['id'=>$officeHeader]); $office = $s->fetch(PDO::FETCH_ASSOC);
            if (!$office || $office['deleted_at']) self::deny(404, 'Oficina no encontrada.');
            $user['organization_id']=$office['id']; $user['office_name']=$office['name']; $user['country_code']=$office['country_code']; $user['office_status']=$office['status'];
        }
        if (!$user['organization_id'] || ($user['role'] !== 'superadmin' && ($user['office_status'] !== 'active' || $user['deleted_at']))) {
            if (explode('?',$_SERVER['REQUEST_URI']??'',2)[0]==='/login') AuditTrail::event($pdo,'session.login_failed',null,$user);
            self::deny(403, 'La oficina no está activa.');
        }
        $context=$pdo->prepare("SELECT set_config('reygom.user_id',:id,false),set_config('reygom.organization_id',:office,false)");
        $context->execute(['id'=>$user['id'],'office'=>$user['organization_id']]);
        unset($user['deleted_at']);
        return $user;
    }

    public static function scope(PDO $pdo, array $user): void
    {
        $s = $pdo->prepare("SELECT set_config('reygom.organization_id', :office, false),set_config('reygom.user_id', :id, false)");
        $s->execute(['office'=>$user['organization_id'],'id'=>$user['id']]);
        // The runtime role is neither a table owner nor a PostgreSQL superuser.
        $pdo->exec('SET ROLE reygom_office_runtime');
    }
}
