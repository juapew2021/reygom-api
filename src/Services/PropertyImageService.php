<?php

class PropertyImageService
{
    private PDO $pdo;
    private PropertyImage $propertyImageModel;
    private string $mediaRoot;
    private string $publicBaseUrl;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->propertyImageModel = new PropertyImage($pdo);
        $this->mediaRoot = "/var/www/media/properties";
        $this->publicBaseUrl = "https://api.reygom.com/media/properties";
    }

    public function uploadImages(
        string $propertyId,
        array $uploadedFiles
    ): array {
        $property = $this->findProperty($propertyId);

        if (!$property) {
            throw new RuntimeException("Inmueble no encontrado.");
        }

        $files = $this->normalizeUploadedFiles($uploadedFiles);

        if (count($files) === 0) {
            throw new RuntimeException(
                "No se han recibido fotografías válidas."
            );
        }

        if (count($files) > 20) {
            throw new RuntimeException(
                "Solo se permiten 20 imágenes por subida."
            );
        }

        $reference = $this->sanitizeReference(
            $property["reference_id"]
        );

        $propertyDirectory = $this->mediaRoot . "/" . $reference;

        $this->ensureDirectoryExists($propertyDirectory);

        $currentImages = $this->propertyImageModel
            ->readByProperty($propertyId);

        $nextPosition = count($currentImages);

        $createdImages = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $createdImages[] = $this->processAndStoreImage(
                    $file,
                    $propertyId,
                    $reference,
                    $propertyDirectory,
                    $nextPosition
                );

                $nextPosition++;
            } catch (Throwable $error) {
                error_log($error->getMessage());

                $errors[] = [
                    "filename" => $file["name"] ?? "desconocido",
                    "message" => $error->getMessage(),
                ];
            }
        }

        return [
            "images" => $createdImages,
            "errors" => $errors,
        ];
    }

    private function findProperty(string $propertyId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT id, reference_id
            FROM properties
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->bindValue(":id", $propertyId);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function normalizeUploadedFiles(array $uploadedFiles): array
    {
        if (!isset($uploadedFiles["name"])) {
            return [];
        }

        if (!is_array($uploadedFiles["name"])) {
            return [$uploadedFiles];
        }

        $files = [];

        foreach ($uploadedFiles["name"] as $index => $name) {
            $files[] = [
                "name" => $name,
                "type" => $uploadedFiles["type"][$index] ?? "",
                "tmp_name" => $uploadedFiles["tmp_name"][$index] ?? "",
                "error" => $uploadedFiles["error"][$index]
                    ?? UPLOAD_ERR_NO_FILE,
                "size" => $uploadedFiles["size"][$index] ?? 0,
            ];
        }

        return $files;
    }

    private function sanitizeReference(string $reference): string
    {
        $sanitized = preg_replace(
            '/[^A-Z0-9]/',
            '',
            strtoupper($reference)
        );

        if (!$sanitized) {
            throw new RuntimeException(
                "La referencia del inmueble no es válida."
            );
        }

        return $sanitized;
    }

    private function ensureDirectoryExists(
        string $directory
    ): void {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(
                "No se pudo crear la carpeta del inmueble."
            );
        }
    }

    private function processAndStoreImage(
        array $file,
        string $propertyId,
        string $reference,
        string $propertyDirectory,
        int $position
    ): array {
        $this->validateUploadedFile($file);

        $mimeType = $this->detectMimeType(
            $file["tmp_name"]
        );

        $imageResource = $this->createImageResource(
            $file["tmp_name"],
            $mimeType
        );

        if (!$imageResource) {
            throw new RuntimeException(
                "No se pudo procesar la fotografía."
            );
        }

        $imageResource = $this->resizeImageIfNeeded(
            $imageResource
        );

        $filename = sprintf(
            "%03d-%s.webp",
            $position + 1,
            bin2hex(random_bytes(8))
        );

        $destination = $propertyDirectory
            . "/"
            . $filename;

        $stored = imagewebp(
            $imageResource,
            $destination,
            82
        );

        imagedestroy($imageResource);

        if (!$stored) {
            throw new RuntimeException(
                "No se pudo guardar la fotografía optimizada."
            );
        }

        chmod($destination, 0664);

        $publicUrl = sprintf(
            "%s/%s/%s",
            $this->publicBaseUrl,
            rawurlencode($reference),
            rawurlencode($filename)
        );

        try {
            return $this->propertyImageModel->create(
                $propertyId,
                $publicUrl,
                $position,
                $position === 0
            );
        } catch (Throwable $error) {
            if (is_file($destination)) {
                unlink($destination);
            }

            throw $error;
        }
    }

    private function validateUploadedFile(array $file): void
    {
        $errorCode = $file["error"]
            ?? UPLOAD_ERR_NO_FILE;

        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                $this->uploadErrorMessage($errorCode)
            );
        }

        $maxSize = 15 * 1024 * 1024;

        if (($file["size"] ?? 0) > $maxSize) {
            throw new RuntimeException(
                "La fotografía supera el máximo permitido de 15 MB."
            );
        }
    }

    private function detectMimeType(
        string $temporaryPath
    ): string {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($temporaryPath);

        $allowedMimeTypes = [
            "image/jpeg",
            "image/png",
            "image/webp",
        ];

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException(
                "Formato no permitido. Usa JPG, PNG o WEBP."
            );
        }

        return $mimeType;
    }

    private function createImageResource(
        string $temporaryPath,
        string $mimeType
    ) {
        return match ($mimeType) {
            "image/jpeg" => imagecreatefromjpeg($temporaryPath),
            "image/png" => imagecreatefrompng($temporaryPath),
            "image/webp" => imagecreatefromwebp($temporaryPath),
            default => false,
        };
    }

    private function resizeImageIfNeeded(
        $imageResource
    ) {
        $originalWidth = imagesx($imageResource);
        $originalHeight = imagesy($imageResource);
        $maxWidth = 1920;

        if ($originalWidth <= $maxWidth) {
            return $imageResource;
        }

        $newWidth = $maxWidth;
        $newHeight = (int) round(
            $originalHeight
            * ($newWidth / $originalWidth)
        );

        $resizedImage = imagecreatetruecolor(
            $newWidth,
            $newHeight
        );

        imagecopyresampled(
            $resizedImage,
            $imageResource,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $originalWidth,
            $originalHeight
        );

        imagedestroy($imageResource);

        return $resizedImage;
    }

    private function uploadErrorMessage(
        int $errorCode
    ): string {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                "La fotografía supera el tamaño máximo permitido.",

            UPLOAD_ERR_PARTIAL =>
                "La fotografía se subió parcialmente.",

            UPLOAD_ERR_NO_FILE =>
                "No se recibió ninguna fotografía.",

            UPLOAD_ERR_NO_TMP_DIR =>
                "No existe la carpeta temporal de subida.",

            UPLOAD_ERR_CANT_WRITE =>
                "No se pudo escribir la fotografía en el disco.",

            UPLOAD_ERR_EXTENSION =>
                "Una extensión de PHP bloqueó la subida.",

            default =>
                "Se produjo un error durante la subida.",
        };
    }
}