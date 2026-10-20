<?php
declare(strict_types=1);
require '/var/www/html/src/Services/PropertySubmissionService.php';
function countryCheck(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$base=['title'=>'QA','description'=>'Prueba de ubicación','city'=>'Vic','province'=>'Barcelona','contact_name'=>'QA','phone'=>'+34930000000','email'=>'qa@example.invalid','price'=>1000,'bedrooms'=>3,'bathrooms'=>1,'built_area'=>70,'operation_type'=>'Alquiler','property_type'=>'Piso','contact_consent'=>'yes'];
$es=$base+['country_code'=>'ES','region_code'=>'08','city_code'=>'08298','postal_code'=>'08500'];
$result=PropertySubmissionService::validate($es);countryCheck($result['country_code']==='ES'&&$result['postal_code']==='08500'&&$result['city']==='Vic','Spanish selection');
$co=array_replace($base,['city'=>'Medellín','province'=>'Antioquia','country_code'=>'CO','region_code'=>'05','city_code'=>'05001','postal_code'=>'050001']);
$result=PropertySubmissionService::validate($co);countryCheck($result['country_code']==='CO'&&$result['country']==='Colombia'&&$result['state_code']==='05','Colombian selection');
foreach([array_replace($co,['region_code'=>'11']),array_replace($co,['country_code'=>'ES']),array_replace($co,['postal_code'=>'08500']),array_replace($es,['country_code'=>'XX']),array_replace($es,['city'=>'Madrid'])] as $invalid){
    try{PropertySubmissionService::validate($invalid);throw new RuntimeException('Invalid location accepted');}catch(SubmissionProblem $expected){}
}
countryCheck(PropertySubmissionService::validate($base)['country_code']==='ES','Legacy submissions remain Spanish');
echo "COUNTRY_CATALOG_VALIDATION_OK\n";
