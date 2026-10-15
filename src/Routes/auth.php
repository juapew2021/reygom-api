<?php

declare(strict_types=1);
use App\Models\User;

function handleAuthRoutes(
    string $request_uri,
    string $method,
    PDO $pdo
): bool {
    if ($request_uri !== '/login') {
        return false;
    }

    if ($method !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'message' => 'Método no permitido.',
        ]);

        return true;
    }

    $raw = file_get_contents('php://input');

    $data = json_decode($raw);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);

        echo json_encode([
            'message' => 'El cuerpo de la petición no contiene un JSON válido.',
        ]);

        return true;
    }

    if (
        !is_object($data)
        || empty($data->username)
        || empty($data->password)
    ) {
        http_response_code(400);

        echo json_encode([
            'message' => 'El usuario y la contraseña son obligatorios.',
        ]);

        return true;
    }

    try {
        $userModel = new User($pdo);

        $userData = $userModel->authenticate(
            trim((string) $data->username),
            (string) $data->password
        );

        if ($userData === false || $userData === null) {
            AuditTrail::failedLogin($pdo, trim((string)$data->username));
            http_response_code(401);

            echo json_encode([
                'message' => 'Credenciales incorrectas.',
            ]);

            return true;
        }

        if (
            empty($userData['id'])
            || empty($userData['username'])
            || empty($userData['role'])
        ) {
            error_log(
                'El método User::authenticate() devolvió datos incompletos.'
            );

            http_response_code(500);

            echo json_encode([
                'message' => 'No se pudo completar el inicio de sesión.',
            ]);

            return true;
        }

        /*
         * organization_id será obligatorio para todos los usuarios
         * una vez finalizada la adaptación multiempresa.
         */

        if (empty($userData['organization_id'])) {
            error_log(
                'El usuario autenticado no tiene organization_id: '
                . (string) $userData['id']
            );

            http_response_code(409);

            echo json_encode([
                'message' =>
                    'El usuario no está asociado a una organización.',
            ]);

            return true;
        }

        $identity = TenantAccess::identity($pdo, ['id'=>$userData['id'], 'auth_version'=>$userData['auth_version'] ?? 1], []);
        $deviceId = AccessGuard::check($pdo,$identity);
        $issuedAt = time();
        $expiresAt = $issuedAt + 28800;

        $jwt = new JwtHandler();

        $token = $jwt->encode([
            'id' => (string) $userData['id'],
            'user' => (string) $userData['username'],
            'role' => strtolower((string) $userData['role']),
            'organization_id' =>
                (string) $userData['organization_id'],
            'auth_version' => (int) ($userData['auth_version'] ?? 1),
            'device_id' => $deviceId,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ]);

        AuditTrail::event($pdo, 'session.login', $identity);
        echo json_encode([
            'message' => 'Login exitoso.',
            'token' => $token,
            'user' => [
                'id' => (string) $userData['id'],
                'username' => (string) $userData['username'],
                'role' => strtolower((string) $userData['role']),
                'organization_id' =>
                    (string) $userData['organization_id'],
            ],
            'expires_in' => 28800,
        ]);

        return true;
    } catch (PDOException $exception) {
        error_log(
            'Error de base de datos durante el login: '
            . $exception->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'message' =>
                'Ocurrió un error interno durante el inicio de sesión.',
        ]);

        return true;
    } catch (Throwable $exception) {
        error_log(
            'Error inesperado durante el login: '
            . $exception->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'message' =>
                'Ocurrió un error interno durante el inicio de sesión.',
        ]);

        return true;
    }
}
