<?php

class PropertyReferenceGenerator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function generate(
        string $city,
        string $province,
        string $countryCode = 'ES'
    ): string {
        $countryCode = $this->normalizeCode($countryCode, 2);
        $provinceCode = $this->normalizeCode($province, 3);
        $cityCode = $this->normalizeCode($city, 3);

        $sequence = $this->pdo
            ->query("SELECT nextval('property_reference_seq')")
            ->fetchColumn();

        $consecutive = str_pad(
            (string) $sequence,
            6,
            '0',
            STR_PAD_LEFT
        );

        return sprintf(
            'RG%s%s%s%s',
            $countryCode,
            $provinceCode,
            $cityCode,
            $consecutive
        );
    }

    private function normalizeCode(string $value, int $length): string
    {
        $value = trim($value);

        if ($value === '') {
            return str_repeat('X', $length);
        }

        $normalized = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        if ($normalized === false) {
            $normalized = $value;
        }

        $normalized = strtoupper($normalized);
        $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';

        return str_pad(
            substr($normalized, 0, $length),
            $length,
            'X'
        );
    }
}