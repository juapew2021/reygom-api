<?php
declare(strict_types=1);
function suggestedCountry(string $ip):?string {
 if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
 $path=getenv('ANALYTICS_GEO_DB')?:'/opt/analytics/dbip-city-lite.mmdb';$autoload='/opt/analytics/vendor/autoload.php';
 if(!is_readable($path)||!is_readable($autoload))return null;
 require_once $autoload;
 try{$reader=new \MaxMind\Db\Reader($path);try{$geo=$reader->get($ip);}finally{$reader->close();}$code=$geo['country']['iso_code']??null;return in_array($code,['ES','CO'],true)?$code:null;}
 catch(Throwable $e){error_log('Country suggestion unavailable');return null;}
}
function handleCountryLocation(string $uri,string $method):bool {
 if($uri!=='/public/location')return false;
 header('Cache-Control: private, no-store');header('Vary: Origin');
 if($method!=='GET'){http_response_code(405);echo json_encode(['message'=>'Método no permitido.']);return true;}
 // Reuse the verified proxy configuration. Never trust forwarded IP headers from arbitrary peers.
 $peer=$_SERVER['REMOTE_ADDR']??'';$trusted=array_filter(array_map('trim',explode(',',getenv('ACCESS_TRUSTED_PROXIES')?:'')));
 $ip=in_array($peer,$trusted,true)?($_SERVER['HTTP_X_REAL_IP']??''):$peer;
 if(getenv('ACCESS_REQUIRE_PROXY')==='1'&&!in_array($peer,$trusted,true))$ip='';
 echo json_encode(['country_code'=>suggestedCountry($ip)]);return true;
}
