<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'src/Models/Property.php';
require_once 'src/Models/Lead.php';
require_once 'src/Auth/JwtHandler.php';

$host = getenv('DB_HOST') ?: 'reygom-db-service';
$db   = getenv('POSTGRES_DB') ?: 'reygom_core';
$user = getenv('POSTGRES_USER') ?: 'reygom_admin';
$pass = getenv('POSTGRES_PASSWORD') ?: 'CambiaEstaClavePorUnaSegura123';

try {
    $dsn = "pgsql:host=$host;port=5432;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error de conexión a la base de datos."]);
    exit();
}

// Función middleware para proteger rutas
function authenticate() {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '');
    
    if (!$authHeader) {
        http_response_code(401);
        echo json_encode(["message" => "Acceso denegado. Token no proporcionado."]);
        exit();
    }
    
    $token = str_replace('Bearer ', '', $authHeader);
    $jwt = new JwtHandler();
    $payload = $jwt->decode($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(["message" => "Acceso denegado. Token inválido o manipulado."]);
        exit();
    }
    return $payload;
}

$request_uri = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
$method = $_SERVER['REQUEST_METHOD'];

switch ($request_uri) {
    case '/login':
        if ($method == 'POST') {
            $data = json_decode(file_get_contents("php://input"));
            // Aquí en el futuro validarías contra una tabla de usuarios. 
            // Por ahora usamos un usuario administrador estático para asegurar la API.
            if (isset($data->username) && isset($data->password) && $data->username == 'admin' && $data->password == 'admin123') {
                $jwt = new JwtHandler();
                $token = $jwt->encode(["user" => "admin", "role" => "superadmin"]);
                echo json_encode(["message" => "Login exitoso", "token" => $token]);
            } else {
                http_response_code(401);
                echo json_encode(["message" => "Credenciales incorrectas."]);
            }
        }
        break;

    case '/properties':
        authenticate(); // ¡Candado activado!
        $property = new Property($pdo);
        if ($method == 'GET') {
            $stmt = $property->readAll();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($method == 'POST') {
            $data = json_decode(file_get_contents("php://input"));
            if (!empty($data->title) && !empty($data->price)) {
                $ref = !empty($data->reference_id) ? $data->reference_id : "REF-" . rand(1000, 9999);
                if ($property->create($ref, $data->title, $data->price, $data->operation_type, $data->property_type)) {
                    http_response_code(201);
                    echo json_encode(["message" => "Inmueble creado exitosamente."]);
                } else {
                    http_response_code(503);
                    echo json_encode(["message" => "No se pudo crear el inmueble."]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["message" => "Datos incompletos."]);
            }
        }
        break;

    case '/leads':
        authenticate(); // ¡Candado activado!
        $lead = new Lead($pdo);
        if ($method == 'GET') {
            $stmt = $lead->readAll();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($method == 'POST') {
            $data = json_decode(file_get_contents("php://input"));
            if (!empty($data->full_name) && !empty($data->contact_info)) {
                $property_id = !empty($data->property_interest_id) ? $data->property_interest_id : null;
                if ($lead->create($data->full_name, $data->contact_info, $property_id)) {
                    http_response_code(201);
                    echo json_encode(["message" => "Cliente registrado."]);
                } else {
                    http_response_code(503);
                    echo json_encode(["message" => "Error al registrar cliente."]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["message" => "Datos incompletos."]);
            }
        }
        break;

    case '/':
        echo json_encode(["status" => "success", "message" => "ReyGom Core API conectada y protegida."]);
        break;

    default:
        http_response_code(404);
        echo json_encode(["message" => "Endpoint no encontrado"]);
        break;
}
?>
