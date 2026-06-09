<?php
class JwtHandler {
    private $secret;

    public function __construct() {
        // En producción, esto debe venir de una variable de entorno
        $this->secret = getenv('JWT_SECRET') ?: 'ClaveSuperSeguraReyGom2026';
    }

    public function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public function decode($jwt) {
        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) != 3) return false;
        
        $header = base64_decode($tokenParts[0]);
        $payload = json_decode(base64_decode($tokenParts[1]));
        $signature_provided = $tokenParts[2];
        
        $base64UrlHeader = $tokenParts[0];
        $base64UrlPayload = $tokenParts[1];
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        if ($base64UrlSignature === $signature_provided) {
            return $payload;
        }
        return false;
    }
}
?>
