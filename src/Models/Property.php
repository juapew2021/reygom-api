<?php

declare(strict_types=1);

class Property
{
    private PDO $conn;
    private string $table = 'properties';

    /**
     * Campos que se pueden insertar o modificar desde la API.
     *
     * id, created_at y agent_id se gestionan de forma separada.
     */
    private const WRITABLE_FIELDS = [
        'reference_id',
        'title',
        'description',
        'price',
        'operation_type',
        'property_type',
        'status',
        'features',
        'image_url',
        'cover_image',
        'city',
        'province',
        'bedrooms',
        'bathrooms',
        'built_area',
        'country',
        'country_code',
        'state',
        'state_code',
        'postal_code',
        'address',
        'neighborhood',
        'latitude',
        'longitude',
    ];

    public function __construct(PDO $db)
    {
        $this->conn = $db;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Devuelve todas las propiedades.
     * Uso exclusivo del administrador.
     */
    public function readAll(): array
    {
        $sql = $this->baseSelect() . '
            ORDER BY p.created_at DESC, p.id DESC
        ';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $this->normalizeRows($stmt->fetchAll());
    }

    /**
     * Devuelve solamente las propiedades pertenecientes a un agente.
     */
    public function readAllByAgent(string $agentId): array
    {
        $this->assertUuid($agentId, 'agent_id');

        $sql = $this->baseSelect() . '
            WHERE p.agent_id = :agent_id
            ORDER BY p.created_at DESC, p.id DESC
        ';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':agent_id', $agentId, PDO::PARAM_STR);
        $stmt->execute();

        return $this->normalizeRows($stmt->fetchAll());
    }

    /**
     * Busca una propiedad por ID.
     * Uso del administrador.
     */
    public function readOne(string $id): ?array
    {
        $this->assertUuid($id, 'id');

        $sql = $this->baseSelect() . '
            WHERE p.id = :id
            LIMIT 1
        ';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        $property = $stmt->fetch();

        return $property !== false
            ? $this->normalizeRow($property)
            : null;
    }

    /**
     * Busca una propiedad por ID comprobando que pertenece al agente.
     */
    public function readOneByAgent(string $id, string $agentId): ?array
    {
        $this->assertUuid($id, 'id');
        $this->assertUuid($agentId, 'agent_id');

        $sql = $this->baseSelect() . '
            WHERE p.id = :id
              AND p.agent_id = :agent_id
            LIMIT 1
        ';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->bindValue(':agent_id', $agentId, PDO::PARAM_STR);
        $stmt->execute();

        $property = $stmt->fetch();

        return $property !== false
            ? $this->normalizeRow($property)
            : null;
    }

    /**
     * Crea una propiedad.
     *
     * Para un agente, $agentId debe ser el UUID obtenido del JWT.
     * Para un administrador, la ruta puede enviar el agente asignado.
     *
     * Devuelve la propiedad creada o false si no pudo insertarse.
     */
    public function create(array $data, ?string $agentId = null): array|false
    {
        $agentId = $agentId ?? $this->nullableString($data['agent_id'] ?? null);

        if ($agentId === null) {
            throw new InvalidArgumentException('El campo agent_id es obligatorio.');
        }

        $this->assertUuid($agentId, 'agent_id');

        $cleanData = $this->sanitizeData($data, true);
        $this->validateRequiredFields($cleanData);

        if ($this->referenceExists((string) $cleanData['reference_id'])) {
            throw new RuntimeException(
                'Ya existe una propiedad con el reference_id indicado.'
            );
        }

        $id = $this->generateUuidV4();

        $fields = ['id', 'agent_id'];
        $placeholders = [':id', ':agent_id'];
        $parameters = [
            ':id' => $id,
            ':agent_id' => $agentId,
        ];

        foreach ($cleanData as $field => $value) {
            $fields[] = $field;
            $placeholders[] = ':' . $field;
            $parameters[':' . $field] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s)
             VALUES (%s)',
            $this->table,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );

        $stmt = $this->conn->prepare($sql);
        $this->bindValues($stmt, $parameters);

        if (!$stmt->execute()) {
            return false;
        }

        return $this->readOne($id) ?? false;
    }

    /**
     * Actualiza una propiedad aplicando permisos multiagente.
     *
     * Administrador:
     *  - Puede actualizar cualquier propiedad.
     *  - Puede reasignar agent_id si la ruta lo permite.
     *
     * Agente:
     *  - Solo puede actualizar sus propiedades.
     *  - No puede cambiar agent_id.
     */
    public function update(
        string $id,
        array $data,
        string $userId,
        bool $isAdmin = false
    ): bool {
        $this->assertUuid($id, 'id');
        $this->assertUuid($userId, 'user_id');

        if (!$isAdmin && !$this->belongsToAgent($id, $userId)) {
            return false;
        }

        if (!$this->exists($id)) {
            return false;
        }

        $cleanData = $this->sanitizeData($data, false);

        if (
            isset($cleanData['reference_id']) &&
            $this->referenceExists((string) $cleanData['reference_id'], $id)
        ) {
            throw new RuntimeException(
                'Ya existe otra propiedad con el reference_id indicado.'
            );
        }

        $assignments = [];
        $parameters = [
            ':id' => $id,
        ];

        foreach ($cleanData as $field => $value) {
            $assignments[] = $field . ' = :' . $field;
            $parameters[':' . $field] = $value;
        }

        if ($isAdmin && array_key_exists('agent_id', $data)) {
            $newAgentId = $this->nullableString($data['agent_id']);

            if ($newAgentId === null) {
                throw new InvalidArgumentException(
                    'agent_id no puede quedar vacío.'
                );
            }

            $this->assertUuid($newAgentId, 'agent_id');

            $assignments[] = 'agent_id = :agent_id';
            $parameters[':agent_id'] = $newAgentId;
        }

        if ($assignments === []) {
            return true;
        }

        $sql = sprintf(
            'UPDATE %s
             SET %s
             WHERE id = :id',
            $this->table,
            implode(', ', $assignments)
        );

        if (!$isAdmin) {
            $sql .= ' AND agent_id = :owner_agent_id';
            $parameters[':owner_agent_id'] = $userId;
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindValues($stmt, $parameters);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina una propiedad respetando el propietario.
     */
    public function delete(
        string $id,
        string $userId,
        bool $isAdmin = false
    ): bool {
        $this->assertUuid($id, 'id');
        $this->assertUuid($userId, 'user_id');

        $sql = sprintf(
            'DELETE FROM %s WHERE id = :id',
            $this->table
        );

        $parameters = [
            ':id' => $id,
        ];

        if (!$isAdmin) {
            $sql .= ' AND agent_id = :agent_id';
            $parameters[':agent_id'] = $userId;
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindValues($stmt, $parameters);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Comprueba si existe una propiedad.
     */
    public function exists(string $id): bool
    {
        $this->assertUuid($id, 'id');

        $sql = sprintf(
            'SELECT 1
             FROM %s
             WHERE id = :id
             LIMIT 1',
            $this->table
        );

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Comprueba si la propiedad pertenece al agente.
     */
    public function belongsToAgent(string $id, string $agentId): bool
    {
        $this->assertUuid($id, 'id');
        $this->assertUuid($agentId, 'agent_id');

        $sql = sprintf(
            'SELECT 1
             FROM %s
             WHERE id = :id
               AND agent_id = :agent_id
             LIMIT 1',
            $this->table
        );

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->bindValue(':agent_id', $agentId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Reasigna una propiedad a otro agente.
     * Debe utilizarse solamente desde rutas autorizadas para administradores.
     */
    public function assignAgent(string $propertyId, string $agentId): bool
    {
        $this->assertUuid($propertyId, 'property_id');
        $this->assertUuid($agentId, 'agent_id');

        $sql = sprintf(
            'UPDATE %s
             SET agent_id = :agent_id
             WHERE id = :id',
            $this->table
        );

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':agent_id', $agentId, PDO::PARAM_STR);
        $stmt->bindValue(':id', $propertyId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Consulta común reutilizada por todos los métodos de lectura.
     */
    private function baseSelect(): string
    {
        return '
            SELECT
                p.id,
                p.reference_id,
                p.agent_id,
                p.title,
                p.description,
                p.price,
                p.operation_type,
                p.property_type,
                p.status,
                p.features,
                p.created_at,
                p.image_url,
                p.cover_image,
                p.city,
                p.province,
                p.bedrooms,
                p.bathrooms,
                p.built_area,
                p.country,
                p.country_code,
                p.state,
                p.state_code,
                p.postal_code,
                p.address,
                p.neighborhood,
                p.latitude,
                p.longitude
            FROM properties p
        ';
    }

    /**
     * Limpia y valida los datos permitidos.
     */
    private function sanitizeData(array $data, bool $creating): array
    {
        $clean = [];

        foreach (self::WRITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            switch ($field) {
                case 'reference_id':
                case 'title':
                case 'operation_type':
                case 'property_type':
                    $clean[$field] = $this->requiredString($value, $field);
                    break;

                case 'description':
                case 'status':
                case 'image_url':
                case 'cover_image':
                case 'city':
                case 'province':
                case 'country':
                case 'country_code':
                case 'state':
                case 'state_code':
                case 'postal_code':
                case 'address':
                case 'neighborhood':
                    $clean[$field] = $this->nullableString($value);
                    break;

                case 'price':
                    $clean[$field] = $this->requiredNumeric($value, 'price', 0);
                    break;

                case 'built_area':
                    $clean[$field] = $this->nullableNumeric(
                        $value,
                        'built_area',
                        0
                    );
                    break;

                case 'latitude':
                    $clean[$field] = $this->nullableNumeric(
                        $value,
                        'latitude',
                        -90,
                        90
                    );
                    break;

                case 'longitude':
                    $clean[$field] = $this->nullableNumeric(
                        $value,
                        'longitude',
                        -180,
                        180
                    );
                    break;

                case 'bedrooms':
                case 'bathrooms':
                    $clean[$field] = $this->nullableInteger(
                        $value,
                        $field,
                        0
                    );
                    break;

                case 'features':
                    $clean[$field] = $this->encodeJson($value);
                    break;
            }
        }

        if ($creating) {
            $clean['status'] = array_key_exists('status', $clean)
                ? $clean['status']
                : 'available';

            $clean['features'] = array_key_exists('features', $clean)
                ? $clean['features']
                : '{}';
        }

        return $clean;
    }

    private function validateRequiredFields(array $data): void
    {
        $required = [
            'reference_id',
            'title',
            'price',
            'operation_type',
            'property_type',
        ];

        foreach ($required as $field) {
            if (
                !array_key_exists($field, $data) ||
                $data[$field] === null ||
                $data[$field] === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf('El campo %s es obligatorio.', $field)
                );
            }
        }
    }

    private function referenceExists(
        string $referenceId,
        ?string $excludeId = null
    ): bool {
        $sql = sprintf(
            'SELECT 1
             FROM %s
             WHERE reference_id = :reference_id',
            $this->table
        );

        $parameters = [
            ':reference_id' => $referenceId,
        ];

        if ($excludeId !== null) {
            $this->assertUuid($excludeId, 'exclude_id');

            $sql .= ' AND id <> :exclude_id';
            $parameters[':exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $this->bindValues($stmt, $parameters);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    private function normalizeRows(array $rows): array
    {
        return array_map(
            fn(array $row): array => $this->normalizeRow($row),
            $rows
        );
    }

    /**
     * Convierte los tipos que PostgreSQL devuelve como texto.
     */
    private function normalizeRow(array $row): array
    {
        foreach (['price', 'built_area', 'latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (float) $row[$field];
            }
        }

        foreach (['bedrooms', 'bathrooms'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        if (array_key_exists('features', $row)) {
            if ($row['features'] === null || $row['features'] === '') {
                $row['features'] = [];
            } elseif (is_string($row['features'])) {
                $decoded = json_decode($row['features'], true);
                $row['features'] = is_array($decoded) ? $decoded : [];
            }
        }

        return $row;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    'El campo features contiene un JSON inválido.'
                );
            }

            $value = $decoded;
        }

        if (!is_array($value) && !is_object($value)) {
            throw new InvalidArgumentException(
                'El campo features debe ser un objeto, un array o JSON válido.'
            );
        }

        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

        return $json;
    }

    private function requiredString(mixed $value, string $field): string
    {
        $normalized = $this->nullableString($value);

        if ($normalized === null) {
            throw new InvalidArgumentException(
                sprintf('El campo %s es obligatorio.', $field)
            );
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException(
                'Se recibió un valor no válido donde se esperaba texto.'
            );
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function requiredNumeric(
        mixed $value,
        string $field,
        ?float $minimum = null,
        ?float $maximum = null
    ): string {
        $numeric = $this->nullableNumeric(
            $value,
            $field,
            $minimum,
            $maximum
        );

        if ($numeric === null) {
            throw new InvalidArgumentException(
                sprintf('El campo %s es obligatorio.', $field)
            );
        }

        return $numeric;
    }

    /**
     * Se devuelve como string para conservar precisión con PostgreSQL numeric.
     */
    private function nullableNumeric(
        mixed $value,
        string $field,
        ?float $minimum = null,
        ?float $maximum = null
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                sprintf('El campo %s debe ser numérico.', $field)
            );
        }

        $number = (float) $value;

        if ($minimum !== null && $number < $minimum) {
            throw new InvalidArgumentException(
                sprintf(
                    'El campo %s no puede ser inferior a %s.',
                    $field,
                    $minimum
                )
            );
        }

        if ($maximum !== null && $number > $maximum) {
            throw new InvalidArgumentException(
                sprintf(
                    'El campo %s no puede ser superior a %s.',
                    $field,
                    $maximum
                )
            );
        }

        return (string) $value;
    }

    private function nullableInteger(
        mixed $value,
        string $field,
        int $minimum = 0
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false) {
            throw new InvalidArgumentException(
                sprintf('El campo %s debe ser un número entero.', $field)
            );
        }

        if ($validated < $minimum) {
            throw new InvalidArgumentException(
                sprintf(
                    'El campo %s no puede ser inferior a %d.',
                    $field,
                    $minimum
                )
            );
        }

        return $validated;
    }

    private function bindValues(PDOStatement $stmt, array $parameters): void
    {
        foreach ($parameters as $placeholder => $value) {
            if ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            if (is_bool($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_BOOL);
                continue;
            }

            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }

    private function assertUuid(string $value, string $field): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-'
            . '[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        if (!preg_match($pattern, $value)) {
            throw new InvalidArgumentException(
                sprintf('El campo %s no contiene un UUID válido.', $field)
            );
        }
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }
}