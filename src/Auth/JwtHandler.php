<?php

declare(strict_types=1);

class JwtHandler
{
    private string $secret;

    public function __construct()
    {
        $secret = getenv('JWT_SECRET');

        if ($secret === false || trim($secret) === '') {
            throw new RuntimeException(
                'La variable de entorno JWT_SECRET no está configurada.'
            );
        }

        $this->secret = $secret;
    }

    public function encode(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $base64UrlHeader = $this->base64UrlEncode(
            json_encode($header, JSON_THROW_ON_ERROR)
        );

        $base64UrlPayload = $this->base64UrlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $signature = hash_hmac(
            'sha256',
            $base64UrlHeader . '.' . $base64UrlPayload,
            $this->secret,
            true
        );

        return $base64UrlHeader
            . '.'
            . $base64UrlPayload
            . '.'
            . $this->base64UrlEncode($signature);
    }

    public function decode(string $jwt): array|false
    {
        $tokenParts = explode('.', $jwt);

        if (count($tokenParts) !== 3) {
            return false;
        }

        [$base64UrlHeader, $base64UrlPayload, $providedSignature] = $tokenParts;

        $header = json_decode(
            $this->base64UrlDecode($base64UrlHeader),
            true
        );

        if (
            !is_array($header)
            || ($header['alg'] ?? null) !== 'HS256'
            || ($header['typ'] ?? null) !== 'JWT'
        ) {
            return false;
        }

      $payload = json_decode(
    $this->base64UrlDecode($base64UrlPayload),
    true
);

if (!is_array($payload)) {
    return false;
}

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac(
                'sha256',
                $base64UrlHeader . '.' . $base64UrlPayload,
                $this->secret,
                true
            )
        );

        if (!hash_equals($expectedSignature, $providedSignature)) {
            return false;
        };
        if (
    !isset($payload['exp']) ||
    !is_numeric($payload['exp']) ||
    (int)$payload['exp'] < time()
) {
    return false;
}

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/'),
            true
        );

        return $decoded === false ? '' : $decoded;
    }
}
