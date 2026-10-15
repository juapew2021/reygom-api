<?php

$origin=$_SERVER['HTTP_ORIGIN']??'';
$allowed=['https://crm-dev.reygom.com','https://crm.reygom.com','https://reygom.com','https://www.reygom.com'];
header('Vary: Origin');
if(in_array($origin,$allowed,true)) {
 header('Access-Control-Allow-Origin: '.$origin);
 header('Access-Control-Allow-Credentials: true');
} elseif($origin!=='') {
 $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
 if(!str_starts_with($path,'/public')) {http_response_code(403);header('Content-Type: application/json');echo json_encode(['message'=>'Origen no autorizado.']);exit;}
 header('Access-Control-Allow-Origin: *');
}
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Office-Id");

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
require_once "src/Services/TenantAccess.php";
require_once "src/Services/AuditTrail.php";
require_once "src/Services/AccessGuard.php";
require_once "src/Routes/access.php";
require_once "src/Routes/history.php";
require_once "src/Routes/offices.php";
require_once "src/Middleware/JwtMiddleware.php";
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

require_once "src/Services/PropertySubmissionService.php";
require_once "src/Routes/submissions.php";
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

if (handleAccessRoutes($request_uri,$method,$pdo)) { exit(); }

if (handleHistoryRoutes($request_uri, $method, $pdo)) { exit(); }

if (handleOfficeRoutes($request_uri, $method, $pdo)) { exit(); }

if (handleSubmissionRoutes($request_uri, $method, $pdo)) { exit(); }

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