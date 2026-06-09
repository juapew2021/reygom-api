<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Recibimos las credenciales desde Kubernetes
$host = getenv('DB_HOST') ?: 'reygom-db-service';
$db   = getenv('POSTGRES_DB') ?: 'reygom_core';
$user = getenv('POSTGRES_USER') ?: 'reygom_admin';
$pass = getenv('POSTGRES_PASSWORD') ?: 'CambiaEstaClavePorUnaSegura123';

try {
    // Intentamos la conexión a PostgreSQL
    $dsn = "pgsql:host=$host;port=5432;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    echo json_encode([
        "status" => "success",
        "service" => "ReyGom Core API",
        "database_connection" => "OK",
        "message" => "El backend está conectado a PostgreSQL exitosamente."
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "service" => "ReyGom Core API",
        "database_connection" => "FAILED",
        "error_details" => $e->getMessage()
    ]);
}
?>
