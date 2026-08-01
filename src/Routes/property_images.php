<?php

function handlePropertyImageRoutes($requestUri, $method, $pdo)
{
    $propertyId = null;
    $imageId = null;

    if (
        preg_match(
            '#^/properties/([a-f0-9-]+)/images$#',
            $requestUri,
            $matches
        )
    ) {
        $propertyId = $matches[1];
        $requestUri = "/properties/{id}/images";
    }

    if (
        preg_match(
            '#^/property-images/([a-f0-9-]+)$#',
            $requestUri,
            $matches
        )
    ) {
        $imageId = $matches[1];
        $requestUri = "/property-images/{id}";
    }

    $validRoutes = [
        "/properties/{id}/images",
        "/property-images/{id}",
    ];

    if (!in_array($requestUri, $validRoutes, true)) {
        return false;
    }

    // Validar JWT
    $currentUser = JwtMiddleware::authenticate();

    $propertyImage = new PropertyImage($pdo);

    if ($requestUri === "/properties/{id}/images") {
        if ($method === "GET") {
            echo json_encode(
                $propertyImage->readByProperty($propertyId)
            );

            return true;
        }

        if ($method === "POST") {
            $data = json_decode(
                file_get_contents("php://input")
            );

            if (empty($data->image_url)) {
                http_response_code(400);

                echo json_encode([
                    "message" => "La URL de la imagen es obligatoria.",
                ]);

                return true;
            }

            $propertyStmt = $pdo->prepare("
                SELECT id
                FROM properties
                WHERE id = :id
                LIMIT 1
            ");

            $propertyStmt->bindValue(":id", $propertyId);
            $propertyStmt->execute();

            if (!$propertyStmt->fetchColumn()) {
                http_response_code(404);

                echo json_encode([
                    "message" => "Inmueble no encontrado.",
                ]);

                return true;
            }

            try {
                $created = $propertyImage->create(
                    $propertyId,
                    trim($data->image_url),
                    isset($data->position)
                        ? (int) $data->position
                        : 0,
                    isset($data->is_cover)
                        ? (bool) $data->is_cover
                        : false
                );

                http_response_code(201);
                echo json_encode($created);
            } catch (Throwable $error) {
                error_log($error->getMessage());

                http_response_code(500);

                echo json_encode([
                    "message" => "No se pudo añadir la imagen.",
                ]);
            }

            return true;
        }

        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido.",
        ]);

        return true;
    }

    if ($requestUri === "/property-images/{id}") {
        if ($method === "GET") {
            $image = $propertyImage->readOne($imageId);

            if (!$image) {
                http_response_code(404);

                echo json_encode([
                    "message" => "Imagen no encontrada.",
                ]);

                return true;
            }

            echo json_encode($image);
            return true;
        }

        if ($method === "PUT") {
            $data = json_decode(
                file_get_contents("php://input")
            );

            try {
                $updated = $propertyImage->update(
                    $imageId,
                    isset($data->image_url)
                        ? trim($data->image_url)
                        : null,
                    isset($data->position)
                        ? (int) $data->position
                        : null,
                    isset($data->is_cover)
                        ? (bool) $data->is_cover
                        : null
                );

                if (!$updated) {
                    http_response_code(404);

                    echo json_encode([
                        "message" => "Imagen no encontrada.",
                    ]);

                    return true;
                }

                echo json_encode($updated);
            } catch (Throwable $error) {
                error_log($error->getMessage());

                http_response_code(500);

                echo json_encode([
                    "message" => "No se pudo actualizar la imagen.",
                ]);
            }

            return true;
        }

        if ($method === "DELETE") {
            try {
                $deleted = $propertyImage->delete($imageId);

                if (!$deleted) {
                    http_response_code(404);

                    echo json_encode([
                        "message" => "Imagen no encontrada.",
                    ]);

                    return true;
                }

                echo json_encode([
                    "success" => true,
                    "message" => "Imagen eliminada.",
                ]);
            } catch (Throwable $error) {
                error_log($error->getMessage());

                http_response_code(500);

                echo json_encode([
                    "message" => "No se pudo eliminar la imagen.",
                ]);
            }

            return true;
        }

        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido.",
        ]);

        return true;
    }

    return false;
}