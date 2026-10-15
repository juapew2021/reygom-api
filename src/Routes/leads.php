<?php

function handleLeadRoutes($request_uri, $method, $pdo) {

    $lead_id = null;

    if (preg_match('#^/leads/([a-f0-9-]+)$#', $request_uri, $matches)) {
        $lead_id = $matches[1];
        $request_uri = '/leads/{id}';
    }

    if ($request_uri !== '/leads' && $request_uri !== '/leads/{id}') {
        return false;
    }

    // Validar JWT
    $currentUser = JwtMiddleware::authenticate();

    $lead = new Lead($pdo);
    if ($lead_id && !$lead->readOne($lead_id)) { http_response_code(404); echo json_encode(['message'=>'Lead no encontrado']); return true; }
    if (in_array($method,['POST','PUT'],true)) {
        $input=json_decode(file_get_contents('php://input'),true);
        if (!empty($input['property_interest_id'])) {
            $check=$pdo->prepare('SELECT id FROM properties WHERE id=?'); $check->execute([$input['property_interest_id']]);
            if (!$check->fetchColumn()) { http_response_code(422); echo json_encode(['message'=>'El inmueble no pertenece a esta oficina.']); return true; }
        }
    }

    if ($request_uri === '/leads') {

        if ($method === 'GET') {
            $stmt = $lead->readAll();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            return true;
        }

        if ($method === 'POST') {
            $data = json_decode(file_get_contents("php://input"));

            if (empty($data->full_name)) {
                http_response_code(400);
                echo json_encode(["message" => "El nombre del lead es obligatorio"]);
                return true;
            }

            $contact_info = $data->contact_info ?? new stdClass();
            $property_interest_id = $data->property_interest_id ?? null;
            $pipeline_stage = $data->pipeline_stage ?? 'Nuevo';

            $created = $lead->create(
                $data->full_name,
                $contact_info,
                $property_interest_id,
                $pipeline_stage
            );

            if ($created) {
                http_response_code(201);
                echo json_encode([
                    "message" => "Lead creado",
                    "id" => $created["id"] ?? null
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "No se pudo crear el lead"]);
            }

            return true;
        }

        http_response_code(405);
        echo json_encode(["message" => "Método no permitido"]);
        return true;
    }

    if ($request_uri === '/leads/{id}') {

        if ($method === 'GET') {
            $item = $lead->readOne($lead_id);

            if (!$item) {
                http_response_code(404);
                echo json_encode(["message" => "Lead no encontrado"]);
                return true;
            }

            echo json_encode($item);
            return true;
        }

        if ($method === 'PUT') {
            $data = json_decode(file_get_contents("php://input"));

            if (empty($data->full_name)) {
                http_response_code(400);
                echo json_encode(["message" => "El nombre del lead es obligatorio"]);
                return true;
            }

            $contact_info = $data->contact_info ?? new stdClass();
            $property_interest_id = $data->property_interest_id ?? null;
            $pipeline_stage = $data->pipeline_stage ?? 'Nuevo';

            $updated = $lead->update(
                $lead_id,
                $data->full_name,
                $contact_info,
                $property_interest_id,
                $pipeline_stage
            );

            echo json_encode(["success" => $updated]);
            return true;
        }

        if ($method === 'DELETE') {
            $deleted = $lead->delete($lead_id);
            echo json_encode(["success" => $deleted]);
            return true;
        }

        http_response_code(405);
        echo json_encode(["message" => "Método no permitido"]);
        return true;
    }

    return false;
}
