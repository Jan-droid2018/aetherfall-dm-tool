<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Formula\FormulaEngine;
use Aetherfall\Repositories\CharacterRepository;
use Aetherfall\Repositories\ContentRepository;
use Aetherfall\Repositories\WeaponRepository;
use Aetherfall\Repositories\ArmorRepository;
use Aetherfall\Repositories\MagicFocusRepository;
use Aetherfall\Services\CombatCalculator;
use Aetherfall\Services\CombatValueParser;
use Aetherfall\Services\CombatProfileValueResolver;
use Aetherfall\Services\CombatEffectResolver;
use Aetherfall\Services\EncounterService;
use Aetherfall\Services\CharacterWeaponService;
use Aetherfall\Services\ClassResourceService;
use Aetherfall\Services\CharacterEquipmentService;
use Aetherfall\Services\WeaponCombatResolver;
use Aetherfall\Services\MagicFocusService;
use Aetherfall\Support\Database;

$api = trim((string)($_GET['api'] ?? ''), '/');
if ($api === '') {
    require __DIR__ . '/view.php';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$pdo = Database::connection();
$content = new ContentRepository($pdo);
$weapons = new WeaponRepository($pdo);
$armors = new ArmorRepository($pdo);
$magicFoci = new MagicFocusRepository($pdo);
$characterWeapons = new CharacterWeaponService($pdo, $weapons);
$characters = new CharacterRepository($pdo);
$characterEquipment = new CharacterEquipmentService($pdo);
$encounters = new EncounterService($pdo);
$resources = new ClassResourceService($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];

function respond(mixed $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function actorVariables(PDO $pdo, int $participantId, ?string $magicAttribute): array {
    $s=$pdo->prepare('SELECT * FROM combat_participants WHERE id=?');$s->execute([$participantId]);$p=$s->fetch();if(!$p)return[];$variables=[];
    if($p['participant_type']==='character'){$s=$pdo->prepare('SELECT level,critical_damage_percent FROM characters WHERE id=?');$s->execute([(int)$p['reference_id']]);$character=$s->fetch();if(!$character)return[];$variables['Stufe']=(float)$character['level'];$variables['Kritischer Schaden']=(float)$character['critical_damage_percent'];$s=$pdo->prepare('SELECT attribute_code,value,modifier,bonus FROM character_attributes WHERE character_id=?');$s->execute([(int)$p['reference_id']]);$attributeNames=MagicFocusService::attributeNames();foreach($s->fetchAll() as $r){$code=(string)$r['attribute_code'];$mod=(float)$r['modifier'];$bonus=(float)$r['bonus'];$variables[$code.'-Wert']=(float)$r['value'];$variables[$code.'-Modifikator']=$mod;$variables[$code.'-Bonus']=$bonus;if(isset($attributeNames[$code])){$label=$attributeNames[$code];$variables[$label.'-Wert']=(float)$r['value'];$variables[$label.'-Modifikator']=$mod;$variables[$label.'-Bonus']=$bonus;}}if($magicAttribute){$magicCode=MagicFocusService::attributeCode($magicAttribute);if($magicCode){$variables['Magieattribut-Modifikator']=$variables[$magicCode.'-Modifikator']??null;$variables['Magieattribut-Bonus']=$variables[$magicCode.'-Bonus']??null;}}}
    elseif($p['participant_type']==='creature'){$s=$pdo->prepare('SELECT attributes_json,combat_values_json FROM creature_levels WHERE creature_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$profile=$s->fetch();if(!$profile)return[];$rows=json_decode((string)$profile['attributes_json'],true)?:[];$variables['Stufe']=(float)$p['selected_level'];$variables['Kritischer Schaden']=(new CombatValueParser())->criticalDamagePercent((string)$profile['combat_values_json']);foreach($rows as $r){$variables[$r['code'].'-Wert']=(float)$r['value'];$variables[$r['code'].'-Modifikator']=(float)$r['modifier'];$variables[$r['code'].'-Bonus']=(float)$r['bonus'];}}
    else{$s=$pdo->prepare('SELECT attribute_profiles_json,combat_values_json,phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$profile=$s->fetch()?:[];$profiles=json_decode((string)($profile['attribute_profiles_json']??'[]'),true)?:[];$phaseRows=(json_decode((string)($profile['phase_values_json']??'[]'),true)[0]['rows']??[]);$variables['Stufe']=(float)$p['selected_level'];$combatValues=json_decode((string)($profile['combat_values_json']??'[]'),true)?:[];$phase=max(1,(int)($p['selected_phase']??1));$variables['Kritischer Schaden']=(new CombatValueParser())->criticalDamagePercent($combatValues,$phase);$activeProfile=(new CombatProfileValueResolver())->applyPhaseOverride($profiles[$phase-1]??$profiles[0]??[],$phaseRows[$phase-1]??[]);foreach(($activeProfile['attributes']??[])as$r){$variables[$r['code'].'-Wert']=(float)$r['value'];$variables[$r['code'].'-Modifikator']=(float)$r['modifier'];$variables[$r['code'].'-Bonus']=(float)$r['bonus'];}}
    if(($p['participant_type']??'')==='character'){$cs=$pdo->prepare('SELECT cc.class_id,cc.class_level FROM character_classes cc WHERE cc.character_id=?');$cs->execute([(int)$p['reference_id']]);$levels=[];$name=$pdo->prepare('SELECT name FROM classes WHERE id=?');foreach($cs->fetchAll() as $cr){$name->execute([$cr['class_id']]);$className=$name->fetchColumn()?:$cr['class_id'];$levels[]=(int)$cr['class_level'];$variables[$className.'-Stufe']=(float)$cr['class_level'];$variables[$cr['class_id'].'-Stufe']=(float)$cr['class_level'];if((string)$cr['class_id']==='taktbrecher'){$level=(int)$cr['class_level'];$variables['Taktpräzision']=(float)($level<=4?0:($level<=14?1:($level<=19?2:($level<=29?3:($level<=34?4:($level<=39?5:6))))));}}$variables['Stufe']=(float)($levels?max($levels):($variables['Stufe']??1));}
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
    $queries=['ability'=>'SELECT class_id,attack_roll,calculation,damage_type,type,effect, NULL rule_effect,costs resource_cost,class_resource,raw_json FROM abilities WHERE id=?','spell'=>'SELECT NULL class_id,attack_roll,calculation,damage_type,effect_type type,rule_effect effect,rule_effect,NULL resource_cost,NULL class_resource,raw_json FROM spells WHERE id=?','creature_action'=>'SELECT NULL class_id,attack_data attack_roll,damage_data calculation,damage_type,type,notes effect,NULL rule_effect,NULL resource_cost,NULL class_resource,raw_json FROM creature_actions WHERE id=?','boss_action'=>'SELECT NULL class_id,attack_data attack_roll,NULL calculation,NULL damage_type,NULL type,notes effect,NULL rule_effect,NULL resource_cost,NULL class_resource,raw_json FROM boss_actions WHERE id=?','class_action'=>'SELECT class_id,attack_formula attack_roll,damage_formula calculation,damage_type,action_type type,description effect,resource_cost,resource_gain class_resource,raw_json FROM class_actions WHERE id=?','class_weapon'=>'SELECT class_id,attack_formula attack_roll,damage_formula calculation,damage_type,\'class_weapon\' type,description effect,NULL resource_cost,NULL class_resource,raw_json FROM class_weapon_profiles WHERE id=?'];
    if(!isset($queries[$sourceType]))return[];$s=$pdo->prepare($queries[$sourceType]);$s->execute([$sourceId]);return$s->fetch()?:[];
}
function bridgeWeaponDamage(string $calculation, string $weaponDamage): string {
    $calculation=trim($calculation);
    if($calculation==='')return$weaponDamage;
    if(preg_match('/normal(?:er|en)?\s+waffenschaden/iu',$calculation))return preg_replace('/normal(?:er|en)?\s+waffenschaden/iu','('.$weaponDamage.')',$calculation,1)??$weaponDamage;
    return$calculation;
}
function splitSecondaryEffect(string $calculation): array {
    if (preg_match('/\b(Schutzwert|Schildwert|Barrierewert|Heilung)\s+danach\s*=\s*(.+)$/isu', $calculation, $m, PREG_OFFSET_CAPTURE)) {
        $offset=(int)$m[0][1];
        return ['main'=>rtrim(substr($calculation,0,$offset), " .;\t\r\n"), 'effect'=>trim((string)$m[2][0]), 'type'=>mb_strtolower((string)$m[1][0])==='heilung'?'healing':'shield'];
    }
    return ['main'=>$calculation,'effect'=>null,'type'=>null];
}
function rollDiceContext(string $expression,array $body,?array $weaponContext=null): array {
    $dice=[];$rolls=is_array($body['rolls']??null)?$body['rolls']:[];$legacy=$body['effect_roll']??null;
    $weaponRoll=$rolls['weapon_damage']??$rolls['damage']??$legacy;
    $effectRoll=$rolls['effect_1']??$rolls['effect']??$legacy;
    preg_match_all('/\b(?:\d*)W\d+\b/iu',$expression,$matches);$notations=[];foreach($matches[0] as $notation){$key=strtoupper(str_replace('D','W',preg_replace('/\s+/','',$notation)));if(str_starts_with($key,'W'))$key='1'.$key;if(!in_array($key,$notations,true))$notations[]=$key;}
    $weaponDice=[];if($weaponContext) {preg_match_all('/\b(?:\d*)W\d+\b/iu',(string)($weaponContext['damage_formula']??''),$wm);foreach($wm[0] as $notation){$key=strtoupper(str_replace('D','W',preg_replace('/\s+/','',$notation)));if(str_starts_with($key,'W'))$key='1'.$key;$weaponDice[]=$key;}}
    foreach($notations as $index=>$key){$value=null;if($weaponRoll!==null&&$weaponRoll!==''){if(!$weaponDice||in_array($key,$weaponDice,true))$value=$weaponRoll;}if($value===null&&$effectRoll!==null&&$effectRoll!=='')$value=$effectRoll;if($value!==null)$dice[$key]=(float)$value;}
    return$dice;
}
function effectDiceContext(string $expression,array $effect,array $body,?array $weaponContext=null): array {
    $rolls=is_array($body['rolls']??null)?$body['rolls']:[];$key=(string)($effect['roll_key']??'effect');
    if($key==='shield'||$key==='healing'){$value=$rolls[$key]??($body[$key.'_roll']??'');return rollDiceContext($expression,['rolls'=>['effect_1'=>$value],'effect_roll'=>$value],null);}
    if($key==='weapon_damage')return rollDiceContext($expression,$body,$weaponContext);
    $value=$rolls[$key]??($rolls['effect_1']??($body['effect_roll']??''));return rollDiceContext($expression,['rolls'=>['effect_1'=>$value],'effect_roll'=>$value],null);
}
function actorSourceIsDamage(PDO $pdo,int $participantId,?string $sourceType,?string $sourceId,?string $weaponSlot=null):bool{
    if(!$sourceType||!$sourceId)return true;
    $s=$pdo->prepare('SELECT participant_type,reference_id,selected_level FROM combat_participants WHERE id=?');$s->execute([$participantId]);$participant=$s->fetch();if(!$participant)throw new RuntimeException('Aktiver Teilnehmer nicht gefunden.');
    if($participant['participant_type']!=='character')return true;
    if($sourceType==='weapon_attack'){$s=$pdo->prepare('SELECT w.damage_type,\'Waffenangriff\' type FROM character_weapon_slots cws JOIN weapons w ON w.id=cws.weapon_id WHERE cws.character_id=? AND cws.slot=?');$s->execute([(int)$participant['reference_id'],$weaponSlot]);}
    elseif($sourceType==='ability'){$s=$pdo->prepare("SELECT a.type,a.damage_type FROM abilities a WHERE a.id=? AND (EXISTS (SELECT 1 FROM character_abilities ca WHERE ca.character_id=? AND ca.ability_id=a.id) OR EXISTS (SELECT 1 FROM character_classes cc WHERE cc.character_id=? AND cc.class_id=a.class_id AND (LOWER(cc.class_id)='ursprungsvermaechtnis' OR LOWER(cc.class_id) LIKE '%ursprungsvermaechtnis%') AND a.unlock_level<=cc.class_level))");$s->execute([$sourceId,(int)$participant['reference_id'],(int)$participant['reference_id']]);}
    elseif($sourceType==='spell'){$s=$pdo->prepare('SELECT s.effect_type type,s.damage_type FROM character_spells cs JOIN spells s ON s.id=cs.spell_id WHERE cs.character_id=? AND s.id=?');$s->execute([(int)$participant['reference_id'],$sourceId]);}
    elseif($sourceType==='class_action'){$s=$pdo->prepare('SELECT ca.damage_type,ca.action_type type FROM character_classes cc JOIN class_actions ca ON ca.class_id=cc.class_id WHERE cc.character_id=? AND ca.id=? AND ca.unlock_level<=cc.class_level');$s->execute([(int)$participant['reference_id'],$sourceId]);}
    elseif($sourceType==='class_weapon'){$s=$pdo->prepare('SELECT cw.damage_type,\'class_weapon\' type FROM character_classes cc JOIN class_weapon_profiles cw ON cw.class_id=cc.class_id WHERE cc.character_id=? AND cw.id=? AND cw.unlock_level<=cc.class_level');$s->execute([(int)$participant['reference_id'],$sourceId]);}
    else throw new RuntimeException('Ungültige Charakterquelle für die Berechnung.');
    $source=$s->fetch();if(!$source)throw new RuntimeException('Die gewählte Fähigkeit oder der Zauber ist dem Charakter nicht zugeordnet.');
    return trim((string)($source['damage_type']??''))!==''||mb_stripos((string)($source['type']??''),'Schaden')!==false;
}
function targetAdjustments(PDO $pdo, int $participantId, ?string $damageType): array {
    if (!$participantId || !$damageType) return ['resistance'=>0.0,'defense'=>0.0,'sources'=>[]];
    $resolved = (new \Aetherfall\Services\TargetDefenseResolver($pdo))->resolve($participantId, $damageType);
    return [
        'resistance' => (float)($resolved['resistance'] ?? 0),
        'defense' => (float)($resolved['defense'] ?? 0),
        'sources' => $resolved['sources'] ?? [],
        'category' => $resolved['category'] ?? \Aetherfall\Services\DamageTypeClassifier::UNKNOWN,
        'physical_defense' => (float)($resolved['physical_defense'] ?? 0),
        'magical_defense' => (float)($resolved['magical_defense'] ?? 0),
        'defense_breakdown' => $resolved['breakdown'] ?? [],
    ];
}

function calculateCombatRequest(PDO $pdo,array $body,ClassResourceService $resources): array {
    $body['resistance_percent']='';$body['defense']='';$engine=new FormulaEngine();
    $sourceType=isset($body['source_type'])?(string)$body['source_type']:null;$sourceId=isset($body['source_id'])?(string)$body['source_id']:null;$actorId=(int)($body['actor_participant_id']??0);$targetId=(int)($body['target_participant_id']??0);$weaponSlot=trim((string)($body['weapon_slot']??''));$weaponProfile=trim((string)($body['weapon_profile']??''));
    $actorStmt=$pdo->prepare('SELECT participant_type,reference_id FROM combat_participants WHERE id=?');$actorStmt->execute([$actorId]);$actor=$actorStmt->fetch()?:[];$actorType=(string)($actor['participant_type']??'');$weaponContext=null;$weapon=null;
    if($sourceType==='weapon_attack'){
        $resolver=new WeaponCombatResolver($pdo);if($actorType!=='character')throw new RuntimeException('Waffenangriffe sind nur für Spielercharaktere verfügbar.');if(!in_array($weaponSlot,['hand_1','hand_2'],true))throw new RuntimeException('Bitte einen gültigen Waffenslot auswählen.');$weapon=$resolver->equipped((int)$actor['reference_id'],$weaponSlot);if(!$weapon||($sourceId!==''&&$sourceId!==(string)$weapon['id']))throw new RuntimeException('Die gewählte Waffe ist in diesem Waffenslot nicht mehr ausgerüstet.');$weaponContext=$resolver->context($weapon,null,$weaponProfile!==''?$weaponProfile:null);$source=['attack_roll'=>$weaponContext['attack_formula'],'calculation'=>$weaponContext['damage_formula'],'damage_type'=>$weaponContext['damage_type'],'type'=>'Waffenangriff','effect'=>'Normaler Waffenangriff','resource_cost'=>null,'class_resource'=>null];
    } else $source=sourceDefinition($pdo,$sourceType,$sourceId);
    if(!$source&&$sourceType!==null)throw new RuntimeException('Die gewählte Combat-Quelle wurde nicht gefunden.');
    if($sourceType==='spell'&&$actorType==='character'){
        $owned=$pdo->prepare('SELECT 1 FROM character_spells WHERE character_id=? AND spell_id=?');
        $owned->execute([(int)$actor['reference_id'],$sourceId]);
        if(!$owned->fetchColumn())throw new RuntimeException('Der gewählte Zauber ist diesem Charakter nicht zugeordnet.');
    }
    $originalSource=$source;
    $meta=$source?WeaponCombatResolver::classify($source):[];
    if($sourceType!=='weapon_attack'&&$actorType==='character'&&$source&&($meta['requires_weapon']??false)){
        $resolver=new WeaponCombatResolver($pdo);if(!in_array($weaponSlot,['hand_1','hand_2'],true))throw new RuntimeException('Diese Fähigkeit benötigt eine ausgerüstete Waffe.');$weapon=$resolver->equipped((int)$actor['reference_id'],$weaponSlot);if(!$weapon)throw new RuntimeException('Diese Fähigkeit benötigt eine ausgerüstete Waffe.');$override=preg_match('/Willenskraft\s+anstelle/iu',(string)($source['attack_roll']??'').' '.(string)($source['calculation']??''))?'Willenskraft':null;$weaponContext=$resolver->context($weapon,$override,$weaponProfile!==''?$weaponProfile:null);if($meta['uses_normal_weapon_attack'])$source['attack_roll']=$weaponContext['attack_formula'];$source['damage_type']=$source['damage_type']?:$weaponContext['damage_type'];
    }
    $effects=$sourceType==='weapon_attack'?[['key'=>'weapon_damage','type'=>'damage','source'=>'weapon','condition'=>'always','formula'=>(string)($weaponContext['damage_formula']??''),'roll_key'=>'weapon_damage','label'=>'Waffenschadenswürfel']]:CombatEffectResolver::describe($originalSource,$weaponContext);
    if(!$effects&&!empty($body['formula']))$effects=[['key'=>'damage','type'=>'damage','source'=>'ability','condition'=>'always','formula'=>(string)$body['formula'],'roll_key'=>'damage','label'=>'Schadenswürfel']];
    $body['damage_type']=$weaponContext&&(($meta['uses_normal_weapon_damage']??false)||$sourceType==='weapon_attack')?($weaponContext['damage_type']??($source['damage_type']??null)):($source['damage_type']??($body['damage_type']??null));
    $mode=(string)($body['calculation_mode']??'damage');if(!in_array($mode,['damage','hit'],true))throw new RuntimeException('Unbekannter Berechnungsmodus.');
    $magicFocusContext=null;
    $variables=actorVariables($pdo,$actorId,$sourceType==='spell'?null:($body['magic_attribute']??null));
    if($sourceType==='spell'&&$actorType==='character'){
        $magicFocusService=new MagicFocusService($pdo);
        $magicFocusContext=$magicFocusService->getMagicAttribute((int)$actor['reference_id']);
        $spellFormula=implode(' ',[(string)($source['attack_roll']??''),(string)($source['calculation']??''),(string)($source['effect']??''),(string)($source['rule_effect']??'')]);
        if(MagicFocusService::formulaNeedsMagicAttribute($spellFormula)){
            if(!$magicFocusContext)throw new RuntimeException('Für das Wirken dieses Zaubers ist ein ausgerüsteter Magiefokus erforderlich.');
            $focusVariables=$magicFocusService->formulaVariables((int)$actor['reference_id']);
            if(!$focusVariables)throw new RuntimeException('Das Magieattribut des ausgerüsteten Magiefokus konnte nicht aufgelöst werden.');
            $variables=array_merge($variables,$focusVariables);
        }
    }
    $resultLocal=[];if($weapon&&$weaponContext){$resultLocal=(new WeaponCombatResolver($pdo))->localVariables($weapon,$variables);$variables=array_merge($variables,$resultLocal);}$variables=array_merge($variables,$sourceType==='spell'?[]:($body['manual_variables']??[]));
    $attackFormula=trim((string)($source['attack_roll']??''));$attackRequired=$actorType==='character'&&$attackFormula!==''&&$attackFormula!=='-'&&preg_match('/(?:W20|normal(?:er|en)?\s+(?:Waffen[- ]?)?angriff|Waffenschaden|angriffswurf)/iu',$attackFormula);$attack=null;
    $resolveAttack=function()use(&$attack,$attackRequired,$attackFormula,$body,$engine,$variables,$targetId,$weaponContext,$pdo):array{
        if(!$attackRequired)return[];$attackExpression=$engine->extractMath($attackFormula)??'W20';$attackDice=[];$hitRoll=$body['hit_roll']??($body['rolls']['hit']??'');$attack=[];$targetVars=$targetId?targetVariables($pdo,$targetId):[];$needsTargetRoll=(bool)preg_match('/gegen\s+(?:den\s+normalen\s+)?(?:W20|Ausweichwurf)/iu',$attackFormula);$targetRoll=$body['target_roll']??'';if($hitRoll!==''){$attackDice['W20']=(float)$hitRoll;$attack=$engine->evaluate($attackExpression,['variables'=>$variables,'dice'=>$attackDice]);}$attack['expression']=$attackExpression;$targetText=preg_replace('/^.*?\s+gegen\s+/iu','',$attackFormula)??'';if($needsTargetRoll&&$targetRoll!==''){$targetExpression=$engine->extractMath($targetText)??'W20 + Ausweichen-Modifikator des Ziels + Ausweichen-Bonus des Ziels';$target=$engine->evaluate($targetExpression,['variables'=>$targetVars,'dice'=>['W20'=>(float)$targetRoll]]);$attack['target']=$target;$attack['target_total']=$target['value'];}elseif($weaponContext&&$targetId)$attack['target_total']=($targetVars['AU-Modifikator des Ziels']??0)+($targetVars['AU-Bonus des Ziels']??0);elseif(!$needsTargetRoll){$target=$targetId?targetAdjustments($pdo,$targetId,$body['damage_type']??null):['defense'=>0];$attack['target_total']=$target['defense'];}if($hitRoll!==''){$attack['value']=$attack['value']??null;$attack['hit']=($attack['value']!==null&&isset($attack['target_total']))?($attack['value']>=(float)$attack['target_total']):null;}else{$attack['value']=null;$attack['hit']=null;}return$attack;
    };
    $attack=$resolveAttack();if($mode==='hit'){if($actorType!=='character')throw new RuntimeException('Trefferprüfung ist nur für Spielercharaktere verfügbar.');if(!$attackRequired)throw new RuntimeException('Für diese Fähigkeit ist keine Trefferformel hinterlegt.');return['supported'=>true,'attack'=>$attack,'original_text'=>$attackFormula,'expression'=>$attackFormula,'weapon'=>$weaponContext,'magic_attribute'=>$magicFocusContext];}
    $resolvedEffects=[];$damageEvaluation=null;$firstEvaluation=null;$allMissing=[];$allSupported=true;
    foreach($effects as $effect){$formula=(string)($effect['formula']??'');$expression=$engine->extractMath($formula)??$formula;$dice=effectDiceContext($expression,$effect,$body,$weaponContext);$evaluation=$engine->evaluate($expression,['variables'=>$variables,'dice'=>$dice]);/* Hit rolls are a preview only. The DM confirms a hit separately, so they never suppress damage or a secondary effect. */$available=true;$value=$evaluation['value'];$row=$effect;$row['formula']=$expression;$row['value']=$value;$row['supported']=$evaluation['supported'];$row['missing']=$evaluation['missing']??[];$row['condition']=$effect['condition']??'always';$resolvedEffects[]=$row;if($firstEvaluation===null)$firstEvaluation=$evaluation;if($effect['type']==='damage'&&$damageEvaluation===null)$damageEvaluation=$evaluation;if(!$evaluation['supported']||$evaluation['value']===null){$allSupported=false;$allMissing=array_merge($allMissing,$evaluation['missing']??[]);}}
    $base=$damageEvaluation??$firstEvaluation??['supported'=>false,'value'=>null,'missing'=>['Schadensformel']];$result=$base;$result['supported']=$allSupported&&($base['supported']??false);$result['missing']=array_values(array_unique(array_merge($allMissing,$base['missing']??[])));$result['effects']=$resolvedEffects;$result['attack']=$attack;$result['effect_type']=$damageEvaluation?'damage':(($resolvedEffects[0]['type']??'other'));
    $result['original_text']=$damageEvaluation['formula']??($resolvedEffects[0]['formula']??'');$result['expression']=$result['original_text'];
    if($damageEvaluation&&$damageEvaluation['value']!==null){if(array_key_exists('critical',$body)&&!is_bool($body['critical']))throw new RuntimeException('Der Krit-Schalter muss ein eindeutiger Boolean sein.');$isCritical=$body['critical']??false;$isDamage=actorSourceIsDamage($pdo,$actorId,$sourceType,$sourceId,$weaponSlot);$auto=$isDamage?targetAdjustments($pdo,$targetId,$body['damage_type']??null):['resistance'=>0.0,'defense'=>0.0,'sources'=>[]];$hasCriticalValue=array_key_exists('Kritischer Schaden',$variables);$crit=(float)($variables['Kritischer Schaden']??0);$result['adjustments']=['resistance_percent'=>$auto['resistance'],'defense'=>$auto['defense'],'category'=>$auto['category']??null,'physical_defense'=>$auto['physical_defense']??0,'magical_defense'=>$auto['magical_defense']??0,'defense_breakdown'=>$auto['defense_breakdown']??[],'sources'=>$auto['sources']];$result['damage']=(new CombatCalculator())->damage((float)$damageEvaluation['value'],$isCritical,$crit,$auto['resistance'],$auto['defense'],0,0,$isDamage);$result['damage']['critical_source']=actorCriticalSource($pdo,$actorId,$hasCriticalValue);}
    $classId=isset($source['class_id'])?(string)$source['class_id']:null;$result['execution_id']=bin2hex(random_bytes(12));$result['resource_costs']=$resources->parseCosts($source['resource_cost']??null,null,$classId);$result['resource_gains']=$resources->parseGains($source['class_resource']??null,$classId);$result['weapon']=$weaponContext;$result['magic_attribute']=$magicFocusContext;if($resultLocal)$result['weapon_local_variables']=$resultLocal;return$result;
}

try {
    if ($api === 'stats' && $method === 'GET') respond($content->stats());
    if ($api === 'classes' && $method === 'GET') respond($content->classes());
    if (preg_match('#^classes/([^/]+)/resources$#', $api, $m) && $method === 'GET') respond($content->classResources($m[1]));
    if (preg_match('#^classes/([^/]+)/abilities$#', $api, $m) && $method === 'GET') respond($content->abilities($m[1], (int)($_GET['level'] ?? 999)));
    if ($api === 'spells' && $method === 'GET') respond($content->spells($_GET));
    if ($api === 'weapons' && $method === 'GET') respond($weapons->all($_GET));
    if (preg_match('#^weapons/([^/]+)$#', $api, $m) && $method === 'GET') {
        $weapon = $weapons->find($m[1]);
        $weapon ? respond($weapon) : respond(['error' => 'Waffe nicht gefunden.'], 404);
    }
    if ($api === 'armors' && $method === 'GET') respond($armors->all($_GET));
    if (preg_match('#^armors/([^/]+)$#', $api, $m) && $method === 'GET') {
        $armor = $armors->find($m[1], true); $armor ? respond($armor) : respond(['error' => 'Rüstung nicht gefunden.'], 404);
    }
    if ($api === 'magic-foci' && $method === 'GET') respond($magicFoci->all($_GET));
    if (preg_match('#^magic-foci/([^/]+)$#', $api, $m) && $method === 'GET') {
        $focus = $magicFoci->find($m[1]); $focus ? respond($focus) : respond(['error' => 'Magiefokus nicht gefunden.'], 404);
    }
    if ($api === 'creatures' && $method === 'GET') respond($content->creatures());
    if ($api === 'bosses' && $method === 'GET') respond($content->bosses());
    if ($api === 'characters' && $method === 'GET') respond($characters->all());
    if ($api === 'characters' && $method === 'POST') { $id=$characters->save($body); respond($characters->find($id),201); }
    if (preg_match('#^characters/(\d+)/weapons/(hand_1|hand_2)$#', $api, $m)) {
        $characterId = (int)$m[1];
        if (!$characters->find($characterId)) respond(['error' => 'Charakter nicht gefunden.'], 404);
        if ($method === 'PUT' || $method === 'PATCH') {
            $weaponId = trim((string)($body['weapon_id'] ?? ''));
            if ($weaponId === '') respond(['error' => 'weapon_id ist erforderlich.'], 422);
            $characterWeapons->setSlot($characterId, $m[2], $weaponId);
            respond($characterWeapons->slots($characterId));
        }
        if ($method === 'DELETE') {
            $characterWeapons->clearSlot($characterId, $m[2]);
            respond($characterWeapons->slots($characterId));
        }
    }
    if (preg_match('#^characters/(\d+)/armor/(head|chest|hands|legs|feet)$#', $api, $m)) {
        $characterId=(int)$m[1]; $slot=$m[2];
        if ($method==='PUT'||$method==='PATCH') { $armorId=trim((string)($body['armor_id']??'')); if($armorId==='')respond(['error'=>'armor_id ist erforderlich.'],422); respond($characterEquipment->setArmorSlot($characterId,$slot,$armorId)); }
        if ($method==='DELETE') { $characterEquipment->clearArmorSlot($characterId,$slot); respond($characterEquipment->armorSlots($characterId)); }
    }
    if (preg_match('#^characters/(\d+)/magic-focus$#', $api, $m)) {
        $characterId=(int)$m[1];
        if ($method==='PUT'||$method==='PATCH') { $focusId=trim((string)($body['magic_focus_id']??'')); if($focusId==='')respond(['error'=>'magic_focus_id ist erforderlich.'],422); respond($characterEquipment->setMagicFocus($characterId,$focusId)); }
        if ($method==='DELETE') { $characterEquipment->clearMagicFocus($characterId); respond(['magic_focus'=>null]); }
    }
    if (preg_match('#^characters/(\d+)$#',$api,$m)) {
        $id=(int)$m[1]; if($method==='GET'){ $row=$characters->find($id); $row?respond($row):respond(['error'=>'Charakter nicht gefunden.'],404); }
        if($method==='PUT'){ $characters->save($body,$id); respond($characters->find($id)); }
        if($method==='DELETE'){ $characters->delete($id); respond(['ok'=>true]); }
    }
    if (preg_match('#^characters/(\d+)/resources/([^/]+)$#',$api,$m)&&($method==='PATCH'||$method==='PUT')) {
        $row=$resources->setCharacterCurrent((int)$m[1],$m[2],(float)($body['value']??$body['current']??0),isset($body['class_id'])?(string)$body['class_id']:null,isset($body['character_class_id'])?(int)$body['character_class_id']:null);respond($row);
    }
    if($api==='encounters'&&$method==='GET')respond($encounters->all());
    if($api==='encounters'&&$method==='POST')respond(['id'=>$encounters->create($body['name']??null)],201);
    if(preg_match('#^encounters/(\d+)$#',$api,$m)){
        if($method==='GET'){ $row=$encounters->find((int)$m[1]);$row?respond($row):respond(['error'=>'Kampf nicht gefunden.'],404); }
        if($method==='DELETE'){ $encounters->delete((int)$m[1]);respond(['ok'=>true]); }
    }
    if(preg_match('#^encounters/(\d+)/participants$#',$api,$m)&&$method==='POST')respond(['id'=>$encounters->addParticipant((int)$m[1],$body)],201);
    if(preg_match('#^encounters/(\d+)/participants/(\d+)/hp$#',$api,$m)&&($method==='PATCH'||$method==='PUT'))respond($encounters->setParticipantHp((int)$m[1],(int)$m[2],$body['current_hp']??$body['hp']??$body['value']??null));
    if(preg_match('#^encounters/(\d+)/participants/(\d+)$#',$api,$m)){
        if($method==='PATCH'){ $encounters->updateParticipant((int)$m[1],(int)$m[2],$body);respond(['ok'=>true]); }
        if($method==='DELETE'){ $encounters->removeParticipant((int)$m[1],(int)$m[2]);respond(['ok'=>true]); }
    }
    if(preg_match('#^encounters/(\d+)/participants/(\d+)/resources/([^/]+)$#',$api,$m)&&($method==='PATCH'||$method==='PUT'))respond($encounters->setParticipantResource((int)$m[1],(int)$m[2],$m[3],(float)($body['value']??$body['current']??0),isset($body['class_id'])?(string)$body['class_id']:null,isset($body['character_class_id'])?(int)$body['character_class_id']:null));
    if(preg_match('#^encounters/(\d+)/participants/(\d+)/phase$#',$api,$m)&&$method==='PATCH'){$encounters->setBossPhase((int)$m[1],(int)$m[2],(string)($body['phase']??''));respond(['ok'=>true]);}
    if(preg_match('#^encounters/(\d+)/participants/(\d+)/select$#',$api,$m)&&$method==='POST'){$encounters->selectParticipant((int)$m[1],(int)$m[2]);respond(['ok'=>true]);}
    if(preg_match('#^encounters/(\d+)/(next|previous)$#',$api,$m)&&$method==='POST'){ $encounters->moveTurn((int)$m[1],$m[2]==='next'?1:-1);respond(['ok'=>true]); }
    if(preg_match('#^encounters/(\d+)/apply-effect$#',$api,$m)&&$method==='POST')respond($encounters->applyEffect((int)$m[1],$body));
    if($api==='calculate'&&$method==='POST') respond(calculateCombatRequest($pdo,$body,$resources));
    if($api==='calculate'&&$method==='POST'){
        // Resistenz und Profilverteidigung stammen ausschließlich aus dem Zielprofil.
        $body['resistance_percent']='';
        $body['defense']='';
        $engine=new FormulaEngine();$sourceType=isset($body['source_type'])?(string)$body['source_type']:null;$sourceId=isset($body['source_id'])?(string)$body['source_id']:null;$actorId=(int)($body['actor_participant_id']??0);$targetId=(int)($body['target_participant_id']??0);$weaponSlot=trim((string)($body['weapon_slot']??''));$weaponProfile=trim((string)($body['weapon_profile']??''));$actorStmt=$pdo->prepare('SELECT participant_type,reference_id FROM combat_participants WHERE id=?');$actorStmt->execute([$actorId]);$actor=$actorStmt->fetch()?:[];$actorType=(string)($actor['participant_type']??'');$weaponContext=null;$weapon=null;
        if($sourceType==='weapon_attack'){$resolver=new WeaponCombatResolver($pdo);if($actorType!=='character')throw new RuntimeException('Waffenangriffe sind nur für Spielercharaktere verfügbar.');if(!in_array($weaponSlot,['hand_1','hand_2'],true))throw new RuntimeException('Bitte einen gültigen Waffenslot auswählen.');$weapon=$resolver->equipped((int)$actor['reference_id'],$weaponSlot);if(!$weapon||($sourceId!==''&&$sourceId!==(string)$weapon['id']))throw new RuntimeException('Die gewählte Waffe ist in diesem Waffenslot nicht mehr ausgerüstet.');$weaponContext=$resolver->context($weapon,null,$weaponProfile!==''?$weaponProfile:null);$source=['attack_roll'=>$weaponContext['attack_formula'],'calculation'=>$weaponContext['damage_formula'],'damage_type'=>$weaponContext['damage_type'],'type'=>'Waffenangriff','effect'=>'Normaler Waffenangriff','resource_cost'=>null,'class_resource'=>null];}else{$source=sourceDefinition($pdo,$sourceType,$sourceId);}
        if(!$source&&$sourceType!==null)throw new RuntimeException('Die gewählte Combat-Quelle wurde nicht gefunden.');
        if($sourceType!=='weapon_attack'&&$actorType==='character'&&$source){$meta=WeaponCombatResolver::classify($source);if($meta['requires_weapon']){$resolver=new WeaponCombatResolver($pdo);if(!in_array($weaponSlot,['hand_1','hand_2'],true))throw new RuntimeException('Diese Fähigkeit benötigt eine ausgerüstete Waffe.');$weapon=$resolver->equipped((int)$actor['reference_id'],$weaponSlot);if(!$weapon)throw new RuntimeException('Diese Fähigkeit benötigt eine ausgerüstete Waffe.');$override=preg_match('/Willenskraft\s+anstelle/iu',(string)($source['attack_roll']??'').' '.(string)($source['calculation']??''))?'Willenskraft':null;$weaponContext=$resolver->context($weapon,$override,$weaponProfile!==''?$weaponProfile:null);if($meta['uses_normal_weapon_attack'])$source['attack_roll']=$weaponContext['attack_formula'];if($meta['uses_normal_weapon_damage'])$source['calculation']=bridgeWeaponDamage((string)($source['calculation']??''),$weaponContext['damage_formula']);$source['damage_type']=$source['damage_type']?:$weaponContext['damage_type'];}}
        $secondaryEffect=null;$formula=(string)($body['formula']??'');if($formula===''&&$source)$formula=(string)($source['calculation']??'');$weaponDamageSource=$sourceType==='weapon_attack'||($weaponContext&&WeaponCombatResolver::classify($source)['uses_normal_weapon_damage']);if($weaponDamageSource)$formula=(string)($source['calculation']??$formula);if($source&&isset($source['calculation'])){$parts=splitSecondaryEffect((string)$source['calculation']);if($parts['effect']!==null){$secondaryEffect=['formula'=>$parts['effect'],'type'=>$parts['type']];$source['calculation']=$parts['main'];$formula=$parts['main'];}}$body['damage_type']=$weaponDamageSource?($weaponContext['damage_type']??$source['damage_type']??null):($body['damage_type']??($source['damage_type']??null));$expression=$engine->extractMath($formula)??$formula;$body['dice']=rollDiceContext($expression,$body,$weaponContext);
        $mode=(string)($body['calculation_mode']??'damage');
        if(!in_array($mode,['damage','hit'],true))throw new RuntimeException('Unbekannter Berechnungsmodus.');
        $variables=actorVariables($pdo,$actorId,$body['magic_attribute']??null);
        if($weapon&&$weaponContext){$local=(new WeaponCombatResolver($pdo))->localVariables($weapon,$variables);$variables=array_merge($variables,$local);$resultLocal=$local;}else{$resultLocal=[];}
        $variables=array_merge($variables,$body['manual_variables']??[]);
        $result=$engine->evaluate($expression,['variables'=>$variables,'dice'=>$body['dice']??[]]);
        $result['original_text']=$formula;$result['expression']=$expression;$effectText=trim((string)($source['type']??'').' '.(string)($source['effect']??'').' '.(string)($source['rule_effect']??''));$result['effect_type']=preg_match('/schild|barriere|schutz|schadensreduktion|schadensabsorption/iu',$effectText)?'shield':(preg_match('/heil|regeneration/iu',$effectText)?'healing':(preg_match('/schaden|angriff/iu',$effectText)?'damage':'other'));if($secondaryEffect&&$result['value']!==null)$result['effect_type']='damage';
        if($secondaryEffect&&$secondaryEffect['formula']!==''){$effectExpression=$engine->extractMath($secondaryEffect['formula'])??$secondaryEffect['formula'];$effectBody=['rolls'=>['effect_1'=>$body['rolls']['effect_1']??($body['effect_roll']??'')]];$effectDice=rollDiceContext($effectExpression,$effectBody,null);$effectResult=$engine->evaluate($effectExpression,['variables'=>$variables,'dice'=>$effectDice]);$result['effects']=[['type'=>$secondaryEffect['type'],'value'=>$effectResult['value'],'formula'=>$effectExpression,'supported'=>$effectResult['supported'],'missing'=>$effectResult['missing']??[]]];}
        $attackFormula=trim((string)($source['attack_roll']??''));$attackRequired=$actorType==='character'&&$attackFormula!==''&&$attackFormula!=='-'&&preg_match('/(?:W20|normal(?:er|en)?\s+(?:Waffen[- ]?)?angriff|Waffenschaden|angriffswurf)/iu',$attackFormula);$attackHit=null;
        if($mode==='hit'&&$actorType!=='character')throw new RuntimeException('Trefferprüfung ist nur für Spielercharaktere verfügbar.');
        if($mode==='hit'&&!$attackRequired)throw new RuntimeException('Für diese Fähigkeit ist keine Trefferformel hinterlegt.');
        if($attackRequired){$attackExpression=$engine->extractMath($attackFormula)??'W20';$attackDice=[];$hitRoll=$body['hit_roll']??($body['rolls']['hit']??'');$attack=[];$targetVars=$targetId?targetVariables($pdo,$targetId):[];$needsTargetRoll=(bool)preg_match('/gegen\s+(?:den\s+normalen\s+)?(?:W20|Ausweichwurf)/iu',$attackFormula);$targetRoll=$body['target_roll']??'';if($hitRoll!==''){$attackDice['W20']=(float)$hitRoll;$attack=$engine->evaluate($attackExpression,['variables'=>$variables,'dice'=>$attackDice]);}$attack['expression']=$attackExpression;$targetText=preg_replace('/^.*?\s+gegen\s+/iu','',$attackFormula)??'';if($needsTargetRoll&&$targetRoll!==''){$targetExpression=$engine->extractMath($targetText)??'W20 + Ausweichen-Modifikator des Ziels + Ausweichen-Bonus des Ziels';$target=$engine->evaluate($targetExpression,['variables'=>$targetVars,'dice'=>['W20'=>(float)$targetRoll]]);$attack['target']=$target;$attack['target_total']=$target['value'];}elseif($weaponContext&&$targetId){$attack['target_total']=($targetVars['AU-Modifikator des Ziels']??0)+($targetVars['AU-Bonus des Ziels']??0);}elseif(!$needsTargetRoll){$target=$targetId?targetAdjustments($pdo,$targetId,$body['damage_type']??null):['defense'=>0];$attack['target_total']=$target['defense'];}if($hitRoll!==''){$attack['value']=$attack['value']??null;$attackHit=($attack['value']!==null&&isset($attack['target_total']))?($attack['value']>=(float)$attack['target_total']):null;$attack['hit']=$attackHit;}else{$attack['value']=null;$attack['hit']=null;}$result['attack']=$attack;}
        if($mode==='hit'){respond(['supported'=>true,'attack'=>$result['attack']??['value'=>null,'hit'=>null],'original_text'=>$attackFormula,'expression'=>$attackFormula]);}
        if($result['value']!==null){if(array_key_exists('critical',$body)&&!is_bool($body['critical']))throw new RuntimeException('Der Krit-Schalter muss ein eindeutiger Boolean sein.');$isCritical=$body['critical']??false;$isDamage=actorSourceIsDamage($pdo,$actorId,$sourceType,$sourceId,$weaponSlot);$auto=$isDamage?targetAdjustments($pdo,$targetId,$body['damage_type']??null):['resistance'=>0.0,'defense'=>0.0,'sources'=>[]];$resistance=$auto['resistance'];$defense=$auto['defense'];$hasCriticalValue=array_key_exists('Kritischer Schaden',$variables);$crit=(float)($variables['Kritischer Schaden']??0);$result['adjustments']=['resistance_percent'=>$resistance,'defense'=>$defense,'sources'=>$auto['sources']];$normalValue=(float)$result['value'];$result['damage']=(new CombatCalculator())->damage($normalValue,$isCritical,$crit,$resistance,$defense,0,0,$isDamage);$result['damage']['critical_source']=actorCriticalSource($pdo,$actorId,$hasCriticalValue);}
        $result['execution_id']=bin2hex(random_bytes(12));
        $result['resource_costs']=$resources->parseCosts($source['resource_cost']??null,$source['class_resource']??null);$result['weapon']=$weaponContext;if($resultLocal)$result['weapon_local_variables']=$resultLocal;
        respond($result);
    }
    respond(['error'=>'Endpunkt nicht gefunden.'],404);
} catch (Throwable $e) {
    $decoded=json_decode($e->getMessage(),true);$message=is_array($decoded)?$decoded:['error'=>$e->getMessage()];respond($message,422);
}

