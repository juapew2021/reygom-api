<?php

function authenticate() {
    $headers = getallheaders();

    $authHeader = isset($headers['Authorization'])
        ? $headers['Authorization']
        : (isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '');

    if (!$authHeader) {
        http_response_code(401);
        echo json_encode([
            "message" => "Acceso denegado. Token no proporcionado."
        ]);
        exit();
    }

    $token = str_replace('Bearer ', '', $authHeader);
    $jwt = new JwtHandler();
    $payload = $jwt->decode($token);

    if (!$payload) {
        http_response_code(401);
        echo json_encode([
            "message" => "Acceso denegado. Token inválido."
        ]);
        exit();
    }

    return $payload;
}
