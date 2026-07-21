<?php

class PropertyImage
{
    private PDO $conn;
    private string $tableName = "property_images";

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public function readByProperty(string $propertyId): array
    {
        $query = "
            SELECT
                id,
                property_id,
                image_url,
                position,
                is_cover,
                created_at
            FROM {$this->tableName}
            WHERE property_id = :property_id
            ORDER BY is_cover DESC, position ASC, created_at ASC
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":property_id", $propertyId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function readOne(string $imageId): array|false
    {
        $query = "
            SELECT
                id,
                property_id,
                image_url,
                position,
                is_cover,
                created_at
            FROM {$this->tableName}
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":id", $imageId);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(
        string $propertyId,
        string $imageUrl,
        int $position = 0,
        bool $isCover = false
    ): array|false {
        try {
            $this->conn->beginTransaction();

            $countStmt = $this->conn->prepare("
                SELECT COUNT(*)
                FROM {$this->tableName}
                WHERE property_id = :property_id
            ");

            $countStmt->bindValue(":property_id", $propertyId);
            $countStmt->execute();

            $isFirstImage = (int) $countStmt->fetchColumn() === 0;

            if ($isFirstImage) {
                $isCover = true;
            }

            if ($isCover) {
                $this->clearCover($propertyId);
            }

            $query = "
                INSERT INTO {$this->tableName}
                    (
                        property_id,
                        image_url,
                        position,
                        is_cover
                    )
                VALUES
                    (
                        :property_id,
                        :image_url,
                        :position,
                        :is_cover
                    )
                RETURNING
                    id,
                    property_id,
                    image_url,
                    position,
                    is_cover,
                    created_at
            ";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":property_id", $propertyId);
            $stmt->bindValue(":image_url", $imageUrl);
            $stmt->bindValue(":position", $position, PDO::PARAM_INT);
            $stmt->bindValue(":is_cover", $isCover, PDO::PARAM_BOOL);
            $stmt->execute();

            $created = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($isCover) {
                $this->syncPropertyCover($propertyId, $imageUrl);
            }

            $this->conn->commit();

            return $created;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            throw $error;
        }
    }

    public function update(
        string $imageId,
        ?string $imageUrl = null,
        ?int $position = null,
        ?bool $isCover = null
    ): array|false {
        $current = $this->readOne($imageId);

        if (!$current) {
            return false;
        }

        $propertyId = $current["property_id"];
        $finalImageUrl = $imageUrl ?? $current["image_url"];
        $finalPosition = $position ?? (int) $current["position"];
        $finalIsCover = $isCover ?? (bool) $current["is_cover"];

        try {
            $this->conn->beginTransaction();

            if ($finalIsCover) {
                $this->clearCover($propertyId);
            }

            $query = "
                UPDATE {$this->tableName}
                SET
                    image_url = :image_url,
                    position = :position,
                    is_cover = :is_cover
                WHERE id = :id
                RETURNING
                    id,
                    property_id,
                    image_url,
                    position,
                    is_cover,
                    created_at
            ";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":id", $imageId);
            $stmt->bindValue(":image_url", $finalImageUrl);
            $stmt->bindValue(":position", $finalPosition, PDO::PARAM_INT);
            $stmt->bindValue(":is_cover", $finalIsCover, PDO::PARAM_BOOL);
            $stmt->execute();

            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($finalIsCover) {
                $this->syncPropertyCover($propertyId, $finalImageUrl);
            }

            $this->conn->commit();

            return $updated;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            throw $error;
        }
    }

    public function delete(string $imageId): bool
    {
        $current = $this->readOne($imageId);

        if (!$current) {
            return false;
        }

        try {
            $this->conn->beginTransaction();

            $query = "
                DELETE FROM {$this->tableName}
                WHERE id = :id
            ";

            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":id", $imageId);
            $deleted = $stmt->execute();

            if ((bool) $current["is_cover"]) {
                $this->assignFirstImageAsCover($current["property_id"]);
            }

            $this->conn->commit();

            return $deleted;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            throw $error;
        }
    }

    private function clearCover(string $propertyId): void
    {
        $stmt = $this->conn->prepare("
            UPDATE {$this->tableName}
            SET is_cover = FALSE
            WHERE property_id = :property_id
        ");

        $stmt->bindValue(":property_id", $propertyId);
        $stmt->execute();
    }

    private function syncPropertyCover(
        string $propertyId,
        ?string $imageUrl
    ): void {
        $stmt = $this->conn->prepare("
            UPDATE properties
            SET cover_image = :cover_image
            WHERE id = :property_id
        ");

        $stmt->bindValue(":cover_image", $imageUrl);
        $stmt->bindValue(":property_id", $propertyId);
        $stmt->execute();
    }

    private function assignFirstImageAsCover(string $propertyId): void
    {
        $stmt = $this->conn->prepare("
            SELECT id, image_url
            FROM {$this->tableName}
            WHERE property_id = :property_id
            ORDER BY position ASC, created_at ASC
            LIMIT 1
        ");

        $stmt->bindValue(":property_id", $propertyId);
        $stmt->execute();

        $firstImage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$firstImage) {
            $this->syncPropertyCover($propertyId, null);
            return;
        }

        $coverStmt = $this->conn->prepare("
            UPDATE {$this->tableName}
            SET is_cover = TRUE
            WHERE id = :id
        ");

        $coverStmt->bindValue(":id", $firstImage["id"]);
        $coverStmt->execute();

        $this->syncPropertyCover(
            $propertyId,
            $firstImage["image_url"]
        );
    }
}