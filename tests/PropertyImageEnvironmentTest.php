<?php
require __DIR__ . '/../src/Models/PropertyImage.php';
require __DIR__ . '/../src/Services/PropertyImageService.php';
class TestPDO extends PDO { public function __construct() {} }
$property = new ReflectionProperty(PropertyImageService::class, 'publicBaseUrl');
$cases = [false => 'https://api.reygom.com/media/properties'];
foreach ([null, 'https://api-dev.reygom.com/media/properties/', 'https://api.reygom.com/media/properties/'] as $base) {
    $base === null ? putenv('MEDIA_PUBLIC_BASE_URL') : putenv('MEDIA_PUBLIC_BASE_URL=' . $base);
    $service = new PropertyImageService(new TestPDO());
    $expected = $base === null ? 'https://api.reygom.com/media/properties' : rtrim($base, '/');
    if ($property->getValue($service) !== $expected) throw new RuntimeException('Unexpected media URL');
}
echo "Media environment tests passed (3 cases).\n";
