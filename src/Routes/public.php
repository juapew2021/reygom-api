<?php

function publicReferenceQuery(string $input):array {
 $raw=strtoupper(trim(preg_replace('/^REF\s*[:.]\s*/i','',trim($input))));
 $canonical=$raw;
 if(preg_match('/^RG([0-9]{1,19})$/D',$raw,$m)){$digits=ltrim($m[1],'0');$canonical='RG'.str_pad($digits===''?'0':$digits,6,'0',STR_PAD_LEFT);}
 return [$raw,$canonical];
}
function handlePublicRoutes($request_uri,$method,$pdo){
 $id=null;$reference=null;
 if(preg_match('#^/public/properties/reference/([^/]+)$#D',$request_uri,$m)){$reference=rawurldecode($m[1]);$request_uri='/public/properties/{reference}';}
 elseif(preg_match('#^/public/properties/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$#iD',$request_uri,$m)){$id=$m[1];$request_uri='/public/properties/{id}';}
 if(!in_array($request_uri,['/public/properties','/public/properties/{id}','/public/properties/{reference}'],true))return false;
 if($method!=='GET'){http_response_code(405);echo json_encode(['message'=>'Método no permitido']);return true;}
 $columns='id,reference_id,public_reference,title,description,price,operation_type,property_type,status,cover_image,country_code,country,state_code,state,postal_code,city,province,bedrooms,bathrooms,built_area,features,created_at';
 $columns=implode(',',array_map(static fn($column)=>'p.'.$column,explode(',',$columns)));
 $publicFrom=" FROM properties p JOIN organizations o ON o.id=p.organization_id WHERE p.status='Disponible' AND o.status='active' AND o.deleted_at IS NULL";
 if($request_uri==='/public/properties'){$stmt=$pdo->prepare("SELECT $columns".$publicFrom.' ORDER BY p.created_at DESC,p.id DESC');$stmt->execute();echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));return true;}
 if($id!==null){$stmt=$pdo->prepare("SELECT $columns".$publicFrom.' AND p.id=:id LIMIT 1');$stmt->execute(['id'=>$id]);}
 else{
  if(strlen($reference)>100||preg_match('/[\x00-\x1f]/',$reference)){http_response_code(422);echo json_encode(['message'=>'Referencia no válida.']);return true;}
  [$raw,$canonical]=publicReferenceQuery($reference);
  $stmt=$pdo->prepare("SELECT $columns".$publicFrom.' AND (p.public_reference=:canonical OR upper(p.reference_id)=:legacy) LIMIT 1');$stmt->execute(['canonical'=>$canonical,'legacy'=>$raw]);
 }
 $property=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$property){http_response_code(404);echo json_encode(['message'=>'Inmueble no encontrado']);return true;}
 $property['images']=(new PropertyImage($pdo))->readByProperty($property['id']);
 echo json_encode($property);return true;
}
