<?php
declare(strict_types=1);

final class SubmissionProblem extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 400) { parent::__construct($message); }
}

final class PropertySubmissionService {
    public function __construct(private PDO $db, private string $root, private string $publicBase) {}

    public static function mode(): string {
        // A test mailbox must never substitute for real email verification in production.
        return strtolower((string)getenv('APP_ENV')) === 'dev' && getenv('SUBMISSIONS_MODE') === 'test'
            ? 'test' : 'disabled';
    }

    public static function validate(array $input): array {
        $out = [];
        foreach (['title'=>160,'description'=>12000,'city'=>100,'province'=>100,'contact_name'=>100,'phone'=>30,'email'=>254] as $key=>$limit) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !trim($value) || strlen(trim($value)) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
                throw new SubmissionProblem('Revisa el campo '.$key.'.');
            }
            $out[$key] = trim($value);
        }
        $out['email'] = strtolower($out['email']);
        if (!filter_var($out['email'], FILTER_VALIDATE_EMAIL)) throw new SubmissionProblem('Correo no válido.');
        $phoneDigits=preg_replace('/\D/','',$out['phone']);
        if (!preg_match('/^\+?[0-9 ()-]{7,30}$/', $out['phone']) || strlen($phoneDigits)<7 || strlen($phoneDigits)>15) throw new SubmissionProblem('Teléfono no válido.');
        if (!in_array($input['operation_type'] ?? '', ['Alquiler','Venta'], true)) throw new SubmissionProblem('Selecciona alquiler o venta.');
        if (!in_array($input['property_type'] ?? '', ['Piso','Casa','Ático','Dúplex','Local','Terreno','Garaje'], true)) throw new SubmissionProblem('Selecciona el tipo de inmueble.');
        $out['operation_type'] = $input['operation_type'];
        $out['property_type'] = $input['property_type'];
        foreach (['price'=>[1,100000000],'bedrooms'=>[0,50],'bathrooms'=>[0,50],'built_area'=>[1,1000000]] as $key=>$range) {
            $value = $input[$key] ?? null;
            if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < $range[0] || (float)$value > $range[1]) throw new SubmissionProblem('Revisa el campo '.$key.'.');
            if (in_array($key, ['bedrooms','bathrooms'], true) && floor((float)$value) !== (float)$value) throw new SubmissionProblem('Habitaciones y baños deben ser números enteros.');
            $out[$key] = (float)$value;
        }
        if (($input['contact_consent'] ?? '') !== 'yes') throw new SubmissionProblem('Autoriza el contacto del equipo para revisar el anuncio.');
        $out['contact_consent'] = true;
        return $out; // Ignore client supplied status, agent IDs and verification fields.
    }

    public static function uuid(): string {
        $b = random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }

    private function query(string $sql, array $args=[]): PDOStatement {
        $s=$this->db->prepare($sql); $s->execute($args); return $s;
    }

    public function submit(array $input, array $upload): array {
        if (self::mode() !== 'test') throw new SubmissionProblem('La publicación todavía no está disponible. Falta configurar el envío de correo.',503);
        $data=self::validate($input);
        if (!empty($input['website'])) throw new SubmissionProblem('No se pudo enviar el anuncio.');
        $id=self::uuid(); $token=bin2hex(random_bytes(32)); $dir=$this->root.'/'.$id;
        $images=[]; $this->db->beginTransaction();
        try {
            $this->query("SELECT pg_advisory_xact_lock(hashtext('reygom_public_submissions'))");
            $hour=(int)$this->query("SELECT count(*) FROM property_submissions WHERE created_at > now()-interval '1 hour'")->fetchColumn();
            $day=(int)$this->query("SELECT count(*) FROM property_submissions WHERE email=:email AND created_at > now()-interval '1 day'",['email'=>$data['email']])->fetchColumn();
            if ($hour>=50 || $day>=5) throw new SubmissionProblem('Se ha alcanzado el límite de envíos. Inténtalo más tarde.',429);
            $images=$this->storeUploads($upload,$dir);
            $this->query("INSERT INTO property_submissions (id,payload,email,verification_hash,verification_expires_at,images) VALUES (:id,:payload,:email,:hash,now()+interval '24 hours',:images)",[
                'id'=>$id,'payload'=>json_encode($data,JSON_THROW_ON_ERROR),'email'=>$data['email'],'hash'=>hash('sha256',$token),'images'=>json_encode($images,JSON_THROW_ON_ERROR)
            ]);
            // Private outbox for DEV; reachable only through the administrator route.
            $mail=['to'=>$data['email'],'verification_token'=>$token,'created_at'=>gmdate('c'),'test_only'=>true];
            if (file_put_contents($dir.'/verification.json',json_encode($mail,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Cannot write test outbox');
            chmod($dir.'/verification.json',0600);
            $this->db->commit();
            return ['id'=>$id,'status'=>'awaiting_email','test_mode'=>true,'message'=>'Anuncio de prueba recibido. El enlace de verificación está en la bandeja de pruebas del equipo; no se ha enviado un correo real.'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->removeFiles($dir); throw $e;
        }
    }

    private function storeUploads(array $files,string $dir): array {
        $names=$files['name']??[];
        if (!is_array($names) || count($names)<1 || count($names)>10) throw new SubmissionProblem('Añade entre una y diez fotografías.');
        if (!mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cannot create private media directory');
        $total=0; $stored=[];
        foreach ($names as $i=>$unused) {
            $path=$files['tmp_name'][$i]??''; $bytes=$files['size'][$i]??0; $total+=$bytes;
            if (($files['error'][$i]??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($path) || $bytes<=0 || $bytes>5*1024*1024 || $total>20*1024*1024) throw new SubmissionProblem('Cada foto admite 5 MB; el total máximo es 20 MB.');
            $info=@getimagesize($path);
            if (!$info || !in_array($info['mime'],['image/jpeg','image/png','image/webp'],true) || $info[0]*$info[1]>16000000) throw new SubmissionProblem('Usa fotos JPG, PNG o WebP de hasta 16 megapíxeles.');
            $image=match($info['mime']) { 'image/jpeg'=>@imagecreatefromjpeg($path),'image/png'=>@imagecreatefrompng($path),'image/webp'=>@imagecreatefromwebp($path) };
            if (!$image) throw new SubmissionProblem('No se pudo leer una fotografía.');
            if (max($info[0],$info[1])>1920) {
                $scale=1920/max($info[0],$info[1]); $resized=imagescale($image,max(1,(int)round($info[0]*$scale)),max(1,(int)round($info[1]*$scale)));
                imagedestroy($image); $image=$resized;
            }
            $name=bin2hex(random_bytes(16)).'.webp';
            $ok=$image && imagewebp($image,$dir.'/'.$name,82); if ($image) imagedestroy($image);
            if (!$ok) throw new RuntimeException('Cannot write image');
            chmod($dir.'/'.$name,0600); $stored[]=$name;
        }
        return $stored;
    }

    private function removeFiles(string $dir): void {
        if (is_dir($dir)) { foreach (glob($dir.'/*')?:[] as $f) if(is_file($f)) unlink($f); rmdir($dir); }
    }

    public function verify(string $token): array {
        if (self::mode()!=='test') throw new SubmissionProblem('Verificación no disponible.',503);
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) throw new SubmissionProblem('Enlace no válido o caducado.',410);
        $id=$this->query("UPDATE property_submissions SET status='pending_review',verified_at=now(),verification_hash=NULL WHERE verification_hash=:hash AND status='awaiting_email' AND verification_expires_at>now() RETURNING id",['hash'=>hash('sha256',$token)])->fetchColumn();
        if (!$id) throw new SubmissionProblem('Enlace no válido, ya utilizado o caducado.',410);
        $f=$this->root.'/'.$id.'/verification.json'; if (is_file($f)) unlink($f);
        return ['status'=>'pending_review','message'=>'Correo verificado en DEV. El equipo revisará el anuncio antes de publicarlo.'];
    }

    public function list(): array {
        $rows=$this->query('SELECT id,payload,status,images,property_id,verified_at,review_note,created_at FROM property_submissions ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) { $row['payload']=json_decode($row['payload'],true); $row['images']=json_decode($row['images'],true); }
        return $rows;
    }

    public function outbox(string $id): array {
        if(self::mode()!=='test') throw new SubmissionProblem('Bandeja de pruebas desactivada.',404);
        $path=$this->root.'/'.$id.'/verification.json';
        if(!is_file($path)) throw new SubmissionProblem('No hay verificación pendiente.',404);
        return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }

    public function image(string $id,string $name): string {
        $row=$this->query('SELECT images FROM property_submissions WHERE id=:id',['id'=>$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row || !in_array($name,json_decode($row['images'],true),true)) throw new SubmissionProblem('Imagen no encontrada.',404);
        $path=$this->root.'/'.$id.'/'.$name;
        if(!is_file($path)) throw new SubmissionProblem('Imagen no encontrada.',404);
        return $path;
    }

    public function review(string $id,string $decision,string $note,string $reviewer): array {
        if(!in_array($decision,['approve','reject'],true) || strlen($note)>2000) throw new SubmissionProblem('Decisión de revisión no válida.');
        $this->db->beginTransaction(); $copied=[];
        try {
            $row=$this->query('SELECT * FROM property_submissions WHERE id=:id FOR UPDATE',['id'=>$id])->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new SubmissionProblem('Anuncio no encontrado.',404);
            if($row['status']!=='pending_review' || !$row['verified_at']) throw new SubmissionProblem('Solo se pueden revisar anuncios con correo verificado y pendientes.',409);
            $propertyId=null;
            if($decision==='approve') {
                $d=json_decode($row['payload'],true); $reference=(new PropertyReferenceGenerator($this->db))->generate($d['city'],$d['province'],'ES');
                $propertyData=array_intersect_key($d,array_flip(['title','description','price','city','province','bedrooms','bathrooms','built_area','operation_type','property_type']));
                $propertyData['reference_id']=$reference; $propertyData['status']='Disponible';
                $created=(new Property($this->db))->create($propertyData,$reviewer);
                if(!$created) throw new RuntimeException('Cannot create property');
                $propertyId=$created['id'];
                $target='/var/www/media/properties/'.$reference;
                if(!is_dir($target) && !mkdir($target,0755,true)) throw new RuntimeException('Cannot create property media');
                foreach(json_decode($row['images'],true) as $position=>$name) {
                    $dest=$target.'/'.$name;
                    if(!@copy($this->root.'/'.$id.'/'.$name,$dest)) throw new RuntimeException('Cannot publish image');
                    chmod($dest,0644); $copied[]=$dest;
                    $url=$this->publicBase.'/media/properties/'.rawurlencode($reference).'/'.$name;
                    (new PropertyImage($this->db))->create($propertyId,$url,$position,$position===0);
                    if($position===0) $this->query('UPDATE properties SET cover_image=:cover,image_url=:image WHERE id=:id',['cover'=>$url,'image'=>$url,'id'=>$propertyId]);
                }
            }
            $status=$decision==='approve'?'approved':'rejected';
            $this->query('UPDATE property_submissions SET status=:status,property_id=:property,reviewed_by=:reviewer,review_note=:note,reviewed_at=now() WHERE id=:id',['status'=>$status,'property'=>$propertyId,'reviewer'=>$reviewer,'note'=>$note,'id'=>$id]);
            $this->db->commit(); return ['status'=>$status,'property_id'=>$propertyId];
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); foreach($copied as $path)if(is_file($path))unlink($path); throw $e; }
    }
}
