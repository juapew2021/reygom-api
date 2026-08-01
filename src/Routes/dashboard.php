<?php

declare(strict_types=1);

function handleDashboardRoutes(
    string $request_uri,
    string $method,
    PDO $pdo
): bool {
    if ($request_uri !== "/dashboard") {
        return false;
    }

    if ($method !== "GET") {
        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido."
        ]);

        return true;
    }

    /*
     |--------------------------------------------------------------------------
     | Autenticación
     |--------------------------------------------------------------------------
     */

    $currentUser = JwtMiddleware::authenticate();

    $organizationId = $currentUser["organization_id"] ?? null;

    if (
        !is_string($organizationId) ||
        trim($organizationId) === ""
    ) {
        http_response_code(401);

        echo json_encode([
            "message" => "El token no contiene una organización válida."
        ]);

        return true;
    }

    /*
     |--------------------------------------------------------------------------
     | KPIs
     |--------------------------------------------------------------------------
     */

    $propertiesCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM properties
        WHERE organization_id = :organization_id
    ");

    $propertiesCountStmt->execute([
        "organization_id" => $organizationId
    ]);

    $propertiesCount = $propertiesCountStmt->fetchColumn();

    $leadsCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM leads
        WHERE organization_id = :organization_id
    ");

    $leadsCountStmt->execute([
        "organization_id" => $organizationId
    ]);

    $leadsCount = $leadsCountStmt->fetchColumn();

    /*
     |--------------------------------------------------------------------------
     | Pipeline
     |--------------------------------------------------------------------------
     */

    $pipelineStmt = $pdo->prepare("
        SELECT
            pipeline_stage,
            COUNT(*) AS total
        FROM leads
        WHERE organization_id = :organization_id
        GROUP BY pipeline_stage
        ORDER BY pipeline_stage
    ");

    $pipelineStmt->execute([
        "organization_id" => $organizationId
    ]);

    $pipeline = [];

    while ($row = $pipelineStmt->fetch(PDO::FETCH_ASSOC)) {
        $pipeline[$row["pipeline_stage"]] = (int) $row["total"];
    }

    /*
     |--------------------------------------------------------------------------
     | Últimos leads
     |--------------------------------------------------------------------------
     */

    $latestLeadsStmt = $pdo->prepare("
        SELECT
            id,
            full_name,
            pipeline_stage,
            created_at
        FROM leads
        WHERE organization_id = :organization_id
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $latestLeadsStmt->execute([
        "organization_id" => $organizationId
    ]);

    /*
     |--------------------------------------------------------------------------
     | Últimos inmuebles
     |--------------------------------------------------------------------------
     */

    $latestPropertiesStmt = $pdo->prepare("
        SELECT
            id,
            reference_id,
            title,
            price,
            status,
            created_at
        FROM properties
        WHERE organization_id = :organization_id
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $latestPropertiesStmt->execute([
        "organization_id" => $organizationId
    ]);

    /*
     |--------------------------------------------------------------------------
     | Respuesta
     |--------------------------------------------------------------------------
     */

    echo json_encode([
        "properties_count" => (int) $propertiesCount,
        "leads_count" => (int) $leadsCount,

        "visits_today" => 0,
        "sales_count" => 0,

        "pipeline" => $pipeline,

        "latest_leads" => $latestLeadsStmt->fetchAll(
            PDO::FETCH_ASSOC
        ),

        "latest_properties" => $latestPropertiesStmt->fetchAll(
            PDO::FETCH_ASSOC
        ),

        "authenticated_user" => [
            "id" => $currentUser["id"] ?? null,
            "username" => $currentUser["username"]
                ?? $currentUser["user"]
                ?? null,
            "role" => $currentUser["role"] ?? null,
            "organization_id" => $organizationId
        ]
    ], JSON_UNESCAPED_UNICODE);

    return true;
}