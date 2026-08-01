<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class User
{
    private PDO $conn;
    private string $tableName = 'users';

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Autentica un usuario por username y contraseña.
     *
     * @return array<string, mixed>|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return null;
        }

        $query = "
            SELECT
                id,
                username,
                password,
                role,
                organization_id,
                created_at
            FROM {$this->tableName}
            WHERE LOWER(username) = LOWER(:username)
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':username' => $username,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        if (!password_verify($password, (string) $user['password'])) {
            return null;
        }

        unset($user['password']);

        return $user;
    }

    /**
     * Busca un usuario por ID dentro de una organización.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id, string $organizationId): ?array
    {
        $this->validateUuid($id);
        $this->validateUuid($organizationId);

        $query = "
            SELECT
                id,
                username,
                role,
                organization_id,
                created_at
            FROM {$this->tableName}
            WHERE id = :id
              AND organization_id = :organization_id
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $id,
            ':organization_id' => $organizationId,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Lista usuarios de una organización.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(string $organizationId): array
    {
        $this->validateUuid($organizationId);

        $query = "
            SELECT
                id,
                username,
                role,
                organization_id,
                created_at
            FROM {$this->tableName}
            WHERE organization_id = :organization_id
            ORDER BY created_at DESC
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':organization_id' => $organizationId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea un usuario.
     *
     * @return array<string, mixed>
     */
    public function create(
        string $username,
        string $password,
        string $role,
        string $organizationId
    ): array {
        $username = trim($username);

        if ($username === '') {
            throw new InvalidArgumentException('El username es obligatorio.');
        }

        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            throw new InvalidArgumentException(
                'El username debe tener entre 3 y 50 caracteres.'
            );
        }

        if (mb_strlen($password) < 8) {
            throw new InvalidArgumentException(
                'La contraseña debe tener al menos 8 caracteres.'
            );
        }

        $this->validateRole($role);
        $this->validateUuid($organizationId);

        if ($this->existsByUsername($username)) {
            throw new RuntimeException('El username ya está registrado.');
        }

        $query = "
            INSERT INTO {$this->tableName} (
                username,
                password,
                role,
                organization_id
            )
            VALUES (
                :username,
                :password,
                :role,
                :organization_id
            )
            RETURNING
                id,
                username,
                role,
                organization_id,
                created_at
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':username' => $username,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':organization_id' => $organizationId,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('No se pudo crear el usuario.');
        }

        return $user;
    }

    /**
     * Actualiza username o role.
     *
     * @return array<string, mixed>
     */
    public function update(
        string $id,
        string $organizationId,
        ?string $username = null,
        ?string $role = null
    ): array {
        $this->validateUuid($id);
        $this->validateUuid($organizationId);

        $fields = [];
        $params = [
            ':id' => $id,
            ':organization_id' => $organizationId,
        ];

        if ($username !== null) {
            $username = trim($username);

            if ($username === '') {
                throw new InvalidArgumentException(
                    'El username no puede estar vacío.'
                );
            }

            if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
                throw new InvalidArgumentException(
                    'El username debe tener entre 3 y 50 caracteres.'
                );
            }

            if ($this->existsByUsername($username, $id)) {
                throw new RuntimeException('El username ya está registrado.');
            }

            $fields[] = 'username = :username';
            $params[':username'] = $username;
        }

        if ($role !== null) {
            $this->validateRole($role);
            $fields[] = 'role = :role';
            $params[':role'] = $role;
        }

        if ($fields === []) {
            throw new InvalidArgumentException(
                'No se proporcionaron campos para actualizar.'
            );
        }

        $query = "
            UPDATE {$this->tableName}
            SET " . implode(', ', $fields) . "
            WHERE id = :id
              AND organization_id = :organization_id
            RETURNING
                id,
                username,
                role,
                organization_id,
                created_at
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        return $user;
    }

    public function updatePassword(
        string $id,
        string $organizationId,
        string $newPassword
    ): bool {
        $this->validateUuid($id);
        $this->validateUuid($organizationId);

        if (mb_strlen($newPassword) < 8) {
            throw new InvalidArgumentException(
                'La contraseña debe tener al menos 8 caracteres.'
            );
        }

        $query = "
            UPDATE {$this->tableName}
            SET password = :password
            WHERE id = :id
              AND organization_id = :organization_id
        ";

        $stmt = $this->conn->prepare($query);

        $stmt->execute([
            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $id,
            ':organization_id' => $organizationId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(string $id, string $organizationId): bool
    {
        $this->validateUuid($id);
        $this->validateUuid($organizationId);

        if ($this->hasDependencies($id)) {
            throw new RuntimeException(
                'El usuario tiene propiedades o leads asociados y no puede eliminarse.'
            );
        }

        $query = "
            DELETE FROM {$this->tableName}
            WHERE id = :id
              AND organization_id = :organization_id
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':id' => $id,
            ':organization_id' => $organizationId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function existsByUsername(
        string $username,
        ?string $excludeId = null
    ): bool {
        $query = "
            SELECT 1
            FROM {$this->tableName}
            WHERE LOWER(username) = LOWER(:username)
        ";

        $params = [
            ':username' => trim($username),
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

    private function hasDependencies(string $id): bool
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
            ':lead_user_id' => $id,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function validateRole(string $role): void
    {
        $allowedRoles = [
            'superadmin',
            'admin',
            'agent',
            'agente',
        ];

        if (!in_array($role, $allowedRoles, true)) {
            throw new InvalidArgumentException(
                'El rol indicado no es válido.'
            );
        }
    }

    private function validateUuid(string $value): void
    {
        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            )
        ) {
            throw new InvalidArgumentException('El identificador no es válido.');
        }
    }
}
