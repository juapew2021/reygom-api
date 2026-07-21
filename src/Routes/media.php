<?php

function handleMediaRoutes(
    string $requestUri,
    string $method
): bool {
    if (!str_starts_with($requestUri, "/media/properties/")) {
        return false;
    }

    if ($method !== "GET") {
        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido."
        ]);

        return true;
    }

    $relativePath = substr(
        $requestUri,
        strlen("/media/properties/")
    );

    $relativePath = rawurldecode($relativePath);

    if (
        $relativePath === "" ||
        str_contains($relativePath, "..") ||
        str_contains($relativePath, "\\")
    ) {
        http_response_code(400);

        echo json_encode([
            "message" => "Ruta no válida."
        ]);

        return true;
    }

    if (!preg_match(
        '#^[A-Z0-9]+/[A-Za-z0-9._-]+$#',
        $relativePath
    )) {
        http_response_code(400);

        echo json_encode([
            "message" => "Ruta no válida."
        ]);

        return true;
    }

    $mediaRoot = "/var/www/media/properties";
    $filePath = $mediaRoot . "/" . $relativePath;

    if (!is_file($filePath)) {
        http_response_code(404);

        echo json_encode([
            "message" => "Imagen no encontrada."
        ]);

        return true;
    }

    $mimeType = mime_content_type($filePath);

    $allowedMimeTypes = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        http_response_code(403);

        echo json_encode([
            "message" => "Archivo no permitido."
        ]);

        return true;
    }

    header("Content-Type: " . $mimeType);
    header("Content-Length: " . filesize($filePath));
    header("Cache-Control: public, max-age=31536000, immutable");
    header("X-Content-Type-Options: nosniff");

    readfile($filePath);

    return true;
}