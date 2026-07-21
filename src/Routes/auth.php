<?php

function handleAuthRoutes($request_uri, $method, $pdo) {
    if ($request_uri !== '/login') {
        return false;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Método no permitido."]);
        return true;
    }

    $data = json_decode(file_get_contents("php://input"));

    if (empty($data->username) || empty($data->password)) {
        http_response_code(400);
        echo json_encode(["message" => "Faltan credenciales."]);
        return true;
    }

    $userModel = new User($pdo);
    $userData = $userModel->authenticate($data->username, $data->password);

    if ($userData) {
        $jwt = new JwtHandler();
        $token = $jwt->encode([
            "id" => $userData['id'],
            "user" => $userData['username'],
            "role" => $userData['role']
        ]);

        echo json_encode([
            "message" => "Login exitoso",
            "token" => $token,
            "role" => $userData['role']
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["message" => "Credenciales incorrectas."]);
    }

    return true;
}
