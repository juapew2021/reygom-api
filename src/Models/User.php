<?php

declare(strict_types=1);

class User
{
    private PDO $conn;
    private string $tableName = 'users';

    private const ALLOWED_ROLES = [
        'superadmin',
        'admin',
        'agent',
    ];

    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
    ];

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    /**
     * Autentica al usuario y actualiza su último acceso.
     */
    public function authenticate(string $username, string $password): array|false
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return false;
        }

        $query = "
            SELECT
                id,
                username,
                full_name,
                email,
                phone,
                password,
                role,
                status,
                last_login,
                created_at,
                updated_at
            FROM {$this->tableName}
            WHERE LOWER(username) = LOWER(:username)
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':username' => $username,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        if (($row['status'] ?? 'inactive') !== 'active') {
            return false;
        }

        if (!password_verify($password, $row['password'])) {
            return false;
        }

        $this->updateLastLogin((string) $row['id']);

        unset($row['password']);

        $row['last_login'] = date('Y-m-d H:i:s');

        return $row;
    }

    /**
     * Devuelve todos los usuarios sin exponer contraseñas.
     */
    public function readAll(): array
    {
        $query = "
            SELECT
                id,
                username,
                full_name,
                email,
                phone,
                role,
                status,
                last_login,
                created_at,
                updated_at
            FROM {$this->tableName}
            ORDER BY created_at DESC
        ";

        $stmt = $this->conn->query($query);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca un usuario por UUID.
     */
    public function readOne(string $id): array|false
    {
        $this->validateUuid($id);

        $query = "
            SELECT
                id,
                username,
                full_name,
                email,
                phone,
                role,
                status,
                last_login,
                created_at,
                updated_at
            FROM {$this->tableName}
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $id,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Compatibilidad con el método utilizado anteriormente.
     */
    public function findById(string $id): array|false
    {
        return $this->readOne($id);
    }

    /**
     * Crea un usuario.
     */
    public function create(array $data): array
    {
        $payload = $this->validateCreatePayload($data);

        if ($this->usernameExists($payload['username'])) {
            throw new RuntimeException('El nombre de usuario ya está registrado.');
        }

        if ($this->emailExists($payload['email'])) {
            throw new RuntimeException('El correo electrónico ya está registrado.');
        }

        $query = "
            INSERT INTO {$this->tableName} (
                username,
                full_name,
                email,
                phone,
                password,
                role,
                status
            )
            VALUES (
                :username,
                :full_name,
                :email,
                :phone,
                :password,
                :role,
                :status
            )
            RETURNING
                id,
                username,
                full_name,
                email,
                phone,
                role,
                status,
                last_login,
                created_at,
                updated_at
        ";

        $stmt = $this->conn->prepare($query);

        $stmt->execute([
            ':username'  => $payload['username'],
            ':full_name' => $payload['full_name'],
            ':email'     => $payload['email'],
            ':phone'     => $payload['phone'],
            ':password'  => password_hash(
                $payload['password'],
                PASSWORD_DEFAULT
            ),
            ':role'      => $payload['role'],
            ':status'    => $payload['status'],
        ]);

        $createdUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$createdUser) {
            throw new RuntimeException('No fue posible crear el usuario.');
        }

        return $createdUser;
    }

    /**
     * Actualiza los datos generales de un usuario.
     */
    public function update(string $id, array $data): array
    {
        $this->validateUuid($id);

        if (!$this->exists($id)) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        $payload = $this->validateUpdatePayload($data);

        if (
            isset($payload['username'])
            && $this->usernameExists($payload['username'], $id)
        ) {
            throw new RuntimeException('El nombre de usuario ya está registrado.');
        }

        if (
            isset($payload['email'])
            && $this->emailExists($payload['email'], $id)
        ) {
            throw new RuntimeException('El correo electrónico ya está registrado.');
        }

        if ($payload === []) {
            throw new InvalidArgumentException(
                'No se proporcionaron campos válidos para actualizar.'
            );
        }

        $setParts = [];
        $params = [':id' => $id];

        foreach ($payload as $field => $value) {
            $placeholder = ':' . $field;
            $setParts[] = "{$field} = {$placeholder}";
            $params[$placeholder] = $value;
        }

        $setParts[] = 'updated_at = CURRENT_TIMESTAMP';

        $query = "
            UPDATE {$this->tableName}
            SET " . implode(', ', $setParts) . "
            WHERE id = :id
            RETURNING
                id,
                username,
                full_name,
                email,
                phone,
                role,
                status,
                last_login,
                created_at,
                updated_at
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$updatedUser) {
            throw new RuntimeException('No fue posible actualizar el usuario.');
        }

        return $updatedUser;
    }

    /**
     * Cambia la contraseña de un usuario.
     */
    public function changePassword(string $id, string $password): bool
    {
        $this->validateUuid($id);
        $this->validatePassword($password);

        if (!$this->exists($id)) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        $query = "
            UPDATE {$this->tableName}
            SET
                password = :password,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($query);

        return $stmt->execute([
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':id'       => $id,
        ]);
    }

    /**
     * Activa o desactiva un usuario.
     */
    public function changeStatus(string $id, string $status): array
    {
        $this->validateUuid($id);
        $status = $this->validateStatus($status);

        if (!$this->exists($id)) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        $query = "
            UPDATE {$this->tableName}
            SET
                status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            RETURNING
                id,
                username,
                full_name,
                email,
                phone,
                role,
                status,
                last_login,
                created_at,
                updated_at
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':status' => $status,
            ':id'     => $id,
        ]);

        $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$updatedUser) {
            throw new RuntimeException(
                'No fue posible cambiar el estado del usuario.'
            );
        }

        return $updatedUser;
    }

    /**
     * Cambia solamente el rol.
     */
    public function changeRole(string $id, string $role): array
    {
        $this->validateUuid($id);
        $role = $this->validateRole($role);

        return $this->update($id, [
            'role' => $role,
        ]);
    }

    /**
     * Elimina un usuario.
     *
     * Antes de eliminar se comprueba si tiene inmuebles o clientes asignados.
     */
    public function delete(string $id): bool
    {
        $this->validateUuid($id);

        $user = $this->readOne($id);

        if (!$user) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        if ($user['role'] === 'superadmin') {
            throw new RuntimeException(
                'No se puede eliminar directamente un usuario superadmin.'
            );
        }

        if ($this->hasAssignedRecords($id)) {
            throw new RuntimeException(
                'El usuario tiene inmuebles o clientes asignados. '
                . 'Debe reasignarlos antes de eliminarlo.'
            );
        }

        $query = "
            DELETE FROM {$this->tableName}
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function exists(string $id): bool
    {
        $this->validateUuid($id);

        $query = "
            SELECT 1
            FROM {$this->tableName}
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $id,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function usernameExists(
        string $username,
        ?string $excludeId = null
    ): bool {
        $username = trim($username);

        $query = "
            SELECT 1
            FROM {$this->tableName}
            WHERE LOWER(username) = LOWER(:username)
        ";

        $params = [
            ':username' => $username,
        ];

        if ($excludeId !== null) {
            $this->validateUuid($excludeId);
            $query .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $query .= ' LIMIT 1';

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function emailExists(
        string $email,
        ?string $excludeId = null
    ): bool {
        $email = strtolower(trim($email));

        $query = "
            SELECT 1
            FROM {$this->tableName}
            WHERE LOWER(email) = LOWER(:email)
        ";

        $params = [
            ':email' => $email,
        ];

        if ($excludeId !== null) {
            $this->validateUuid($excludeId);
            $query .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $query .= ' LIMIT 1';

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Conservamos initDatabase para instalaciones nuevas.
     */
    public function initDatabase(): string
    {
        $query = "
            CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";

            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                username VARCHAR(50) UNIQUE NOT NULL,
                full_name VARCHAR(120) NOT NULL,
                email VARCHAR(160) UNIQUE NOT NULL,
                phone VARCHAR(30),
                password VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'agent',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_login TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT users_role_check
                    CHECK (role IN ('superadmin', 'admin', 'agent')),
                CONSTRAINT users_status_check
                    CHECK (status IN ('active', 'inactive'))
            )
        ";

        $this->conn->exec($query);

        return 'Tabla de usuarios verificada correctamente.';
    }

    private function updateLastLogin(string $id): void
    {
        $query = "
            UPDATE {$this->tableName}
            SET
                last_login = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $id,
        ]);
    }

    private function hasAssignedRecords(string $id): bool
    {
        $query = "
            SELECT EXISTS (
                SELECT 1
                FROM properties
                WHERE agent_id = :property_user_id
            )
            OR EXISTS (
                SELECT 1
                FROM leads
                WHERE assigned_to = :lead_user_id
            )
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':property_user_id' => $id,
            ':lead_user_id'     => $id,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function validateCreatePayload(array $data): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = $this->normalizeNullableString($data['phone'] ?? null);
        $password = (string) ($data['password'] ?? '');
        $role = $this->validateRole(
            (string) ($data['role'] ?? 'agent')
        );
        $status = $this->validateStatus(
            (string) ($data['status'] ?? 'active')
        );

        $this->validateUsername($username);
        $this->validateFullName($fullName);
        $this->validateEmail($email);
        $this->validatePhone($phone);
        $this->validatePassword($password);

        return [
            'username'  => $username,
            'full_name' => $fullName,
            'email'     => $email,
            'phone'     => $phone,
            'password'  => $password,
            'role'      => $role,
            'status'    => $status,
        ];
    }

    private function validateUpdatePayload(array $data): array
    {
        $payload = [];

        if (array_key_exists('username', $data)) {
            $username = trim((string) $data['username']);
            $this->validateUsername($username);
            $payload['username'] = $username;
        }

        if (array_key_exists('full_name', $data)) {
            $fullName = trim((string) $data['full_name']);
            $this->validateFullName($fullName);
            $payload['full_name'] = $fullName;
        }

        if (array_key_exists('email', $data)) {
            $email = strtolower(trim((string) $data['email']));
            $this->validateEmail($email);
            $payload['email'] = $email;
        }

        if (array_key_exists('phone', $data)) {
            $phone = $this->normalizeNullableString($data['phone']);
            $this->validatePhone($phone);
            $payload['phone'] = $phone;
        }

        if (array_key_exists('role', $data)) {
            $payload['role'] = $this->validateRole(
                (string) $data['role']
            );
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $this->validateStatus(
                (string) $data['status']
            );
        }

        return $payload;
    }

    private function validateUsername(string $username): void
    {
        if ($username === '') {
            throw new InvalidArgumentException(
                'El nombre de usuario es obligatorio.'
            );
        }

        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new InvalidArgumentException(
                'El usuario debe tener entre 3 y 50 caracteres.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            throw new InvalidArgumentException(
                'El usuario solo puede contener letras, números, puntos, '
                . 'guiones y guiones bajos.'
            );
        }
    }

    private function validateFullName(string $fullName): void
    {
        if ($fullName === '') {
            throw new InvalidArgumentException(
                'El nombre completo es obligatorio.'
            );
        }

        if (strlen($fullName) > 120) {
            throw new InvalidArgumentException(
                'El nombre completo no puede superar 120 caracteres.'
            );
        }
    }

    private function validateEmail(string $email): void
    {
        if ($email === '') {
            throw new InvalidArgumentException(
                'El correo electrónico es obligatorio.'
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'El correo electrónico no es válido.'
            );
        }

        if (strlen($email) > 160) {
            throw new InvalidArgumentException(
                'El correo electrónico no puede superar 160 caracteres.'
            );
        }
    }

    private function validatePhone(?string $phone): void
    {
        if ($phone === null) {
            return;
        }

        if (strlen($phone) > 30) {
            throw new InvalidArgumentException(
                'El teléfono no puede superar 30 caracteres.'
            );
        }
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException(
                'La contraseña debe contener al menos 8 caracteres.'
            );
        }

        if (strlen($password) > 255) {
            throw new InvalidArgumentException(
                'La contraseña es demasiado larga.'
            );
        }
    }

    private function validateRole(string $role): string
    {
        $role = strtolower(trim($role));

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException(
                'Rol no permitido. Valores válidos: '
                . implode(', ', self::ALLOWED_ROLES) . '.'
            );
        }

        return $role;
    }

    private function validateStatus(string $status): string
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Estado no permitido. Valores válidos: '
                . implode(', ', self::ALLOWED_STATUSES) . '.'
            );
        }

        return $status;
    }

    private function validateUuid(string $id): void
    {
        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $id
            )
        ) {
            throw new InvalidArgumentException(
                'El identificador del usuario no es un UUID válido.'
            );
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
