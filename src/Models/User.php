<?php
class User {
    private $conn;
    private $table_name = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function authenticate($username, $password) {
        $query = "SELECT id, username, password, role FROM " . $this->table_name . " WHERE username = :username LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Verificar que la contraseña coincida con el hash seguro
            if (password_verify($password, $row['password'])) {
                return ["id" => $row['id'], "username" => $row['username'], "role" => $row['role']];
            }
        }
        return false;
    }

    public function initDatabase() {
        // 1. Crear tabla de usuarios
        $query = "CREATE TABLE IF NOT EXISTS " . $this->table_name . " (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'agente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->conn->exec($query);

        // 2. Crear tabla properties 
        $this->conn->exec("CREATE TABLE IF NOT EXISTS properties (
            id SERIAL PRIMARY KEY,
            reference_id VARCHAR(50) UNIQUE NOT NULL,
            title VARCHAR(100) NOT NULL,
            price DECIMAL(15,2) NOT NULL,
            operation_type VARCHAR(20),
            property_type VARCHAR(50),
            status VARCHAR(20) DEFAULT 'disponible',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 3. Crear tabla leads 
        $this->conn->exec("CREATE TABLE IF NOT EXISTS leads (
            id SERIAL PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            contact_info JSONB NOT NULL,
            property_interest_id INT REFERENCES properties(id) ON DELETE SET NULL,
            pipeline_stage VARCHAR(50) DEFAULT 'nuevo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 4. Inyectar el Super Administrador si no existe
        $check = $this->conn->query("SELECT id FROM users WHERE username = 'admin'");
        if ($check->rowCount() == 0) {
            $insert = "INSERT INTO users (username, password, role) VALUES (:user, :pass, 'superadmin')";
            $stmt = $this->conn->prepare($insert);
            // Encriptamos la clave antes de guardarla
            $hash = password_hash('QAOpsAdmin2026', PASSWORD_BCRYPT);
            $stmt->execute([':user' => 'admin', ':pass' => $hash]);
            return "Tablas de DB creadas y Super Admin generado con éxito.";
        }
        return "Tablas verificadas. El Super Admin ya existía.";
    }
}
?>
