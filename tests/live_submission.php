<?php
declare(strict_types=1);
foreach(['Config/database','Models/Property','Models/PropertyImage','Services/PropertyReferenceGenerator','Services/PropertySubmissionService'] as $part) require '/var/www/html/src/'.$part.'.php';
if(PropertySubmissionService::mode()!=='test')throw new RuntimeException('DEV test mode only');
$pdo=getDatabaseConnection();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$title=$argv[1]??'';
if(!str_starts_with($title,'QA PUBLICACION DEV 20261014 '))throw new RuntimeException('QA title required');
$stmt=$pdo->prepare("SELECT * FROM property_submissions WHERE payload->>'title'=:title");$stmt->execute(['title'=>$title]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$row || $row['status']!=='awaiting_email')throw new RuntimeException('Expected one awaiting verification fixture');
$id=$row['id'];$images=json_decode($row['images'],true);$payload=json_decode($row['payload'],true);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function assertQa(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
assertQa($payload['phone']==='930000000' && count($images)===1,'Contact and photograph stored');
assertQa(getimagesize('/var/www/media/submissions-private/'.$id.'/'.$images[0])['mime']==='image/webp','Private photo optimized');
$admin=$pdo->query("SELECT id FROM users WHERE lower(role) IN ('admin','superadmin') LIMIT 1")->fetchColumn();
assertQa((bool)$admin,'DEV administrator required');
$service=new PropertySubmissionService($pdo,'/var/www/media/submissions-private','https://api-dev.reygom.com');
try{$service->review($id,'approve','QA',$admin);throw new RuntimeException('Unverified approval allowed');}catch(SubmissionProblem $e){assertQa($e->httpStatus===409,'Unverified rejected');}
$outbox=$service->outbox($id);$service->verify($outbox['verification_token']);
try{$service->verify($outbox['verification_token']);throw new RuntimeException('Token replay allowed');}catch(SubmissionProblem $e){assertQa($e->httpStatus===410,'Token consumed');}
// Force failure after the first image is inserted: the whole approval must roll back.
$stmt=$pdo->prepare('UPDATE property_submissions SET images=:images WHERE id=:id');$stmt->execute(['images'=>json_encode([...$images,str_repeat('f',32).'.webp']),'id'=>$id]);
try{$service->review($id,'approve','QA rollback',$admin);throw new RuntimeException('Missing image accepted');}catch(RuntimeException $e){assertQa($e->getMessage()==='Cannot publish image','Expected controlled image failure');}
$stmt=$pdo->prepare('SELECT status FROM property_submissions WHERE id=:id');$stmt->execute(['id'=>$id]);assertQa($stmt->fetchColumn()==='pending_review','Failed approval remains pending');
$stmt=$pdo->prepare('SELECT count(*) FROM properties WHERE title=:title');$stmt->execute(['title'=>$title]);assertQa((int)$stmt->fetchColumn()===0,'Failed approval creates no property');
$stmt=$pdo->prepare('UPDATE property_submissions SET images=:images WHERE id=:id');$stmt->execute(['images'=>json_encode($images),'id'=>$id]);
$approved=$service->review($id,'approve','QA completo de correo, contacto, fotos y publicación',$admin);$propertyId=$approved['property_id'];
$stmt=$pdo->prepare('SELECT reference_id,status,cover_image,description FROM properties WHERE id=:id');$stmt->execute(['id'=>$propertyId]);$property=$stmt->fetch();
assertQa($property['status']==='Disponible' && str_starts_with($property['cover_image'],'https://api-dev.reygom.com/media/properties/'),'Approved with DEV photo URL');
assertQa($property['description']===$payload['description'],'Full description preserved');
$stmt=$pdo->prepare('SELECT count(*) FROM property_images WHERE property_id=:id');$stmt->execute(['id'=>$propertyId]);assertQa((int)$stmt->fetchColumn()===1,'Photo metadata committed');
file_put_contents('/tmp/publication-qa-result.json',json_encode(['submission_id'=>$id,'property_id'=>$propertyId,'reference'=>$property['reference_id'],'image_names'=>$images,'cover_url'=>$property['cover_image'],'title'=>$title]));
echo "PUBLICATION_LIVE_QA_OK: public form, upload, contact, verification, replay rejection, transactional photo rollback, approval, gallery and description\n";
