<?php

class CountriesNowProvider
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = "https://countriesnow.space/api/v0.1";
    }

    public function getCountries(): array
    {
        $response = $this->request(
            "GET",
            "/countries/iso"
        );

        $countries = $response["data"] ?? [];

        $result = [];

        foreach ($countries as $country) {
            $name = $country["name"] ?? null;
            $iso2 = $country["Iso2"] ?? null;
            $iso3 = $country["Iso3"] ?? null;

            if (!$name || !$iso2) {
                continue;
            }

            $result[] = [
                "name" => $name,
                "code" => strtoupper($iso2),
                "code3" => $iso3
                    ? strtoupper($iso3)
                    : null,
            ];
        }

        usort(
            $result,
            static fn (array $first, array $second) =>
                strcasecmp(
                    $first["name"],
                    $second["name"]
                )
        );

        return $result;
    }

    public function getStates(string $country): array
    {
        $response = $this->request(
            "POST",
            "/countries/states",
            [
                "country" => $country,
            ]
        );

        $states = $response["data"]["states"] ?? [];

        $result = [];

        foreach ($states as $state) {
            $name = $state["name"] ?? null;

            if (!$name) {
                continue;
            }

            $result[] = [
                "name" => $name,
                "code" => $state["state_code"] ?? null,
            ];
        }

        usort(
            $result,
            static fn (array $first, array $second) =>
                strcasecmp(
                    $first["name"],
                    $second["name"]
                )
        );

        return $result;
    }

    public function getCities(
        string $country,
        ?string $state = null
    ): array {
        if ($state) {
            $response = $this->request(
                "POST",
                "/countries/state/cities",
                [
                    "country" => $country,
                    "state" => $state,
                ]
            );
        } else {
            $response = $this->request(
                "POST",
                "/countries/cities",
                [
                    "country" => $country,
                ]
            );
        }

        $cities = $response["data"] ?? [];

        if (!is_array($cities)) {
            return [];
        }

        $cities = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn ($city) =>
                            is_string($city)
                                ? trim($city)
                                : null,
                        $cities
                    )
                )
            )
        );

        natcasesort($cities);

        return array_values($cities);
    }

    private function request(
        string $method,
        string $endpoint,
        ?array $payload = null
    ): array {
        $url = $this->baseUrl . $endpoint;

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                "No se pudo iniciar la conexión con el proveedor geográfico."
            );
        }

        $headers = [
            "Accept: application/json",
        ];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if (strtoupper($method) === "POST") {
            $encodedPayload = json_encode(
                $payload ?? [],
                JSON_UNESCAPED_UNICODE
            );

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedPayload,
                CURLOPT_HTTPHEADER => [
                    ...$headers,
                    "Content-Type: application/json",
                ],
            ]);
        }

        $responseBody = curl_exec($curl);

        if ($responseBody === false) {
            $message = curl_error($curl);
            curl_close($curl);

            throw new RuntimeException(
                "Error consultando el proveedor geográfico: "
                . $message
            );
        }

        $httpCode = curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        curl_close($curl);

        $response = json_decode(
            $responseBody,
            true
        );

        if (
            $httpCode < 200 ||
            $httpCode >= 300 ||
            !is_array($response)
        ) {
            throw new RuntimeException(
                "El proveedor geográfico devolvió una respuesta no válida."
            );
        }

        if (($response["error"] ?? false) === true) {
            throw new RuntimeException(
                $response["msg"]
                    ?? "El proveedor geográfico rechazó la solicitud."
            );
        }

        return $response;
    }
}
