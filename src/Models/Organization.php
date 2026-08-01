<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Organization
{
    private const STATUS_ACTIVE = 'active';
    private const STATUS_INACTIVE = 'inactive';

    private const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Crea una organización.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, ?string $actorId = null): array
    {
        $payload = $this->validateCreatePayload($data);
        $actorId = $this->validateOptionalUuid($actorId, 'created_by');

        if ($this->slugExists($payload['slug'])) {
            throw new InvalidArgumentException(
                'Ya existe una organización con el slug indicado.'
            );
        }

        $sql = <<<'SQL'
            INSERT INTO organizations (
                name,
                slug,
                legal_name,
                tax_id,
                email,
                phone,
                website,
                country_code,
                logo,
                primary_color,
                secondary_color,
                status,
                is_demo,
                settings,
                created_by,
                updated_by
            ) VALUES (
                :name,
                :slug,
                :legal_name,
                :tax_id,
                :email,
                :phone,
                :website,
                :country_code,
                :logo,
                :primary_color,
                :secondary_color,
                :status,
                :is_demo,
                CAST(:settings AS JSONB),
                :created_by,
                :updated_by
            )
            RETURNING
                id,
                name,
                slug,
                legal_name,
                tax_id,
                email,
                phone,
                website,
                country_code,
                logo,
                primary_color,
                secondary_color,
                status,
                is_demo,
                settings,
                created_by,
                updated_by,
                version,
                created_at,
                updated_at,
                deleted_at
        SQL;

        try {
            $statement = $this->db->prepare($sql);

            $statement->bindValue(':name', $payload['name']);
            $statement->bindValue(':slug', $payload['slug']);
            $statement->bindValue(':legal_name', $payload['legal_name']);
            $statement->bindValue(':tax_id', $payload['tax_id']);
            $statement->bindValue(':email', $payload['email']);
            $statement->bindValue(':phone', $payload['phone']);
            $statement->bindValue(':website', $payload['website']);
            $statement->bindValue(':country_code', $payload['country_code']);
            $statement->bindValue(':logo', $payload['logo']);
            $statement->bindValue(':primary_color', $payload['primary_color']);
            $statement->bindValue(':secondary_color', $payload['secondary_color']);
            $statement->bindValue(':status', $payload['status']);
            $statement->bindValue(
                ':is_demo',
                $payload['is_demo'],
                PDO::PARAM_BOOL
            );
            $statement->bindValue(
                ':settings',
                $this->encodeSettings($payload['settings'])
            );
            $statement->bindValue(':created_by', $actorId);
            $statement->bindValue(':updated_by', $actorId);

            $statement->execute();

            $organization = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($organization)) {
                throw new RuntimeException(
                    'No fue posible recuperar la organización creada.'
                );
            }

            return $this->normalizeRow($organization);
        } catch (PDOException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new InvalidArgumentException(
                    'Ya existe una organización con el slug indicado.',
                    0,
                    $exception
                );
            }

            if ($this->isForeignKeyViolation($exception)) {
                throw new InvalidArgumentException(
                    'El usuario indicado para la auditoría no existe.',
                    0,
                    $exception
                );
            }

            throw new RuntimeException(
                'No fue posible crear la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * Actualiza una organización usando optimistic locking.
     *
     * El payload debe contener la versión que recibió el cliente.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(
        string $id,
        array $data,
        ?string $actorId = null
    ): array {
        $id = $this->validateUuid($id, 'id');
        $actorId = $this->validateOptionalUuid($actorId, 'updated_by');
        $payload = $this->validateUpdatePayload($data);

        $expectedVersion = $payload['version'];
        unset($payload['version']);

        if (
            isset($payload['slug'])
            && $this->slugExists($payload['slug'], $id)
        ) {
            throw new InvalidArgumentException(
                'Ya existe otra organización con el slug indicado.'
            );
        }

        $assignments = [];
        $parameters = [
            ':id' => $id,
            ':expected_version' => $expectedVersion,
            ':updated_by' => $actorId,
        ];

        foreach ($payload as $field => $value) {
            if ($field === 'settings') {
                $assignments[] = 'settings = CAST(:settings AS JSONB)';
                $parameters[':settings'] = $this->encodeSettings($value);
                continue;
            }

            $assignments[] = sprintf('%s = :%s', $field, $field);
            $parameters[':' . $field] = $value;
        }

        $assignments[] = 'updated_by = :updated_by';
        $assignments[] = 'updated_at = CURRENT_TIMESTAMP';
        $assignments[] = 'version = version + 1';

        $sql = sprintf(
            <<<'SQL'
                UPDATE organizations
                SET %s
                WHERE id = :id
                  AND deleted_at IS NULL
                  AND version = :expected_version
                RETURNING
                    id,
                    name,
                    slug,
                    legal_name,
                    tax_id,
                    email,
                    phone,
                    website,
                    country_code,
                    logo,
                    primary_color,
                    secondary_color,
                    status,
                    is_demo,
                    settings,
                    created_by,
                    updated_by,
                    version,
                    created_at,
                    updated_at,
                    deleted_at
            SQL,
            implode(",\n", $assignments)
        );

        try {
            $statement = $this->db->prepare($sql);

            foreach ($parameters as $parameter => $value) {
                if ($parameter === ':expected_version') {
                    $statement->bindValue(
                        $parameter,
                        $value,
                        PDO::PARAM_INT
                    );
                    continue;
                }

                if ($parameter === ':is_demo') {
                    $statement->bindValue(
                        $parameter,
                        $value,
                        PDO::PARAM_BOOL
                    );
                    continue;
                }

                $statement->bindValue($parameter, $value);
            }

            $statement->execute();

            $organization = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($organization)) {
                if (!$this->exists($id)) {
                    throw new RuntimeException(
                        'La organización no existe o fue eliminada.'
                    );
                }

                throw new RuntimeException(
                    'La organización fue modificada por otro usuario. '
                    . 'Recarga la información antes de volver a guardar.',
                    409
                );
            }

            return $this->normalizeRow($organization);
        } catch (PDOException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new InvalidArgumentException(
                    'Ya existe otra organización con el slug indicado.',
                    0,
                    $exception
                );
            }

            if ($this->isForeignKeyViolation($exception)) {
                throw new InvalidArgumentException(
                    'El usuario indicado para la auditoría no existe.',
                    0,
                    $exception
                );
            }

            throw new RuntimeException(
                'No fue posible actualizar la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * Realiza borrado lógico.
     */
    public function delete(string $id, ?string $actorId = null): bool
    {
        $id = $this->validateUuid($id, 'id');
        $actorId = $this->validateOptionalUuid($actorId, 'updated_by');

        $sql = <<<'SQL'
            UPDATE organizations
            SET
                status = 'inactive',
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP,
                deleted_at = CURRENT_TIMESTAMP,
                version = version + 1
            WHERE id = :id
              AND deleted_at IS NULL
        SQL;

        try {
            $statement = $this->db->prepare($sql);
            $statement->bindValue(':id', $id);
            $statement->bindValue(':updated_by', $actorId);
            $statement->execute();

            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                throw new InvalidArgumentException(
                    'El usuario indicado para la auditoría no existe.',
                    0,
                    $exception
                );
            }

            throw new RuntimeException(
                'No fue posible eliminar la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * Restaura una organización eliminada.
     *
     * @return array<string, mixed>
     */
    public function restore(string $id, ?string $actorId = null): array
    {
        $id = $this->validateUuid($id, 'id');
        $actorId = $this->validateOptionalUuid($actorId, 'updated_by');

        $sql = <<<'SQL'
            UPDATE organizations
            SET
                status = 'active',
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP,
                deleted_at = NULL,
                version = version + 1
            WHERE id = :id
              AND deleted_at IS NOT NULL
            RETURNING
                id,
                name,
                slug,
                legal_name,
                tax_id,
                email,
                phone,
                website,
                country_code,
                logo,
                primary_color,
                secondary_color,
                status,
                is_demo,
                settings,
                created_by,
                updated_by,
                version,
                created_at,
                updated_at,
                deleted_at
        SQL;

        try {
            $statement = $this->db->prepare($sql);
            $statement->bindValue(':id', $id);
            $statement->bindValue(':updated_by', $actorId);
            $statement->execute();

            $organization = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($organization)) {
                throw new RuntimeException(
                    'La organización no existe o no está eliminada.'
                );
            }

            return $this->normalizeRow($organization);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible restaurar la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readOne(
        string $id,
        bool $includeDeleted = false
    ): ?array {
        $id = $this->validateUuid($id, 'id');

        $sql = <<<'SQL'
            SELECT
                id,
                name,
                slug,
                legal_name,
                tax_id,
                email,
                phone,
                website,
                country_code,
                logo,
                primary_color,
                secondary_color,
                status,
                is_demo,
                settings,
                created_by,
                updated_by,
                version,
                created_at,
                updated_at,
                deleted_at
            FROM organizations
            WHERE id = :id
        SQL;

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $sql .= ' LIMIT 1';

        try {
            $statement = $this->db->prepare($sql);
            $statement->bindValue(':id', $id);
            $statement->execute();

            $organization = $statement->fetch(PDO::FETCH_ASSOC);

            return is_array($organization)
                ? $this->normalizeRow($organization)
                : null;
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible consultar la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(
        string $slug,
        bool $includeDeleted = false
    ): ?array {
        $slug = $this->normalizeSlug($slug);

        if ($slug === '') {
            throw new InvalidArgumentException(
                'El slug es obligatorio.'
            );
        }

        $sql = <<<'SQL'
            SELECT
                id,
                name,
                slug,
                legal_name,
                tax_id,
                email,
                phone,
                website,
                country_code,
                logo,
                primary_color,
                secondary_color,
                status,
                is_demo,
                settings,
                created_by,
                updated_by,
                version,
                created_at,
                updated_at,
                deleted_at
            FROM organizations
            WHERE slug = :slug
        SQL;

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $sql .= ' LIMIT 1';

        try {
            $statement = $this->db->prepare($sql);
            $statement->bindValue(':slug', $slug);
            $statement->execute();

            $organization = $statement->fetch(PDO::FETCH_ASSOC);

            return is_array($organization)
                ? $this->normalizeRow($organization)
                : null;
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible consultar la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function readAll(array $filters = []): array
    {
        $status = isset($filters['status'])
            ? $this->validateStatus((string) $filters['status'])
            : null;

        $countryCode = isset($filters['country_code'])
            ? $this->validateCountryCode(
                (string) $filters['country_code']
            )
            : null;

        $isDemo = array_key_exists('is_demo', $filters)
            ? $this->toBoolean($filters['is_demo'], 'is_demo')
            : null;

        $search = isset($filters['search'])
            ? trim((string) $filters['search'])
            : null;

        $includeDeleted = array_key_exists(
            'include_deleted',
            $filters
        )
            ? $this->toBoolean(
                $filters['include_deleted'],
                'include_deleted'
            )
            : false;

        $limit = $this->validateLimit(
            $filters['limit'] ?? self::DEFAULT_LIMIT
        );

        $offset = $this->validateOffset(
            $filters['offset'] ?? 0
        );

        $conditions = [];
        $parameters = [];

        if (!$includeDeleted) {
            $conditions[] = 'deleted_at IS NULL';
        }

        if ($status !== null) {
            $conditions[] = 'status = :status';
            $parameters[':status'] = $status;
        }

        if ($countryCode !== null) {
            $conditions[] = 'country_code = :country_code';
            $parameters[':country_code'] = $countryCode;
        }

        if ($isDemo !== null) {
            $conditions[] = 'is_demo = :is_demo';
            $parameters[':is_demo'] = $isDemo;
        }

        if ($search !== null && $search !== '') {
            $conditions[] = <<<'SQL'
                (
                    name ILIKE :search
                    OR slug ILIKE :search
                    OR legal_name ILIKE :search
                    OR tax_id ILIKE :search
                    OR email ILIKE :search
                )
            SQL;

            $parameters[':search'] = '%' . $search . '%';
        }

        $sql = <<<'SQL'
            SELECT
                id,
                name,
                slug,
                legal_name,
                tax_id,
                email,
                phone,
                website,
                country_code,
                logo,
                primary_color,
                secondary_color,
                status,
                is_demo,
                settings,
                created_by,
                updated_by,
                version,
                created_at,
                updated_at,
                deleted_at
            FROM organizations
        SQL;

        if ($conditions !== []) {
            $sql .= "\nWHERE " . implode("\nAND ", $conditions);
        }

        $sql .= <<<'SQL'

            ORDER BY created_at DESC, name ASC
            LIMIT :limit
            OFFSET :offset
        SQL;

        try {
            $statement = $this->db->prepare($sql);

            foreach ($parameters as $parameter => $value) {
                if ($parameter === ':is_demo') {
                    $statement->bindValue(
                        $parameter,
                        $value,
                        PDO::PARAM_BOOL
                    );
                    continue;
                }

                $statement->bindValue($parameter, $value);
            }

            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();

            $organizations = $statement->fetchAll(PDO::FETCH_ASSOC);

            return array_map(
                fn (array $organization): array =>
                    $this->normalizeRow($organization),
                $organizations
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible consultar las organizaciones.',
                0,
                $exception
            );
        }
    }

    public function count(array $filters = []): int
    {
        $conditions = ['deleted_at IS NULL'];
        $parameters = [];

        if (isset($filters['status'])) {
            $conditions[] = 'status = :status';
            $parameters[':status'] = $this->validateStatus(
                (string) $filters['status']
            );
        }

        if (isset($filters['country_code'])) {
            $conditions[] = 'country_code = :country_code';
            $parameters[':country_code'] =
                $this->validateCountryCode(
                    (string) $filters['country_code']
                );
        }

        if (array_key_exists('is_demo', $filters)) {
            $conditions[] = 'is_demo = :is_demo';
            $parameters[':is_demo'] = $this->toBoolean(
                $filters['is_demo'],
                'is_demo'
            );
        }

        if (
            isset($filters['search'])
            && trim((string) $filters['search']) !== ''
        ) {
            $conditions[] = <<<'SQL'
                (
                    name ILIKE :search
                    OR slug ILIKE :search
                    OR legal_name ILIKE :search
                    OR tax_id ILIKE :search
                    OR email ILIKE :search
                )
            SQL;

            $parameters[':search'] =
                '%' . trim((string) $filters['search']) . '%';
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM organizations WHERE %s',
            implode(' AND ', $conditions)
        );

        try {
            $statement = $this->db->prepare($sql);

            foreach ($parameters as $parameter => $value) {
                if ($parameter === ':is_demo') {
                    $statement->bindValue(
                        $parameter,
                        $value,
                        PDO::PARAM_BOOL
                    );
                    continue;
                }

                $statement->bindValue($parameter, $value);
            }

            $statement->execute();

            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible contar las organizaciones.',
                0,
                $exception
            );
        }
    }

    public function exists(string $id): bool
    {
        $id = $this->validateUuid($id, 'id');

        $sql = <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM organizations
                WHERE id = :id
                  AND deleted_at IS NULL
            )
        SQL;

        try {
            $statement = $this->db->prepare($sql);
            $statement->bindValue(':id', $id);
            $statement->execute();

            return filter_var(
                $statement->fetchColumn(),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible comprobar la organización.',
                0,
                $exception
            );
        }
    }

    /**
     * Mantiene el mismo patrón de inicialización usado por los modelos
     * existentes. Las migraciones continúan siendo la fuente principal.
     */
    public function initDatabase(): void
    {
        $sql = <<<'SQL'
            CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

            CREATE TABLE IF NOT EXISTS organizations (
                id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(150) NOT NULL UNIQUE,
                legal_name VARCHAR(200),
                tax_id VARCHAR(50),
                email VARCHAR(160),
                phone VARCHAR(30),
                website VARCHAR(255),
                country_code CHAR(2) NOT NULL DEFAULT 'ES',
                logo VARCHAR(255),
                primary_color VARCHAR(20),
                secondary_color VARCHAR(20),
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                is_demo BOOLEAN NOT NULL DEFAULT FALSE,
                settings JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,
                created_by UUID NULL,
                updated_by UUID NULL,
                version INTEGER NOT NULL DEFAULT 1,

                CONSTRAINT organizations_status_check
                    CHECK (status IN ('active', 'inactive')),

                CONSTRAINT organizations_version_check
                    CHECK (version > 0)
            );

            CREATE INDEX IF NOT EXISTS idx_organizations_name
                ON organizations(name);

            CREATE INDEX IF NOT EXISTS idx_organizations_slug
                ON organizations(slug);

            CREATE INDEX IF NOT EXISTS idx_organizations_status
                ON organizations(status);

            CREATE INDEX IF NOT EXISTS idx_organizations_deleted_at
                ON organizations(deleted_at);

            CREATE INDEX IF NOT EXISTS idx_organizations_created_by
                ON organizations(created_by);

            CREATE INDEX IF NOT EXISTS idx_organizations_updated_by
                ON organizations(updated_by);
        SQL;

        try {
            $this->db->exec($sql);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'No fue posible inicializar la tabla organizations.',
                0,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateCreatePayload(array $data): array
    {
        $name = $this->requireString(
            $data,
            'name',
            150
        );

        $slug = array_key_exists('slug', $data)
            ? $this->validateSlug((string) $data['slug'])
            : $this->validateSlug($this->generateSlug($name));

        return [
            'name' => $name,
            'slug' => $slug,
            'legal_name' => $this->optionalString(
                $data,
                'legal_name',
                200
            ),
            'tax_id' => $this->optionalString(
                $data,
                'tax_id',
                50
            ),
            'email' => $this->validateOptionalEmail(
                $data['email'] ?? null
            ),
            'phone' => $this->optionalString(
                $data,
                'phone',
                30
            ),
            'website' => $this->validateOptionalUrl(
                $data['website'] ?? null
            ),
            'country_code' => $this->validateCountryCode(
                (string) ($data['country_code'] ?? 'ES')
            ),
            'logo' => $this->optionalString(
                $data,
                'logo',
                255
            ),
            'primary_color' => $this->validateOptionalColor(
                $data['primary_color'] ?? null,
                'primary_color'
            ),
            'secondary_color' => $this->validateOptionalColor(
                $data['secondary_color'] ?? null,
                'secondary_color'
            ),
            'status' => $this->validateStatus(
                (string) ($data['status'] ?? self::STATUS_ACTIVE)
            ),
            'is_demo' => array_key_exists('is_demo', $data)
                ? $this->toBoolean($data['is_demo'], 'is_demo')
                : false,
            'settings' => $this->validateSettings(
                $data['settings'] ?? []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateUpdatePayload(array $data): array
    {
        if (!array_key_exists('version', $data)) {
            throw new InvalidArgumentException(
                'El campo version es obligatorio para actualizar.'
            );
        }

        $version = filter_var(
            $data['version'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($version === false) {
            throw new InvalidArgumentException(
                'El campo version debe ser un entero mayor que cero.'
            );
        }

        $allowedFields = [
            'name',
            'slug',
            'legal_name',
            'tax_id',
            'email',
            'phone',
            'website',
            'country_code',
            'logo',
            'primary_color',
            'secondary_color',
            'status',
            'is_demo',
            'settings',
        ];

        $payload = ['version' => (int) $version];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $payload[$field] = match ($field) {
                'name' => $this->requireString(
                    $data,
                    'name',
                    150
                ),
                'slug' => $this->validateSlug(
                    (string) $data['slug']
                ),
                'legal_name' => $this->optionalString(
                    $data,
                    'legal_name',
                    200
                ),
                'tax_id' => $this->optionalString(
                    $data,
                    'tax_id',
                    50
                ),
                'email' => $this->validateOptionalEmail(
                    $data['email']
                ),
                'phone' => $this->optionalString(
                    $data,
                    'phone',
                    30
                ),
                'website' => $this->validateOptionalUrl(
                    $data['website']
                ),
                'country_code' => $this->validateCountryCode(
                    (string) $data['country_code']
                ),
                'logo' => $this->optionalString(
                    $data,
                    'logo',
                    255
                ),
                'primary_color' => $this->validateOptionalColor(
                    $data['primary_color'],
                    'primary_color'
                ),
                'secondary_color' => $this->validateOptionalColor(
                    $data['secondary_color'],
                    'secondary_color'
                ),
                'status' => $this->validateStatus(
                    (string) $data['status']
                ),
                'is_demo' => $this->toBoolean(
                    $data['is_demo'],
                    'is_demo'
                ),
                'settings' => $this->validateSettings(
                    $data['settings']
                ),
            };
        }

        if (count($payload) === 1) {
            throw new InvalidArgumentException(
                'Debes indicar al menos un campo para actualizar.'
            );
        }

        return $payload;
    }

    private function slugExists(
        string $slug,
        ?string $excludeId = null
    ): bool {
        $sql = <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM organizations
                WHERE slug = :slug
        SQL;

        if ($excludeId !== null) {
            $excludeId = $this->validateUuid(
                $excludeId,
                'exclude_id'
            );

            $sql .= ' AND id <> :exclude_id';
        }

        $sql .= ')';

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':slug', $slug);

        if ($excludeId !== null) {
            $statement->bindValue(':exclude_id', $excludeId);
        }

        $statement->execute();

        return filter_var(
            $statement->fetchColumn(),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(
        array $data,
        string $field,
        int $maxLength
    ): string {
        if (!array_key_exists($field, $data)) {
            throw new InvalidArgumentException(
                sprintf('El campo %s es obligatorio.', $field)
            );
        }

        $value = trim((string) $data[$field]);

        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf('El campo %s no puede estar vacío.', $field)
            );
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                sprintf(
                    'El campo %s no puede superar %d caracteres.',
                    $field,
                    $maxLength
                )
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(
        array $data,
        string $field,
        int $maxLength
    ): ?string {
        if (
            !array_key_exists($field, $data)
            || $data[$field] === null
        ) {
            return null;
        }

        $value = trim((string) $data[$field]);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                sprintf(
                    'El campo %s no puede superar %d caracteres.',
                    $field,
                    $maxLength
                )
            );
        }

        return $value;
    }

    private function validateSlug(string $slug): string
    {
        $slug = $this->normalizeSlug($slug);

        if ($slug === '') {
            throw new InvalidArgumentException(
                'El slug es obligatorio.'
            );
        }

        if (mb_strlen($slug) > 150) {
            throw new InvalidArgumentException(
                'El slug no puede superar 150 caracteres.'
            );
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException(
                'El slug solo puede contener letras minúsculas, '
                . 'números y guiones simples.'
            );
        }

        return $slug;
    }

    private function normalizeSlug(string $slug): string
    {
        return trim(mb_strtolower($slug));
    }

    private function generateSlug(string $value): string
    {
        $value = trim(mb_strtolower($value));

        $transliterated = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value;
    }

    private function validateStatus(string $status): string
    {
        $status = trim(mb_strtolower($status));

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'El estado debe ser active o inactive.'
            );
        }

        return $status;
    }

    private function validateCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));

        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new InvalidArgumentException(
                'country_code debe contener exactamente dos letras.'
            );
        }

        return $countryCode;
    }

    private function validateOptionalEmail(mixed $email): ?string
    {
        if ($email === null || trim((string) $email) === '') {
            return null;
        }

        $email = mb_strtolower(trim((string) $email));

        if (mb_strlen($email) > 160) {
            throw new InvalidArgumentException(
                'El email no puede superar 160 caracteres.'
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                'El email no tiene un formato válido.'
            );
        }

        return $email;
    }

    private function validateOptionalUrl(mixed $url): ?string
    {
        if ($url === null || trim((string) $url) === '') {
            return null;
        }

        $url = trim((string) $url);

        if (mb_strlen($url) > 255) {
            throw new InvalidArgumentException(
                'La URL no puede superar 255 caracteres.'
            );
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(
                'La URL no tiene un formato válido.'
            );
        }

        return $url;
    }

    private function validateOptionalColor(
        mixed $color,
        string $field
    ): ?string {
        if ($color === null || trim((string) $color) === '') {
            return null;
        }

        $color = strtoupper(trim((string) $color));

        if (
            !preg_match(
                '/^#(?:[A-F0-9]{3}|[A-F0-9]{6}|[A-F0-9]{8})$/',
                $color
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'El campo %s debe ser un color hexadecimal válido.',
                    $field
                )
            );
        }

        return $color;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSettings(mixed $settings): array
    {
        if ($settings === null) {
            return [];
        }

        if (is_string($settings)) {
            try {
                $settings = json_decode(
                    $settings,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable $exception) {
                throw new InvalidArgumentException(
                    'El campo settings debe contener un JSON válido.',
                    0,
                    $exception
                );
            }
        }

        if (!is_array($settings)) {
            throw new InvalidArgumentException(
                'El campo settings debe ser un objeto JSON.'
            );
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function encodeSettings(array $settings): string
    {
        try {
            return json_encode(
                $settings,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'No fue posible serializar el campo settings.',
                0,
                $exception
            );
        }
    }

    private function toBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        $normalized = mb_strtolower(trim((string) $value));

        if (in_array($normalized, ['true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', 'no', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException(
            sprintf('El campo %s debe ser booleano.', $field)
        );
    }

    private function validateUuid(string $uuid, string $field): string
    {
        $uuid = trim($uuid);

        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}'
                . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $uuid
            )
        ) {
            throw new InvalidArgumentException(
                sprintf('El campo %s no contiene un UUID válido.', $field)
            );
        }

        return mb_strtolower($uuid);
    }

    private function validateOptionalUuid(
        ?string $uuid,
        string $field
    ): ?string {
        if ($uuid === null || trim($uuid) === '') {
            return null;
        }

        return $this->validateUuid($uuid, $field);
    }

    private function validateLimit(mixed $limit): int
    {
        $validated = filter_var(
            $limit,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => self::MAX_LIMIT,
                ],
            ]
        );

        if ($validated === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'El límite debe estar entre 1 y %d.',
                    self::MAX_LIMIT
                )
            );
        }

        return (int) $validated;
    }

    private function validateOffset(mixed $offset): int
    {
        $validated = filter_var(
            $offset,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        if ($validated === false) {
            throw new InvalidArgumentException(
                'El offset debe ser un entero igual o mayor que cero.'
            );
        }

        return (int) $validated;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        if (isset($row['settings']) && is_string($row['settings'])) {
            try {
                $row['settings'] = json_decode(
                    $row['settings'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                $row['settings'] = [];
            }
        }

        $row['is_demo'] = filter_var(
            $row['is_demo'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $row['version'] = (int) ($row['version'] ?? 1);

        return $row;
    }

    private function isUniqueViolation(
        PDOException $exception
    ): bool {
        return (string) $exception->getCode() === '23505';
    }

    private function isForeignKeyViolation(
        PDOException $exception
    ): bool {
        return (string) $exception->getCode() === '23503';
    }
}
