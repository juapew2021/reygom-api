<?php
declare(strict_types=1);

final class LocationCatalog {
    public static function validate(array $input): array {
        $code=$input['country_code']??'ES';
        if(!is_string($code)||!in_array($code,['ES','CO'],true))throw new SubmissionProblem('Selecciona un país válido.');
        $out=['country_code'=>$code,'country'=>$code==='ES'?'España':'Colombia'];
        if(array_key_exists('country_code',$input)) {
            $catalog=json_decode(file_get_contents(__DIR__.'/../Data/locations.json'),true,512,JSON_THROW_ON_ERROR);
            $country=null;$region=null;$city=null;
            foreach($catalog['countries'] as $candidate)if($candidate['code']===$code)$country=$candidate;
            foreach($country['regions']??[] as $candidate)if($candidate['code']===($input['region_code']??null))$region=$candidate;
            foreach($region['cities']??[] as $candidate)if($candidate['code']===($input['city_code']??null))$city=$candidate;
            if(!$region||!$city)throw new SubmissionProblem('Selecciona una región y un municipio de ese país.');
            if(($input['city']??'')!==$city['name']||($input['province']??'')!==$region['name'])throw new SubmissionProblem('El municipio no corresponde a la región seleccionada.');
            $out+=['region_code'=>$region['code'],'state_code'=>$region['code'],'state'=>$region['name'],'province'=>$region['name'],'city_code'=>$city['code'],'city'=>$city['name']];
        }
        $postal=$input['postal_code']??'';
        if(!is_string($postal))throw new SubmissionProblem('Código postal no válido.');
        if($postal!=='') {
            $pattern=$code==='ES'?'/^[0-9]{5}$/':'/^[0-9]{6}$/';
            if(!preg_match($pattern,$postal))throw new SubmissionProblem('Revisa el código postal de ese país.');
            $out['postal_code']=$postal;
        }
        return $out;
    }
}
