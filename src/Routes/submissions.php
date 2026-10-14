<?php
declare(strict_types=1);

function handleSubmissionRoutes(string $path,string $method,PDO $pdo): bool {
    $uuid='[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}';
    $public=in_array($path,['/public/submissions','/public/submissions/config','/public/submissions/verify'],true);
    $matches=[];
    $admin=$path==='/submissions' || preg_match('#^/submissions/('.$uuid.')/(review|outbox|images/([a-f0-9]{32}\.webp))$#',$path,$matches);
    if(!$public && !$admin)return false;
    header('Cache-Control: no-store');
    try {
        if($admin) {
            $user=JwtMiddleware::authenticate();
            if(!in_array(strtolower((string)($user['role']??'')),['admin','superadmin'],true))throw new SubmissionProblem('Solo el equipo administrador puede revisar anuncios.',403);
        }
        $base=rtrim((string)getenv('PUBLIC_API_BASE_URL'),'/');
        $service=new PropertySubmissionService($pdo,'/var/www/media/submissions-private',$base);
        $id=$matches[1]??''; $action=$matches[2]??'';
        if($path==='/public/submissions/config' && $method==='GET') {
            $mode=PropertySubmissionService::mode();
            echo json_encode(['enabled'=>$mode==='test','test_mode'=>$mode==='test']);return true;
        }
        if($path==='/public/submissions' && $method==='POST') {
            $data=$service->submit($_POST,$_FILES['images']??[]); http_response_code(202);
        } elseif($path==='/public/submissions/verify' && $method==='POST') {
            $input=json_decode(file_get_contents('php://input'),true);
            $data=$service->verify(is_string($input['token']??null)?$input['token']:'');
        } elseif($path==='/submissions' && $method==='GET') { $data=$service->list();
        } elseif($action==='outbox' && $method==='GET') { $data=$service->outbox($id);
        } elseif(str_starts_with($action,'images/') && $method==='GET') {
            $image=$service->image($id,$matches[3]);header('Content-Type: image/webp');header('X-Content-Type-Options: nosniff');readfile($image);return true;
        } elseif($action==='review' && $method==='POST') {
            if(!$base || !preg_match('#^https://#',$base))throw new SubmissionProblem('Falta configurar la URL pública de imágenes.',503);
            $input=json_decode(file_get_contents('php://input'),true);
            $data=$service->review($id,(string)($input['decision']??''),(string)($input['note']??''),(string)$user['id']);
        } else { header('Allow: GET, POST');throw new SubmissionProblem('Método no permitido.',405); }
        echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch(SubmissionProblem $e) { http_response_code($e->httpStatus);echo json_encode(['message'=>$e->getMessage()]);
    } catch(Throwable $e) { error_log('Property submission error: '.$e->getMessage());http_response_code(500);echo json_encode(['message'=>'No se pudo completar la operación. Inténtalo de nuevo.']); }
    return true;
}
