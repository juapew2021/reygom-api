<?php

function handlePropertyUploadRoutes(
    string $requestUri,
    string $method,
    PDO $pdo
): bool {
    if (
        !preg_match(
            '#^/properties/([a-f0-9-]+)/images/upload$#',
            $requestUri,
            $matches
        )
    ) {
        return false;
    }

    // Validar JWT
    $currentUser = JwtMiddleware::authenticate();

    if ($method !== "POST") {
        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido."
        ]);

        return true;
    }

    $propertyId = $matches[1];

    if (!isset($_FILES["images"])) {
        http_response_code(400);

        echo json_encode([
            "message" => "No se han recibido fotografías."
        ]);

        return true;
    }

    try {
        $service = new PropertyImageService($pdo);

        $result = $service->uploadImages(
            $propertyId,
            $_FILES["images"]
        );

        if (count($result["images"]) === 0) {
            http_response_code(400);

            echo json_encode([
                "message" => "No se pudo subir ninguna fotografía.",
                "errors" => $result["errors"]
            ]);

            return true;
        }

        http_response_code(201);

        echo json_encode([
            "message" => count($result["images"]) === 1
                ? "Fotografía subida correctamente."
                : "Fotografías subidas correctamente.",
            "images" => $result["images"],
            "errors" => $result["errors"]
        ]);

        return true;
    } catch (RuntimeException $error) {
        http_response_code(400);

        echo json_encode([
            "message" => $error->getMessage()
        ]);

        return true;
    } catch (Throwable $error) {
        error_log($error->getMessage());

        http_response_code(500);

        echo json_encode([
            "message" => "No se pudieron procesar las fotografías."
        ]);

        return true;
    }
}