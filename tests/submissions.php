<?php
declare(strict_types=1);
require '/var/www/html/src/Config/database.php';
require __DIR__.'/../src/Services/PropertySubmissionService.php';
require __DIR__.'/../src/Routes/submissions.php';
if(strtolower((string)getenv('APP_ENV'))!=='dev')throw new RuntimeException('DEV only');
function check(bool $value,string $message): void { if(!$value)throw new RuntimeException($message); }
function problem(callable $f,int $status): void {try{$f();throw new RuntimeException('Expected rejection');}catch(SubmissionProblem $e){check($e->httpStatus===$status,'Wrong error status');}}
$valid=['title'=>'QA publicación','description'=>'Descripción para probar el proceso de publicación.','city'=>'Barcelona','province'=>'Barcelona','contact_name'=>'QA','phone'=>'+34930000000','email'=>'qa@example.invalid','price'=>1100,'bedrooms'=>3,'bathrooms'=>1,'built_area'=>70,'operation_type'=>'Alquiler','property_type'=>'Piso','contact_consent'=>'yes'];
check(PropertySubmissionService::validate($valid)['operation_type']==='Alquiler','Rental validation');
$sale=$valid;$sale['operation_type']='Venta';check(PropertySubmissionService::validate($sale)['operation_type']==='Venta','Sale validation');
foreach(['email'=>'bad','price'=>-1,'bedrooms'=>2.5,'phone'=>'javascript:alert(1)','contact_consent'=>'no','operation_type'=>'Injection'] as $field=>$bad){$d=$valid;$d[$field]=$bad;problem(fn()=>PropertySubmissionService::validate($d),400);}
$spoof=$valid+['status'=>'approved','agent_id'=>'fake','verified_at'=>'now'];check(!isset(PropertySubmissionService::validate($spoof)['status']),'Client cannot approve itself');
putenv('APP_ENV=PROD');putenv('SUBMISSIONS_MODE=test');check(PropertySubmissionService::mode()==='disabled','Production must not use test verification');
putenv('APP_ENV=DEV');check(PropertySubmissionService::mode()==='test','DEV test mode');
$pdo=getDatabaseConnection();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema='qa_submissions_'.bin2hex(random_bytes(6));$pdo->exec('CREATE SCHEMA '.$schema);$pdo->exec('SET search_path TO '.$schema);
class PropertyReferenceGenerator { public function __construct(private PDO $pdo){} public function generate(...$unused){return 'QA'.bin2hex(random_bytes(4));} }
class Property {public function __construct(private PDO $pdo){}public function create($data,$agent){$id=PropertySubmissionService::uuid();$s=$this->pdo->prepare('INSERT INTO properties(id) VALUES (:id)');$s->execute(['id'=>$id]);return ['id'=>$id]+$data;}}
class PropertyImage {public function __construct(private PDO $pdo){} public function create(...$unused){throw new RuntimeException('Unexpected image in state test');}}
class JwtMiddleware { public static function authenticate(){return ['id'=>PropertySubmissionService::uuid(),'role'=>'agent'];} }
try {
    $pdo->exec('CREATE TABLE properties (id uuid PRIMARY KEY); CREATE TABLE users (id uuid PRIMARY KEY)');
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/20261014_property_submissions.sql'));
    $admin=PropertySubmissionService::uuid();$pdo->exec("INSERT INTO users(id) VALUES ('".$admin."')");
    $service=new PropertySubmissionService($pdo,'/tmp/reygom-qa-absent','https://api-dev.reygom.com');
    foreach(['/submissions','/submissions/'.PropertySubmissionService::uuid().'/outbox','/submissions/'.PropertySubmissionService::uuid().'/images/'.str_repeat('a',32).'.webp'] as $route){ob_start();handleSubmissionRoutes($route,'GET',$pdo);ob_end_clean();check(http_response_code()===403,'Non-admin cannot access inbox or private media');}
    $seed=function(string $status,bool $expired=false)use($pdo,$valid){$id=PropertySubmissionService::uuid();$token=bin2hex(random_bytes(32));$s=$pdo->prepare('INSERT INTO property_submissions(id,payload,email,verification_hash,verification_expires_at,status,verified_at) VALUES (:id,:payload,:email,:hash,:expires,:status,:verified)');$s->execute(['id'=>$id,'payload'=>json_encode($valid),'email'=>$valid['email'],'hash'=>hash('sha256',$token),'expires'=>gmdate('c',time()+($expired?-3600:3600)),'status'=>$status,'verified'=>$status==='pending_review'?gmdate('c'):null]);return [$id,$token];};
    [$id,$token]=$seed('awaiting_email');problem(fn()=>$service->review($id,'approve','',$admin),409);
    check($service->verify($token)['status']==='pending_review','Valid email link moves to review');problem(fn()=>$service->verify($token),410);
    [$expired,$old]=$seed('awaiting_email',true);problem(fn()=>$service->verify($old),410);problem(fn()=>$service->verify(str_repeat('a',64)),410);
    $approved=$service->review($id,'approve','QA',$admin);check($approved['status']==='approved'&&!!$approved['property_id'],'Approval creates property');problem(fn()=>$service->review($id,'approve','',$admin),409);
    [$reject]=$seed('pending_review');check($service->review($reject,'reject','QA',$admin)['status']==='rejected','Reject verified submission');
    check((int)$pdo->query('SELECT count(*) FROM properties')->fetchColumn()===1,'Rejected/unverified submissions never create public properties');
    try{$seed('approved');throw new RuntimeException('Missing DB state constraint');}catch(PDOException $expected){}
    echo "SUBMISSIONS_QA_OK: validation, production gate, single-use tokens, expiry, approval, rejection, replay and database constraints\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA '.$schema.' CASCADE');}
