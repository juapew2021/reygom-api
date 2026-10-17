<?php

declare(strict_types=1);

function handlePropertyRoutes(
    string $request_uri,
    string $method,
    PDO $pdo
): bool {
    $propertyId = null;

    /*
     |--------------------------------------------------------------------------
     | Identificar rutas con UUID
     |--------------------------------------------------------------------------
     */

    if (
        preg_match(
            '#^/properties/([a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$#i',
            $request_uri,
            $matches
        )
    ) {
        $propertyId = $matches[1];
        $request_uri = '/properties/{id}';
    }

    if (
        $request_uri !== '/properties'
        && $request_uri !== '/properties/{id}'
    ) {
        return false;
    }

    /*
     |--------------------------------------------------------------------------
     | Usuario autenticado
     |--------------------------------------------------------------------------
     */

   // Validar JWT
$currentUser = JwtMiddleware::authenticate();

$userId = (string) $currentUser['id'];
$userRole = strtolower((string) $currentUser['role']);
$organizationId = (string) $currentUser['organization_id'];

    $isAdmin = in_array(
        $userRole,
        ['admin', 'superadmin', 'agent', 'agente'],
        true
    );

    $property = new Property($pdo);

    /*
     |--------------------------------------------------------------------------
     | GET /properties
     |--------------------------------------------------------------------------
     */

    if ($request_uri === '/properties' && $method === 'GET') {
        try {
            $properties = $isAdmin
                ? $property->readAll()
                : $property->readAllByAgent($userId);

            echo json_encode($properties);

            return true;
        } catch (PDOException $exception) {
            error_log(
                'Error consultando inmuebles: '
                . $exception->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'message' => 'Ocurrió un error interno al consultar los inmuebles.',
            ]);

            return true;
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);

            echo json_encode([
                'message' => $exception->getMessage(),
            ]);

            return true;
        }
    }

    /*
     |--------------------------------------------------------------------------
     | POST /properties
     |--------------------------------------------------------------------------
     */

    if ($request_uri === '/properties' && $method === 'POST') {
        $data = json_decode(
            file_get_contents('php://input')
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);

            echo json_encode([
                'message' => 'El cuerpo de la petición no contiene un JSON válido.',
            ]);

            return true;
        }

        if (
            !is_object($data)
            || empty($data->title)
            || !isset($data->price)
            || $data->price === ''
        ) {
            http_response_code(400);

            echo json_encode([
                'message' => 'Título y precio son obligatorios.',
            ]);

            return true;
        }

        /*
         * Los agentes nunca pueden elegir otro agent_id.
         *
         * Los administradores pueden asignar la propiedad a otro agente.
         * Si no envían agent_id, queda asignada al administrador actual.
         */

        $agentId = $userId;

        if (
            in_array($userRole, ['admin','superadmin'], true)
            && !empty($data->agent_id)
        ) {
            $agentId = trim((string) $data->agent_id);
        }

        /*
         |--------------------------------------------------------------------------
         | Referencia ReyGom
         |--------------------------------------------------------------------------
         */

        $referenceId = !empty($data->reference_id)
            ? strtoupper(trim((string) $data->reference_id))
            : null;

        if ($referenceId === null) {
            if (
                empty($data->city)
                || empty($data->province)
            ) {
                http_response_code(400);

                echo json_encode([
                    'message' =>
                        'La ciudad y la provincia son obligatorias para generar la referencia ReyGom.',
                ]);

                return true;
            }

            try {
                $referenceGenerator = new PropertyReferenceGenerator($pdo);

                $referenceId = $referenceGenerator->generate(
                    trim((string) $data->city),
                    trim((string) $data->province),
                    !empty($data->country_code)
                        ? strtoupper(trim((string) $data->country_code))
                        : 'ES'
                );
            } catch (PDOException $exception) {
                error_log(
                    'Error generando referencia del inmueble: '
                    . $exception->getMessage()
                );

                http_response_code(500);

                echo json_encode([
                    'message' => 'No se pudo generar la referencia del inmueble.',
                ]);

                return true;
            }
        }

        try {
            $created = $property->create(
                [
                    'reference_id' => $referenceId,
                    'title' => trim((string) $data->title),
                    'description' => $data->description ?? null,
                    'price' => $data->price,
                    'operation_type' =>
                        $data->operation_type ?? 'Venta',
                    'property_type' =>
                        $data->property_type ?? 'Piso',
                    'status' =>
                        $data->status ?? 'Disponible',
                    'features' =>
                        $data->features ?? null,
                    'image_url' =>
                        $data->image_url ?? null,
                    'cover_image' =>
                        $data->cover_image ?? null,
                    'city' =>
                        $data->city ?? null,
                    'province' =>
                        $data->province ?? null,
                    'bedrooms' => isset($data->bedrooms)
                        ? (int) $data->bedrooms
                        : null,
                    'bathrooms' => isset($data->bathrooms)
                        ? (int) $data->bathrooms
                        : null,
                    'built_area' =>
                        $data->built_area ?? null,
                    'country' =>
                        $data->country ?? null,
                    'country_code' =>
                        $data->country_code ?? null,
                    'state' =>
                        $data->state ?? null,
                    'state_code' =>
                        $data->state_code ?? null,
                    'postal_code' =>
                        $data->postal_code ?? null,
                    'address' =>
                        $data->address ?? null,
                    'neighborhood' =>
                        $data->neighborhood ?? null,
                    'latitude' =>
                        $data->latitude ?? null,
                    'longitude' =>
                        $data->longitude ?? null,
                ],
                $agentId
            );

            if ($created === false) {
                http_response_code(500);

                echo json_encode([
                    'message' => 'No se pudo crear el inmueble.',
                ]);

                return true;
            }

            http_response_code(201);

            echo json_encode([
                'message' => 'Inmueble creado correctamente.',
                'id' => $created['id'] ?? null,
                'agent_id' => $created['agent_id'] ?? $agentId,
            ]);

            return true;
        } catch (PDOException $exception) {
            $sqlState = (string) $exception->getCode();

            if ($sqlState === '23503') {
                http_response_code(400);

                echo json_encode([
                    'message' => 'El agente indicado no existe.',
                ]);

                return true;
            }

            if ($sqlState === '23505') {
                http_response_code(409);

                echo json_encode([
                    'message' => 'La referencia del inmueble ya existe.',
                ]);

                return true;
            }

            error_log(
                'Error creando inmueble: '
                . $exception->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'message' => 'Ocurrió un error interno al crear el inmueble.',
            ]);

            return true;
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);

            echo json_encode([
                'message' => $exception->getMessage(),
            ]);

            return true;
        } catch (RuntimeException $exception) {
            http_response_code(409);

            echo json_encode([
                'message' => $exception->getMessage(),
            ]);

            return true;
        }
    }

    /*
     |--------------------------------------------------------------------------
     | Operaciones sobre /properties/{id}
     |--------------------------------------------------------------------------
     */

    if ($request_uri === '/properties/{id}') {
        if ($propertyId === null) {
            http_response_code(400);

            echo json_encode([
                'message' => 'El identificador del inmueble no es válido.',
            ]);

            return true;
        }

        try {
            /*
             * Los agentes únicamente pueden consultar sus propiedades.
             * Una propiedad ajena se responde como no encontrada.
             */

            $existingProperty = $isAdmin
                ? $property->readOne($propertyId)
                : $property->readOneByAgent(
                    $propertyId,
                    $userId
                );
        } catch (PDOException $exception) {
            error_log(
                'Error consultando inmueble: '
                . $exception->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'message' => 'Ocurrió un error interno al consultar el inmueble.',
            ]);

            return true;
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);

            echo json_encode([
                'message' => $exception->getMessage(),
            ]);

            return true;
        }

        if ($existingProperty === null) {
            http_response_code(404);

            echo json_encode([
                'message' => 'Inmueble no encontrado.',
            ]);

            return true;
        }

        /*
         |--------------------------------------------------------------------------
         | GET /properties/{id}
         |--------------------------------------------------------------------------
         */

        if ($method === 'GET') {
            echo json_encode($existingProperty);

            return true;
        }

        /*
         |--------------------------------------------------------------------------
         | PUT /properties/{id}
         |--------------------------------------------------------------------------
         */

        if ($method === 'PUT') {
            $data = json_decode(
                file_get_contents('php://input')
            );

            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);

                echo json_encode([
                    'message' => 'El cuerpo de la petición no contiene un JSON válido.',
                ]);

                return true;
            }

            if (
                !is_object($data)
                || empty($data->title)
                || !isset($data->price)
                || $data->price === ''
            ) {
                http_response_code(400);

                echo json_encode([
                    'message' => 'Título y precio son obligatorios.',
                ]);

                return true;
            }

            /*
             * agent_id no se modifica desde esta ruta.
             * La propiedad conserva el propietario actual.
             */

            $referenceId = !empty($data->reference_id)
                ? strtoupper(trim((string) $data->reference_id))
                : $existingProperty['reference_id'];

            if ($referenceId !== $existingProperty['reference_id']) {
                http_response_code(422);
                echo json_encode(['message'=>'La referencia del inmueble es permanente.']);
                return true;
            }

            try {
                $updated = $property->update(
                    $propertyId,
                    [
                        'reference_id' => $referenceId,
                        'title' => trim((string) $data->title),
                        'description' =>
                            $data->description ?? null,
                        'price' =>
                            $data->price,
                        'operation_type' =>
                            $data->operation_type ?? 'Venta',
                        'property_type' =>
                            $data->property_type ?? 'Piso',
                        'status' =>
                            $data->status ?? 'Disponible',
                        'features' =>
                            $data->features ?? null,
                        'image_url' =>
                            $data->image_url ?? null,
                        'cover_image' =>
                            $data->cover_image ?? null,
                        'city' =>
                            $data->city ?? null,
                        'province' =>
                            $data->province ?? null,
                        'bedrooms' => isset($data->bedrooms)
                            ? (int) $data->bedrooms
                            : null,
                        'bathrooms' => isset($data->bathrooms)
                            ? (int) $data->bathrooms
                            : null,
                        'built_area' =>
                            $data->built_area ?? null,
                        'country' =>
                            $data->country ?? null,
                        'country_code' =>
                            $data->country_code ?? null,
                        'state' =>
                            $data->state ?? null,
                        'state_code' =>
                            $data->state_code ?? null,
                        'postal_code' =>
                            $data->postal_code ?? null,
                        'address' =>
                            $data->address ?? null,
                        'neighborhood' =>
                            $data->neighborhood ?? null,
                        'latitude' =>
                            $data->latitude ?? null,
                        'longitude' =>
                            $data->longitude ?? null,
                    ],
                    $userId,
                    $isAdmin
                );

                if (!$updated) {
                    http_response_code(404);

                    echo json_encode([
                        'message' =>
                            'No se encontró el inmueble o no tienes permiso para modificarlo.',
                    ]);

                    return true;
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Inmueble actualizado correctamente.',
                ]);

                return true;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23505') {
                    http_response_code(409);

                    echo json_encode([
                        'message' => 'La referencia del inmueble ya existe.',
                    ]);

                    return true;
                }

                if ((string) $exception->getCode() === '23503') {
                    http_response_code(400);

                    echo json_encode([
                        'message' => 'El agente indicado no existe.',
                    ]);

                    return true;
                }

                error_log(
                    'Error actualizando inmueble: '
                    . $exception->getMessage()
                );

                http_response_code(500);

                echo json_encode([
                    'message' =>
                        'Ocurrió un error interno al actualizar el inmueble.',
                ]);

                return true;
            } catch (InvalidArgumentException $exception) {
                http_response_code(400);

                echo json_encode([
                    'message' => $exception->getMessage(),
                ]);

                return true;
            } catch (RuntimeException $exception) {
                http_response_code(409);

                echo json_encode([
                    'message' => $exception->getMessage(),
                ]);

                return true;
            }
        }

        /*
         |--------------------------------------------------------------------------
         | DELETE /properties/{id}
         |--------------------------------------------------------------------------
         */

        if ($method === 'DELETE') {
            try {
                $deleted = $property->delete(
                    $propertyId,
                    $userId,
                    $isAdmin
                );

                if (!$deleted) {
                    http_response_code(404);

                    echo json_encode([
                        'message' =>
                            'No se encontró el inmueble o no tienes permiso para eliminarlo.',
                    ]);

                    return true;
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Inmueble eliminado correctamente.',
                ]);

                return true;
            } catch (PDOException $exception) {
                error_log(
                    'Error eliminando inmueble: '
                    . $exception->getMessage()
                );

                http_response_code(500);

                echo json_encode([
                    'message' =>
                        'Ocurrió un error interno al eliminar el inmueble.',
                ]);

                return true;
            } catch (InvalidArgumentException $exception) {
                http_response_code(400);

                echo json_encode([
                    'message' => $exception->getMessage(),
                ]);

                return true;
            }
        }

        http_response_code(405);

        echo json_encode([
            'message' => 'Método no permitido.',
        ]);

        return true;
    }

    http_response_code(405);

    echo json_encode([
        'message' => 'Método no permitido.',
    ]);

    return true;
}
