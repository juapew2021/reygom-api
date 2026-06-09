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

$request_uri = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
$method = $_SERVER['REQUEST_METHOD'];

switch ($request_uri) {
    case '/properties':
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
                    echo json_encode(["message" => "Cliente registrado exitosamente."]);
                } else {
                    http_response_code(503);
                    echo json_encode(["message" => "No se pudo registrar el cliente."]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["message" => "Datos incompletos. Se requiere nombre e información de contacto."]);
            }
        }
        break;

    case '/':
        echo json_encode(["status" => "success", "message" => "ReyGom Core API conectada."]);
        break;

    default:
        http_response_code(404);
        echo json_encode(["message" => "Endpoint no encontrado"]);
        break;
}
?>
