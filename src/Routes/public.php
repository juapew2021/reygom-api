<?php

function handlePublicRoutes($request_uri, $method, $pdo)
{
    $property_id = null;

    if (preg_match('#^/public/properties/([a-f0-9-]+)$#', $request_uri, $matches)) {
        $property_id = $matches[1];
        $request_uri = '/public/properties/{id}';
    }

    if ($request_uri !== '/public/properties' && $request_uri !== '/public/properties/{id}') {
        return false;
    }

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Método no permitido"]);
        return true;
    }

    if ($request_uri === '/public/properties') {
        $stmt = $pdo->prepare("
            SELECT
                id,
                reference_id,
                title,
                description,
                price,
                operation_type,
                property_type,
                status,
                cover_image,
                city,
                province,
                bedrooms,
                bathrooms,
                built_area,
                features,
                created_at
            FROM properties
            WHERE status = 'Disponible'
            ORDER BY created_at DESC
        ");

        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        return true;
    }

    if ($request_uri === '/public/properties/{id}') {
        $stmt = $pdo->prepare("
            SELECT
                id,
                reference_id,
                title,
                description,
                price,
                operation_type,
                property_type,
                status,
                cover_image,
                city,
                province,
                bedrooms,
                bathrooms,
                built_area,
                features,
                created_at
            FROM properties
            WHERE id = :id
              AND status = 'Disponible'
            LIMIT 1
        ");

        $stmt->bindParam(':id', $property_id);
        $stmt->execute();

        $property = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$property) {
            http_response_code(404);
            echo json_encode(["message" => "Inmueble no encontrado"]);
            return true;
        }

        echo json_encode($property);
        return true;
    }

    return false;
}