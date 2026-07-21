<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

/*
|--------------------------------------------------------------------------
| Configuración
|--------------------------------------------------------------------------
*/

require_once "src/Config/database.php";

/*
|--------------------------------------------------------------------------
| Autenticación y helpers
|--------------------------------------------------------------------------
*/

require_once "src/Auth/JwtHandler.php";
require_once "src/Helpers/auth.php";

/*
|--------------------------------------------------------------------------
| Modelos
|--------------------------------------------------------------------------
*/

require_once "src/Models/User.php";
require_once "src/Models/Lead.php";
require_once "src/Models/Property.php";
require_once "src/Models/PropertyImage.php";

/*
|--------------------------------------------------------------------------
| Servicios
|--------------------------------------------------------------------------
*/

require_once "src/Services/PropertyReferenceGenerator.php";
require_once "src/Services/PropertyImageService.php";

/*
|--------------------------------------------------------------------------
| Rutas
|--------------------------------------------------------------------------
*/

require_once "src/Routes/auth.php";
require_once "src/Routes/dashboard.php";
require_once "src/Routes/leads.php";
require_once "src/Routes/properties.php";
require_once "src/Routes/property_images.php";
require_once "src/Routes/property_uploads.php";
require_once "src/Routes/public.php";
require_once "src/Routes/media.php";
require_once "src/Routes/property_image_order.php";

/*
|--------------------------------------------------------------------------
| Inicialización
|--------------------------------------------------------------------------
*/

$pdo = getDatabaseConnection();

$request_uri = explode("?", $_SERVER["REQUEST_URI"], 2)[0];
$method = $_SERVER["REQUEST_METHOD"];

/*
|--------------------------------------------------------------------------
| Router principal
|--------------------------------------------------------------------------
| La ruta de medios debe ejecutarse primero porque es pública.
|--------------------------------------------------------------------------
*/

if (handleMediaRoutes($request_uri, $method)) {
    exit();
}

if (handleAuthRoutes($request_uri, $method, $pdo)) {
    exit();
}

if (handleDashboardRoutes($request_uri, $method, $pdo)) {
    exit();
}

if (handlePropertyUploadRoutes($request_uri, $method, $pdo)) {
    exit();
}

if (
    handlePropertyImageOrderRoutes(
        $request_uri,
        $method,
        $pdo
    )
) {
    exit();
}

if (handlePropertyImageRoutes($request_uri, $method, $pdo)) {
    exit();
}

if (handlePropertyRoutes($request_uri, $method, $pdo)) {
    exit();
}

if (handleLeadRoutes($request_uri, $method, $pdo)) {
    exit();
}

if (handlePublicRoutes($request_uri, $method, $pdo)) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
*/

if ($request_uri === "/") {
    echo json_encode([
        "status" => "success",
        "message" => "ReyGom Core API activa"
    ]);

    exit();
}

/*
|--------------------------------------------------------------------------
| Endpoint no encontrado
|--------------------------------------------------------------------------
*/

http_response_code(404);

echo json_encode([
    "message" => "Endpoint no encontrado"
]);