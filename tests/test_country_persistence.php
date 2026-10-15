<?php
declare(strict_types=1);
foreach(['Config/database','Models/Property','Models/PropertyImage','Services/PropertyReferenceGenerator','Services/PropertySubmissionService'] as $part)require '/var/www/html/src/'.$part.'.php';
if(PropertySubmissionService::mode()!=='test')throw new RuntimeException('DEV only');
$pdo=getDatabaseConnection();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema='qa_countries_'.bin2hex(random_bytes(6));$beforeDirs=glob('/var/www/media/properties/RGCOANTMED*')?:[];$createdDir=null;
try {
    $pdo->exec('CREATE SCHEMA '.$schema.'; SET search_path TO '.$schema.', public');
    foreach(['properties','property_images','users','property_submissions'] as $table)$pdo->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
    $pdo->exec('CREATE SEQUENCE '.$schema.'.property_reference_seq START 1');
    $pdo->exec("INSERT INTO users SELECT * FROM public.users WHERE lower(role) IN ('admin','superadmin') LIMIT 1");
    $admin=$pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn();if(!$admin)throw new RuntimeException('DEV administrator missing');
    $input=['title'=>'QA COUNTRY SCOPED','description'=>'Prueba aislada de país, departamento y municipio.','city'=>'Medellín','province'=>'Antioquia','country_code'=>'CO','region_code'=>'05','city_code'=>'05001','contact_name'=>'QA','phone'=>'+573000000000','email'=>'qa@example.invalid','price'=>1000,'bedrooms'=>1,'bathrooms'=>1,'built_area'=>20,'operation_type'=>'Alquiler','property_type'=>'Piso','contact_consent'=>'yes'];
    $data=PropertySubmissionService::validate($input);$id=PropertySubmissionService::uuid();
    $s=$pdo->prepare("INSERT INTO property_submissions(id,payload,email,status,verified_at,verification_expires_at,images) VALUES(?,?,?,'pending_review',now(),now()+interval '24 hours','[]')");$s->execute([$id,json_encode($data,JSON_THROW_ON_ERROR),'qa@example.invalid']);
    $result=(new PropertySubmissionService($pdo,'/tmp/qa-countries-private','https://api-dev.reygom.com'))->review($id,'approve','QA aislado',$admin);
    $row=$pdo->query('SELECT * FROM properties')->fetch(PDO::FETCH_ASSOC);
    if($row['country_code']!=='CO'||$row['country']!=='Colombia'||$row['province']!=='Antioquia'||$row['city']!=='Medellín'||$row['state_code']!=='05'||!str_starts_with($row['reference_id'],'RGCO'))throw new RuntimeException('Country lost during approval');
    $createdDir='/var/www/media/properties/'.$row['reference_id'];
    $property=new Property($pdo);if(!$property->update($result['property_id'],['city'=>'Bogotá, D.C.','province'=>'Bogotá, D.C.','state'=>'Bogotá, D.C.','state_code'=>'11'],$admin,true))throw new RuntimeException('Edit failed');
    $row=$pdo->query('SELECT * FROM properties')->fetch(PDO::FETCH_ASSOC);if($row['country_code']!=='CO'||$row['state_code']!=='11'||$row['city']!=='Bogotá, D.C.')throw new RuntimeException('Country lost during edit');
    echo "COUNTRY_APPROVAL_AND_EDIT_PERSISTENCE_OK_SCOPED_DB\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET search_path TO public; DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
    if($createdDir&&!in_array($createdDir,$beforeDirs,true)&&is_dir($createdDir)&&count(scandir($createdDir))===2)rmdir($createdDir);
}
