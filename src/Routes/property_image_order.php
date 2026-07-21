<?php

function handlePropertyImageOrderRoutes(
    string $requestUri,
    string $method,
    PDO $pdo
): bool {
    if (
        !preg_match(
            '#^/properties/([a-f0-9-]+)/images/order/?$#',
            $requestUri,
            $matches
        )
    ) {
        return false;
    }

    authenticate();

    if ($method !== "PUT") {
        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido."
        ]);

        return true;
    }

    $propertyId = $matches[1];

    $data = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (
        !is_array($data) ||
        !isset($data["image_ids"]) ||
        !is_array($data["image_ids"])
    ) {
        http_response_code(400);

        echo json_encode([
            "message" => "Debes enviar image_ids como una lista ordenada."
        ]);

        return true;
    }

    $imageIds = array_values(
        array_unique(
            array_filter(
                $data["image_ids"],
                static fn ($id) =>
                    is_string($id) &&
                    preg_match(
                        '#^[a-f0-9-]{36}$#',
                        $id
                    )
            )
        )
    );

    if (count($imageIds) === 0) {
        http_response_code(400);

        echo json_encode([
            "message" => "No se recibieron imágenes válidas."
        ]);

        return true;
    }

    try {
        $propertyStmt = $pdo->prepare("
            SELECT id
            FROM properties
            WHERE id = :property_id
            LIMIT 1
        ");

        $propertyStmt->bindValue(
            ":property_id",
            $propertyId
        );

        $propertyStmt->execute();

        if (!$propertyStmt->fetchColumn()) {
            http_response_code(404);

            echo json_encode([
                "message" => "Inmueble no encontrado."
            ]);

            return true;
        }

        $placeholders = implode(
            ",",
            array_fill(0, count($imageIds), "?")
        );

        $validationStmt = $pdo->prepare("
            SELECT id
            FROM property_images
            WHERE property_id = ?
              AND id IN ($placeholders)
        ");

        $validationStmt->execute([
            $propertyId,
            ...$imageIds
        ]);

        $existingIds = $validationStmt->fetchAll(
            PDO::FETCH_COLUMN
        );

        if (count($existingIds) !== count($imageIds)) {
            http_response_code(400);

            echo json_encode([
                "message" =>
                    "Una o más imágenes no pertenecen al inmueble."
            ]);

            return true;
        }

        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare("
            UPDATE property_images
            SET position = :position
            WHERE id = :image_id
              AND property_id = :property_id
        ");

        foreach ($imageIds as $position => $imageId) {
            $updateStmt->bindValue(
                ":position",
                $position,
                PDO::PARAM_INT
            );

            $updateStmt->bindValue(
                ":image_id",
                $imageId
            );

            $updateStmt->bindValue(
                ":property_id",
                $propertyId
            );

            $updateStmt->execute();
        }

        $pdo->commit();

        $resultStmt = $pdo->prepare("
            SELECT
                id,
                property_id,
                image_url,
                position,
                is_cover,
                created_at
            FROM property_images
            WHERE property_id = :property_id
            ORDER BY position ASC, created_at ASC
        ");

        $resultStmt->bindValue(
            ":property_id",
            $propertyId
        );

        $resultStmt->execute();

        echo json_encode([
            "message" =>
                "Orden de fotografías actualizado.",
            "images" =>
                $resultStmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($error->getMessage());

        http_response_code(500);

        echo json_encode([
            "message" =>
                "No se pudo actualizar el orden de las fotografías."
        ]);

        return true;
    }
}