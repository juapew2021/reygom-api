<?php

function handleDashboardRoutes($request_uri, $method, $pdo)
{
    if ($request_uri !== "/dashboard") {
        return false;
    }

    authenticate();

    if ($method !== "GET") {
        http_response_code(405);

        echo json_encode([
            "message" => "Método no permitido"
        ]);

        return true;
    }

    /*
     |--------------------------------------------------------------------------
     | KPIs
     |--------------------------------------------------------------------------
     */

    $propertiesCount = $pdo
        ->query("SELECT COUNT(*) FROM properties")
        ->fetchColumn();

    $leadsCount = $pdo
        ->query("SELECT COUNT(*) FROM leads")
        ->fetchColumn();

    /*
     |--------------------------------------------------------------------------
     | Pipeline
     |--------------------------------------------------------------------------
     */

    $pipelineStmt = $pdo->query("
        SELECT pipeline_stage,
               COUNT(*) AS total
        FROM leads
        GROUP BY pipeline_stage
        ORDER BY pipeline_stage
    ");

    $pipeline = [];

    while ($row = $pipelineStmt->fetch(PDO::FETCH_ASSOC)) {
        $pipeline[$row["pipeline_stage"]] = (int) $row["total"];
    }

    /*
     |--------------------------------------------------------------------------
     | Últimos Leads
     |--------------------------------------------------------------------------
     */

    $latestLeadsStmt = $pdo->query("
        SELECT
            id,
            full_name,
            pipeline_stage,
            created_at
        FROM leads
        ORDER BY created_at DESC
        LIMIT 5
    ");

    /*
     |--------------------------------------------------------------------------
     | Últimos Inmuebles
     |--------------------------------------------------------------------------
     */

    $latestPropertiesStmt = $pdo->query("
        SELECT
            id,
            reference_id,
            title,
            price,
            status,
            created_at
        FROM properties
        ORDER BY created_at DESC
        LIMIT 5
    ");

    /*
     |--------------------------------------------------------------------------
     | Respuesta
     |--------------------------------------------------------------------------
     */

    echo json_encode([

        "properties_count" => (int) $propertiesCount,

        "leads_count" => (int) $leadsCount,

        // Próximos módulos
        "visits_today" => 0,
        "sales_count" => 0,

        "pipeline" => $pipeline,

        "latest_leads" => $latestLeadsStmt->fetchAll(PDO::FETCH_ASSOC),

        "latest_properties" => $latestPropertiesStmt->fetchAll(PDO::FETCH_ASSOC)

    ]);

    return true;
}