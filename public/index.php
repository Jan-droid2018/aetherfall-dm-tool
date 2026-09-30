<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Formula\FormulaEngine;
use Aetherfall\Repositories\CharacterRepository;
use Aetherfall\Repositories\ContentRepository;
use Aetherfall\Services\CombatCalculator;
use Aetherfall\Services\CombatValueParser;
use Aetherfall\Services\CombatProfileValueResolver;
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
    else{$s=$pdo->prepare('SELECT attribute_profiles_json,combat_values_json,phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$profile=$s->fetch()?:[];$profiles=json_decode((string)($profile['attribute_profiles_json']??'[]'),true)?:[];$phaseRows=(json_decode((string)($profile['phase_values_json']??'[]'),true)[0]['rows']??[]);$variables['Stufe']=(float)$p['selected_level'];$combatValues=json_decode((string)($profile['combat_values_json']??'[]'),true)?:[];$phase=max(1,(int)($p['selected_phase']??1));$variables['Kritischer Schaden']=(new CombatValueParser())->criticalDamagePercent($combatValues,$phase);$activeProfile=(new CombatProfileValueResolver())->applyPhaseOverride($profiles[$phase-1]??$profiles[0]??[],$phaseRows[$phase-1]??[]);foreach(($activeProfile['attributes']??[])as$r){$variables[$r['code'].'-Wert']=(float)$r['value'];$variables[$r['code'].'-Modifikator']=(float)$r['modifier'];$variables[$r['code'].'-Bonus']=(float)$r['bonus'];}}
    if(($p['participant_type']??'')==='character'){$cs=$pdo->prepare('SELECT cc.class_id,cc.class_level FROM character_classes cc WHERE cc.character_id=?');$cs->execute([(int)$p['reference_id']]);$levels=[];$name=$pdo->prepare('SELECT name FROM classes WHERE id=?');foreach($cs->fetchAll() as $cr){$name->execute([$cr['class_id']]);$className=$name->fetchColumn()?:$cr['class_id'];$levels[]=(int)$cr['class_level'];$variables[$className.'-Stufe']=(float)$cr['class_level'];$variables[$cr['class_id'].'-Stufe']=(float)$cr['class_level'];}$variables['Stufe']=(float)($levels?max($levels):($variables['Stufe']??1));}
    return array_filter($variables,static fn($v)=>$v!==null);
}
function actorCriticalSource(PDO $pdo,int $participantId,bool $hasCriticalValue):?string{
    if(!$hasCriticalValue)return null;
    $s=$pdo->prepare('SELECT participant_type FROM combat_participants WHERE id=?');$s->execute([$participantId]);
    return match($s->fetchColumn()){'character'=>'Charakter','creature'=>'Kreaturenprofil','boss'=>'Bossprofil',default=>null};
}
function targetVariables(PDO $pdo,int $participantId):array{
    $s=$pdo->prepare('SELECT participant_type,reference_id,selected_level FROM combat_participants WHERE id=?');$s->execute([$participantId]);$p=$s->fetch();if(!$p)return[];$rows=[];
    if($p['participant_type']==='character'){$s=$pdo->prepare('SELECT attribute_code code,modifier,bonus FROM character_attributes WHERE character_id=?');$s->execute([(int)$p['reference_id']]);$rows=$s->fetchAll();}
    elseif($p['participant_type']==='creature'){$s=$pdo->prepare('SELECT attributes_json FROM creature_levels WHERE creature_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$rows=json_decode((string)$s->fetchColumn(),true)?:[];}
    else{$s=$pdo->prepare('SELECT attribute_profiles_json,phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$profileRow=$s->fetch()?:[];$profiles=json_decode((string)($profileRow['attribute_profiles_json']??'[]'),true)?:[];$phaseRows=(json_decode((string)($profileRow['phase_values_json']??'[]'),true)[0]['rows']??[]);$phase=max(1,(int)($p['selected_phase']??1));$profile=(new CombatProfileValueResolver())->applyPhaseOverride($profiles[$phase-1]??$profiles[0]??[],$phaseRows[$phase-1]??[]);$rows=$profile['attributes']??[];}
    $variables=[];foreach($rows as $r){$code=$r['code']??$r['attribute_code']??null;if(!$code)continue;$mod=(float)($r['modifier']??0);$bonus=(float)($r['bonus']??0);$variables[$code.'-Modifikator des Ziels']=$mod;$variables[$code.'-Bonus des Ziels']=$bonus;}
    $auMod=$variables['AU-Modifikator des Ziels']??0;$auBonus=$variables['AU-Bonus des Ziels']??0;$variables['Ausweichen-Modifikator des Ziels']=$auMod;$variables['Ausweichen-Bonus des Ziels']=$auBonus;return$variables;
}
function normalizeCombatFormula(string $formula):string{
    $formula=preg_replace('/\b(ST|GE|BW|IN|WA|KR|CH|EM|WI|IT|AU)-Mod\.?\b/iu','$1-Modifikator',$formula)??$formula;
    return preg_replace('/\b(ST|GE|BW|IN|WA|KR|CH|EM|WI|IT|AU)-Bonus\.?\b/iu','$1-Bonus',$formula)??$formula;
}
function sourceDefinition(PDO $pdo,?string $sourceType,?string $sourceId):array{
    if(!$sourceType||!$sourceId)return[];
    $queries=['ability'=>'SELECT attack_roll,calculation,damage_type,type,effect, NULL rule_effect FROM abilities WHERE id=?','spell'=>'SELECT attack_roll,calculation,damage_type,effect_type type,rule_effect effect,rule_effect FROM spells WHERE id=?','creature_action'=>'SELECT attack_data attack_roll,damage_data calculation,damage_type,type,notes effect,NULL rule_effect FROM creature_actions WHERE id=?','boss_action'=>'SELECT attack_data attack_roll,NULL calculation,NULL damage_type,NULL type,notes effect,NULL rule_effect FROM boss_actions WHERE id=?'];
    if(!isset($queries[$sourceType]))return[];$s=$pdo->prepare($queries[$sourceType]);$s->execute([$sourceId]);return$s->fetch()?:[];
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
    $phaseColumn=$p['participant_type']==='boss'?',phase_values_json':'';$s=$pdo->prepare("SELECT physical_defense,magic_defense,elemental_resistances_json{$phaseColumn} FROM {$table} WHERE {$foreign}=? AND level=?");$s->execute([$p['reference_id'],$p['selected_level']]);$profile=$s->fetch();if(!$profile)return$result;
    if($p['participant_type']==='boss'&&$p['selected_phase']){$phaseRows=json_decode((string)$profile['phase_values_json'],true)[0]['rows']??[];$phaseRow=$phaseRows[(int)$p['selected_phase']-1]??[];if(isset($phaseRow['Phys-VTD']))$profile['physical_defense']=(float)str_replace(',','.',$phaseRow['Phys-VTD']);if(isset($phaseRow['Mag-VTD']))$profile['magic_defense']=(float)str_replace(',','.',$phaseRow['Mag-VTD']);}
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
    if(preg_match('#^encounters/(\d+)/participants/(\d+)/phase$#',$api,$m)&&$method==='PATCH'){$encounters->setBossPhase((int)$m[1],(int)$m[2],(string)($body['phase']??''));respond(['ok'=>true]);}
    if(preg_match('#^encounters/(\d+)/(next|previous)$#',$api,$m)&&$method==='POST'){ $encounters->moveTurn((int)$m[1],$m[2]==='next'?1:-1);respond(['ok'=>true]); }
    if(preg_match('#^encounters/(\d+)/apply-effect$#',$api,$m)&&$method==='POST')respond($encounters->applyEffect((int)$m[1],$body));
    if($api==='calculate'&&$method==='POST'){
        // Resistenz und Profilverteidigung stammen ausschließlich aus dem Zielprofil.
        $body['resistance_percent']='';
        $body['defense']='';
        $engine=new FormulaEngine();$source=sourceDefinition($pdo,isset($body['source_type'])?(string)$body['source_type']:null,isset($body['source_id'])?(string)$body['source_id']:null);$formula=(string)($body['formula']??'');if($formula===''&&$source)$formula=(string)($source['calculation']??'');if(($body['damage_type']??'')===''&&$source)$body['damage_type']=$source['damage_type']??null;$expression=$engine->extractMath($formula)??$formula;if(isset($body['effect_roll'])&&$body['effect_roll']!==''){preg_match_all('/\b(?:\d*)W\d+\b/iu',$expression,$diceMatches);$body['dice']=$body['dice']??[];foreach($diceMatches[0] as $notation){$normalized=strtoupper(str_replace('D','W',preg_replace('/\s+/','',$notation)));if(str_starts_with($normalized,'W'))$normalized='1'.$normalized;$body['dice'][$normalized]=(float)$body['effect_roll'];}}
        $mode=(string)($body['calculation_mode']??'damage');
        if(!in_array($mode,['damage','hit'],true))throw new RuntimeException('Unbekannter Berechnungsmodus.');
        $actorId=(int)($body['actor_participant_id']??0);$targetId=(int)($body['target_participant_id']??0);$actorType='';if($actorId){$actorStmt=$pdo->prepare('SELECT participant_type FROM combat_participants WHERE id=?');$actorStmt->execute([$actorId]);$actorType=(string)$actorStmt->fetchColumn();}$variables=actorVariables($pdo,$actorId,$body['magic_attribute']??null);
        if(($targetId!==0)&&(($body['damage_type']??'')!=='')){$automaticTarget=targetAdjustments($pdo,$targetId,(string)$body['damage_type']);}
        $variables=array_merge($variables,$body['manual_variables']??[]);
        $result=$engine->evaluate($expression,['variables'=>$variables,'dice'=>$body['dice']??[]]);
        $result['original_text']=$formula;$result['expression']=$expression;$effectText=trim((string)($source['type']??'').' '.(string)($source['effect']??'').' '.(string)($source['rule_effect']??''));$result['effect_type']=preg_match('/schild|barriere|schutz|schadensreduktion|schadensabsorption/iu',$effectText)?'shield':(preg_match('/heil|regeneration/iu',$effectText)?'healing':(preg_match('/schaden|angriff/iu',$effectText)?'damage':'other'));
        $attackFormula=trim((string)($source['attack_roll']??''));$attackRequired=$mode==='hit'&&$actorType==='character'&&$attackFormula!==''&&$attackFormula!=='-'&&preg_match('/(?:W20|normal(?:er|en)?\s+(?:Waffen[- ]?)?angriff|Waffenschaden)/iu',$attackFormula);$attackHit=null;
        if($mode==='hit'&&$actorType!=='character')throw new RuntimeException('Trefferprüfung ist nur für Spielercharaktere verfügbar.');
        if($mode==='hit'&&!$attackRequired)throw new RuntimeException('Für diese Fähigkeit ist keine Trefferformel hinterlegt.');
        if($attackRequired){$attackExpression=$engine->extractMath($attackFormula)??'W20';$attackDice=[];$hitRoll=$body['hit_roll']??'';$attack=[];$targetVars=$targetId?targetVariables($pdo,$targetId):[];$needsTargetRoll=(bool)preg_match('/gegen\s+(?:den\s+normalen\s+)?(?:W20|Ausweichwurf)/iu',$attackFormula);$targetRoll=$body['target_roll']??'';if($hitRoll!==''){$attackDice['W20']=(float)$hitRoll;$attack=$engine->evaluate($attackExpression,['variables'=>$variables,'dice'=>$attackDice]);}$attack['expression']=$attackExpression;$targetText=preg_replace('/^.*?\s+gegen\s+/iu','',$attackFormula)??'';if($needsTargetRoll&&$targetRoll!==''){$targetExpression=$engine->extractMath($targetText)??'W20 + Ausweichen-Modifikator des Ziels + Ausweichen-Bonus des Ziels';$target=$engine->evaluate($targetExpression,['variables'=>$targetVars,'dice'=>['W20'=>(float)$targetRoll]]);$attack['target']=$target;$attack['target_total']=$target['value'];}elseif(!$needsTargetRoll){$target=$targetId?targetAdjustments($pdo,$targetId,$body['damage_type']??null):['defense'=>0];$attack['target_total']=$target['defense'];}if($hitRoll!==''){$attack['value']=$attack['value']??null;$attackHit=($attack['value']!==null&&isset($attack['target_total']))?($attack['value']>=(float)$attack['target_total']):null;$attack['hit']=$attackHit;}else{$attack['value']=null;$attack['hit']=null;}$result['attack']=$attack;}
        if($mode==='hit'){respond(['supported'=>true,'attack'=>$result['attack']??['value'=>null,'hit'=>null],'original_text'=>$attackFormula,'expression'=>$attackFormula]);}
        if($result['value']!==null){if(array_key_exists('critical',$body)&&!is_bool($body['critical']))throw new RuntimeException('Der Krit-Schalter muss ein eindeutiger Boolean sein.');$isCritical=$body['critical']??false;$isDamage=actorSourceIsDamage($pdo,$actorId,isset($body['source_type'])?(string)$body['source_type']:null,isset($body['source_id'])?(string)$body['source_id']:null);$auto=$isDamage?targetAdjustments($pdo,$targetId,$body['damage_type']??null):['resistance'=>0.0,'defense'=>0.0,'sources'=>[]];$resistance=$auto['resistance'];$defense=$auto['defense'];$hasCriticalValue=array_key_exists('Kritischer Schaden',$variables);$crit=(float)($variables['Kritischer Schaden']??0);$result['adjustments']=['resistance_percent'=>$resistance,'defense'=>$defense,'sources'=>$auto['sources']];$normalValue=(float)$result['value'];$result['damage']=(new CombatCalculator())->damage($normalValue,$isCritical,$crit,$resistance,$defense,0,0,$isDamage);$result['damage']['critical_source']=actorCriticalSource($pdo,$actorId,$hasCriticalValue);}
        respond($result);
    }
    respond(['error'=>'Endpunkt nicht gefunden.'],404);
} catch (Throwable $e) {
    $decoded=json_decode($e->getMessage(),true);$message=is_array($decoded)?$decoded:['error'=>$e->getMessage()];respond($message,422);
}

