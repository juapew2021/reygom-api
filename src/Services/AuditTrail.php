<?php
declare(strict_types=1);

final class AuditTrail
{
    public static function event(PDO $pdo,string $action,?array $actor,?array $subject=null): void
    {
        $s=$pdo->prepare("INSERT INTO office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,subject_ref,metadata,source) VALUES(:actor,:actor_name,:office,:office_name,:action,:subject,'user',:ref,'{}','application')");
        $s->execute(['actor'=>$actor['id']??null,'actor_name'=>$actor['username']??'No autenticado',
            'office'=>$subject['organization_id']??$actor['organization_id']??null,
            'office_name'=>$subject['office_name']??$actor['office_name']??null,
            'action'=>$action,'subject'=>$subject['id']??$actor['id']??null,'ref'=>$subject['username']??$actor['username']??null]);
    }

    public static function failedLogin(PDO $pdo,string $username): void
    {
        $s=$pdo->prepare('SELECT u.id,u.username,u.organization_id,o.name AS office_name FROM users u LEFT JOIN organizations o ON o.id=u.organization_id WHERE lower(u.username)=lower(?) LIMIT 1');
        $s->execute([$username]);$target=$s->fetch(PDO::FETCH_ASSOC);
        self::event($pdo,'session.login_failed',null,$target?:null);
    }

    public static function revoked(PDO $pdo,string $token): bool
    {
        $s=$pdo->prepare('SELECT 1 FROM revoked_sessions WHERE token_hash=? AND expires_at>now()');
        $s->execute([hash('sha256',$token)]);return (bool)$s->fetchColumn();
    }

    public static function logout(PDO $pdo,array $user,string $token): void
    {
        $claims=(new JwtHandler())->decode($token);
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('INSERT INTO revoked_sessions(token_hash,expires_at) VALUES(?,?) ON CONFLICT DO NOTHING');
            $s->execute([hash('sha256',$token),gmdate('c',(int)$claims['exp'])]);
            self::event($pdo,'session.logout',$user);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
