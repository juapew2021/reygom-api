<?php

declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST') ?: 'reygom-db-service';
    $db   = getenv('POSTGRES_DB') ?: 'reygom_core';
    $user = getenv('POSTGRES_USER') ?: 'reygom_admin';
    $pass = getenv('POSTGRES_PASSWORD');

    if ($pass === false || trim($pass) === '') {
        throw new RuntimeException(
            'La variable de entorno POSTGRES_PASSWORD no está configurada.'
        );
    }

    try {
        $dsn = "pgsql:host={$host};port=5432;dbname={$db}";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('Error de conexión PostgreSQL: ' . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            'error' => 'Error de conexión a la base de datos.',
        ]);

        exit;
    }
}
