<?php

declare(strict_types=1);

final class JwtMiddleware
{
    public static function authenticate(bool $scope = true): array
    {
        $headers = getallheaders();

        if (
            !isset($headers['Authorization']) &&
            !isset($headers['authorization'])
        ) {
            http_response_code(401);

            echo json_encode([
                'message' => 'Token no proporcionado.'
            ]);

            exit;
        }

        $authorization =
            $headers['Authorization']
            ?? $headers['authorization'];

        if (!preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {

            http_response_code(401);

            echo json_encode([
                'message' => 'Formato de token inválido.'
            ]);

            exit;
        }

        $token = trim($matches[1]);

        $jwt = new JwtHandler();

        $payload = $jwt->decode($token);

        if ($payload === false) {

            http_response_code(401);

            echo json_encode([
                'message' => 'Token inválido o expirado.'
            ]);

            exit;
        }

        if (
            !isset($payload['id']) ||
            (!isset($payload['username']) && !isset($payload['user'])) ||
            !isset($payload['role']) ||
            !isset($payload['organization_id'])
        ) {
            http_response_code(401);

            echo json_encode([
                'message' => 'El token no contiene la información del usuario.'
            ]);

            exit;
        }

        if (!isset($payload['username']) && isset($payload['user'])) {
            $payload['username'] = $payload['user'];
        }

        global $pdo;
        if (AuditTrail::revoked($pdo, $token)) TenantAccess::deny(401, 'La sesión ya está cerrada.');
        $user = TenantAccess::identity($pdo, $payload, $headers);
        if ($scope) TenantAccess::scope($pdo, $user);
        return $user;
    }
}