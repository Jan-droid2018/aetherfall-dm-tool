<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Formula\FormulaEngine;
use Aetherfall\Repositories\CharacterRepository;
use Aetherfall\Repositories\ContentRepository;
use Aetherfall\Services\CombatCalculator;
use Aetherfall\Services\CombatValueParser;
use Aetherfall\Services\EncounterService;
use Aetherfall\Support\Database;

$api = trim((string)($_GET['api'] ?? ''), '/');
if ($api === '') {
    require __DIR__ . '/view.php';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$pdo = Database::connection();
$content = new ContentRepository($pdo);
$characters = new CharacterRepository($pdo);
$encounters = new EncounterService($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];

function respond(mixed $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function actorVariables(PDO $pdo, int $participantId, ?string $magicAttribute): array {
    $s=$pdo->prepare('SELECT * FROM combat_participants WHERE id=?');$s->execute([$participantId]);$p=$s->fetch();if(!$p)return[];$variables=[];
    if($p['participant_type']==='character'){$s=$pdo->prepare('SELECT level,critical_damage_percent FROM characters WHERE id=?');$s->execute([(int)$p['reference_id']]);$character=$s->fetch();if(!$character)return[];$variables['Stufe']=(float)$character['level'];$variables['Kritischer Schaden']=(float)$character['critical_damage_percent'];$s=$pdo->prepare('SELECT attribute_code,value,modifier,bonus FROM character_attributes WHERE character_id=?');$s->execute([(int)$p['reference_id']]);foreach($s->fetchAll() as $r){$variables[$r['attribute_code'].'-Wert']=(float)$r['value'];$variables[$r['attribute_code'].'-Modifikator']=(float)$r['modifier'];$variables[$r['attribute_code'].'-Bonus']=(float)$r['bonus'];}if($magicAttribute){$variables['Magieattribut-Modifikator']=$variables[$magicAttribute.'-Modifikator']??null;$variables['Magieattribut-Bonus']=$variables[$magicAttribute.'-Bonus']??null;}}
    elseif($p['participant_type']==='creature'){$s=$pdo->prepare('SELECT attributes_json,combat_values_json FROM creature_levels WHERE creature_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$profile=$s->fetch();if(!$profile)return[];$rows=json_decode((string)$profile['attributes_json'],true)?:[];$variables['Stufe']=(float)$p['selected_level'];$variables['Kritischer Schaden']=(new CombatValueParser())->criticalDamagePercent((string)$profile['combat_values_json']);foreach($rows as $r){$variables[$r['code'].'-Wert']=(float)$r['value'];$variables[$r['code'].'-Modifikator']=(float)$r['modifier'];$variables[$r['code'].'-Bonus']=(float)$r['bonus'];}}
    else{$s=$pdo->prepare('SELECT attribute_profiles_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$profiles=json_decode((string)$s->fetchColumn(),true)?:[];$variables['Stufe']=(float)$p['selected_level'];foreach(($profiles[0]['attributes']??[])as$r){$variables[$r['code'].'-Wert']=(float)$r['value'];$variables[$r['code'].'-Modifikator']=(float)$r['modifier'];$variables[$r['code'].'-Bonus']=(float)$r['bonus'];}}
    return array_filter($variables,static fn($v)=>$v!==null);
}
function actorCriticalSource(PDO $pdo,int $participantId,bool $hasCriticalValue):?string{
    if(!$hasCriticalValue)return null;
    $s=$pdo->prepare('SELECT participant_type FROM combat_participants WHERE id=?');$s->execute([$participantId]);
    return match($s->fetchColumn()){'character'=>'Charakter','creature'=>'Kreaturenprofil','boss'=>'Bossprofil',default=>null};
}
function actorSourceIsDamage(PDO $pdo,int $participantId,?string $sourceType,?string $sourceId):bool{
    if(!$sourceType||!$sourceId)return true;
    $s=$pdo->prepare('SELECT participant_type,reference_id,selected_level FROM combat_participants WHERE id=?');$s->execute([$participantId]);$participant=$s->fetch();if(!$participant)throw new RuntimeException('Aktiver Teilnehmer nicht gefunden.');
    if($participant['participant_type']!=='character')return true;
    if($sourceType==='ability'){$s=$pdo->prepare('SELECT a.type,a.damage_type FROM character_abilities ca JOIN abilities a ON a.id=ca.ability_id WHERE ca.character_id=? AND a.id=?');$s->execute([(int)$participant['reference_id'],$sourceId]);}
    elseif($sourceType==='spell'){$s=$pdo->prepare('SELECT s.effect_type type,s.damage_type FROM character_spells cs JOIN spells s ON s.id=cs.spell_id WHERE cs.character_id=? AND s.id=?');$s->execute([(int)$participant['reference_id'],$sourceId]);}
    else throw new RuntimeException('Ungültige Charakterquelle für die Berechnung.');
    $source=$s->fetch();if(!$source)throw new RuntimeException('Die gewählte Fähigkeit oder der Zauber ist dem Charakter nicht zugeordnet.');
    return trim((string)($source['damage_type']??''))!==''||mb_stripos((string)($source['type']??''),'Schaden')!==false;
}
function targetAdjustments(PDO $pdo, int $participantId, ?string $damageType): array {
    $result=['resistance'=>0.0,'defense'=>0.0,'sources'=>[]];
    if(!$participantId||!$damageType)return$result;
    $s=$pdo->prepare('SELECT * FROM combat_participants WHERE id=?');$s->execute([$participantId]);$p=$s->fetch();if(!$p||$p['participant_type']==='character')return$result;
    $table=$p['participant_type']==='creature'?'creature_levels':'boss_levels';$foreign=$p['participant_type']==='creature'?'creature_id':'boss_id';
    $s=$pdo->prepare("SELECT physical_defense,magic_defense,elemental_resistances_json FROM {$table} WHERE {$foreign}=? AND level=?");$s->execute([$p['reference_id'],$p['selected_level']]);$profile=$s->fetch();if(!$profile)return$result;
    $element=null;foreach(['feuer','wasser','erde','luft','licht','dunkelheit']as$key)if(mb_stripos($damageType,$key)!==false){$element=$key;break;}
    if($element){$res=json_decode((string)$profile['elemental_resistances_json'],true);$entry=$res['elements'][$element]??null;if($entry){$percent=(float)($entry['percent']??0);if(($entry['type']??'')==='vulnerability')$percent=-$percent;$result['resistance']=$percent;$result['sources'][]=ucfirst($element).': '.($entry['raw']??'neutral');}$result['defense']=(float)($profile['magic_defense']??0);$result['sources'][]='Magieverteidigung des Zielprofils';}
    elseif(preg_match('/(?:Stich|Hieb|Wucht)/iu',$damageType)){$result['defense']=(float)($profile['physical_defense']??0);$result['sources'][]='Physische Verteidigung des Zielprofils';}
    return$result;
}

try {
    if ($api === 'stats' && $method === 'GET') respond($content->stats());
    if ($api === 'classes' && $method === 'GET') respond($content->classes());
    if (preg_match('#^classes/([^/]+)/abilities$#', $api, $m) && $method === 'GET') respond($content->abilities($m[1], (int)($_GET['level'] ?? 999)));
    if ($api === 'spells' && $method === 'GET') respond($content->spells($_GET));
    if ($api === 'creatures' && $method === 'GET') respond($content->creatures());
    if ($api === 'bosses' && $method === 'GET') respond($content->bosses());
    if ($api === 'characters' && $method === 'GET') respond($characters->all());
    if ($api === 'characters' && $method === 'POST') { $id=$characters->save($body); respond($characters->find($id),201); }
    if (preg_match('#^characters/(\d+)$#',$api,$m)) {
        $id=(int)$m[1]; if($method==='GET'){ $row=$characters->find($id); $row?respond($row):respond(['error'=>'Charakter nicht gefunden.'],404); }
        if($method==='PUT'){ $characters->save($body,$id); respond($characters->find($id)); }
        if($method==='DELETE'){ $characters->delete($id); respond(['ok'=>true]); }
    }
    if($api==='encounters'&&$method==='GET')respond($encounters->all());
    if($api==='encounters'&&$method==='POST')respond(['id'=>$encounters->create($body['name']??null)],201);
    if(preg_match('#^encounters/(\d+)$#',$api,$m)&&$method==='GET'){ $row=$encounters->find((int)$m[1]);$row?respond($row):respond(['error'=>'Kampf nicht gefunden.'],404); }
    if(preg_match('#^encounters/(\d+)/participants$#',$api,$m)&&$method==='POST')respond(['id'=>$encounters->addParticipant((int)$m[1],$body)],201);
    if(preg_match('#^encounters/(\d+)/participants/(\d+)$#',$api,$m)){
        if($method==='PATCH'){ $encounters->updateParticipant((int)$m[1],(int)$m[2],$body);respond(['ok'=>true]); }
        if($method==='DELETE'){ $encounters->removeParticipant((int)$m[1],(int)$m[2]);respond(['ok'=>true]); }
    }
    if(preg_match('#^encounters/(\d+)/(next|previous)$#',$api,$m)&&$method==='POST'){ $encounters->moveTurn((int)$m[1],$m[2]==='next'?1:-1);respond(['ok'=>true]); }
    if(preg_match('#^encounters/(\d+)/apply-effect$#',$api,$m)&&$method==='POST')respond($encounters->applyEffect((int)$m[1],$body));
    if($api==='calculate'&&$method==='POST'){
        $engine=new FormulaEngine();$formula=(string)($body['formula']??'');$expression=$engine->extractMath($formula)??$formula;
        $variables=actorVariables($pdo,(int)($body['actor_participant_id']??0),$body['magic_attribute']??null);
        $variables=array_merge($variables,$body['manual_variables']??[]);
        $result=$engine->evaluate($expression,['variables'=>$variables,'dice'=>$body['dice']??[]]);
        $result['original_text']=$formula;$result['expression']=$expression;
        $attackFormula=trim((string)($body['attack_formula']??''));if($attackFormula!==''){$attackExpression=$engine->extractMath($attackFormula)??$attackFormula;$attackDice=[];if(isset($body['attack_roll'])&&$body['attack_roll']!=='')$attackDice['W20']=$body['attack_roll'];$attack=$engine->evaluate($attackExpression,['variables'=>$variables,'dice'=>$attackDice]);$attack['expression']=$attackExpression;$targetTotal=$body['target_total']??null;if($attack['value']!==null&&$targetTotal!==null&&$targetTotal!==''){$attack['target_total']=(float)$targetTotal;$attack['hit']=$attack['value']>(float)$targetTotal?true:($attack['value']<(float)$targetTotal?false:null);}$result['attack']=$attack;}
        if($result['value']!==null){if(array_key_exists('critical',$body)&&!is_bool($body['critical']))throw new RuntimeException('Der Krit-Schalter muss ein eindeutiger Boolean sein.');$actorId=(int)($body['actor_participant_id']??0);$isCritical=$body['critical']??false;$isDamage=actorSourceIsDamage($pdo,$actorId,isset($body['source_type'])?(string)$body['source_type']:null,isset($body['source_id'])?(string)$body['source_id']:null);$auto=$isDamage?targetAdjustments($pdo,(int)($body['target_participant_id']??0),$body['damage_type']??null):['resistance'=>0.0,'defense'=>0.0,'sources'=>[]];$resistance=array_key_exists('resistance_percent',$body)&&$body['resistance_percent']!==''?(float)$body['resistance_percent']:$auto['resistance'];$defense=array_key_exists('defense',$body)&&$body['defense']!==''?(float)$body['defense']:$auto['defense'];$hasCriticalValue=array_key_exists('Kritischer Schaden',$variables);$crit=(float)($variables['Kritischer Schaden']??0);$result['adjustments']=['resistance_percent'=>$resistance,'defense'=>$defense,'sources'=>$auto['sources']];$result['damage']=(new CombatCalculator())->damage((float)$result['value'],$isCritical,$crit,$resistance,$defense,(float)($body['bonus']??0),(float)($body['penalty']??0),$isDamage);$result['damage']['critical_source']=actorCriticalSource($pdo,$actorId,$hasCriticalValue);}
        respond($result);
    }
    respond(['error'=>'Endpunkt nicht gefunden.'],404);
} catch (Throwable $e) {
    $decoded=json_decode($e->getMessage(),true);$message=is_array($decoded)?$decoded:['error'=>$e->getMessage()];respond($message,422);
}

