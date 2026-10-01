<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use Aetherfall\Support\Json;
use PDO;
use RuntimeException;

final class EncounterService
{
    private ClassResourceService $resourceService;
    private WeaponCombatResolver $weaponCombat;
    public function __construct(private PDO $pdo) { $this->ensureRuntimeColumns(); $this->resourceService=new ClassResourceService($pdo); $this->weaponCombat=new WeaponCombatResolver($pdo); }
    public function all(): array { return $this->pdo->query("SELECT e.*,COUNT(p.id) participant_count FROM combat_encounters e LEFT JOIN combat_participants p ON p.encounter_id=e.id GROUP BY e.id ORDER BY e.id DESC")->fetchAll(); }
    public function create(?string $name): int { $s=$this->pdo->prepare('INSERT INTO combat_encounters (name) VALUES (?)');$s->execute([$name ?: 'Neuer Kampf']);return (int)$this->pdo->lastInsertId(); }
    public function find(int $id): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM combat_encounters WHERE id=?');$s->execute([$id]);$e=$s->fetch();if(!$e)return null;
        $s=$this->pdo->prepare('SELECT * FROM combat_participants WHERE encounter_id=? ORDER BY initiative DESC,sort_order ASC,id ASC');$s->execute([$id]);$e['participants']=$s->fetchAll();
        foreach($e['participants'] as &$p){
            if($p['participant_type']==='boss'){$this->syncBossPhase($p);}
            $p['details']=$this->participantDetails($p);if($p['participant_type']==='character')$p['details']=array_merge($p['details'],$this->characterClassDetails((int)$p['reference_id']));
            if($p['participant_type']==='character'&&isset($p['details']['level']))$p['selected_level']=(int)$p['details']['level'];
            $p['content']=$this->participantContent($p);
            $p['resources']=$p['participant_type']==='character'?$this->resourceService->statesForParticipant((int)$p['id']):[];
        }
        $s=$this->pdo->prepare('SELECT * FROM combat_log WHERE encounter_id=? ORDER BY id DESC LIMIT 100');$s->execute([$id]);$e['log']=$s->fetchAll();return $e;
    }
    public function addParticipant(int $encounterId,array $data): int
    {
        $type=$data['participant_type']??'';$reference=(string)($data['reference_id']??'');$level=isset($data['selected_level'])?(int)$data['selected_level']:null;
        if(!in_array($type,['character','creature','boss'],true)||$reference==='')throw new RuntimeException('Ungültiger Teilnehmer.');
        if($type==='character'){$s=$this->pdo->prepare('SELECT name,level,max_hp,current_hp FROM characters WHERE id=?');$s->execute([(int)$reference]);}
        elseif($type==='creature'){$s=$this->pdo->prepare('SELECT c.name,cl.max_hp,cl.max_hp current_hp,cl.combat_values_json,cl.hp_calculation_json FROM creatures c JOIN creature_levels cl ON cl.creature_id=c.id WHERE c.id=? AND cl.level=?');$s->execute([$reference,$level]);}
        else{$s=$this->pdo->prepare('SELECT b.name,bl.max_hp,bl.max_hp current_hp,bl.combat_values_json,bl.hp_calculation_json FROM bosses b JOIN boss_levels bl ON bl.boss_id=b.id WHERE b.id=? AND bl.level=?');$s->execute([$reference,$level]);}
        $entity=$s->fetch();if(!$entity)throw new RuntimeException('Regelobjekt oder Stufenprofil nicht gefunden.');
        if($type!=='character'){unset($data['max_hp'],$data['current_hp']);}
        if($type==='character')$level=(int)$entity['level'];
        $profileMaxHp=$entity['max_hp']??null;
        if($type!=='character'&&($profileMaxHp===null||(int)$profileMaxHp<1)){$profileMaxHp=(new CombatProfileValueResolver())->maxHp($entity['combat_values_json']??null,$entity['hp_calculation_json']??null);}
        $maxHp=(int)($data['max_hp']??$profileMaxHp??0); if($maxHp<1)throw new RuntimeException('Die maximalen LP konnten für dieses Profil nicht aus den Regelwerkdaten ermittelt werden.');
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM combat_participants WHERE encounter_id=? AND participant_type=? AND reference_id=?');$count->execute([$encounterId,$type,$reference]);$n=(int)$count->fetchColumn()+1;
        $name=trim((string)($data['display_name']??'')) ?: $entity['name'].($n>1?" #{$n}":'');
        if($type==='boss'){$data['selected_phase']=1;$data['runtime_state_json']=Json::encode(['current_phase'=>1,'highest_phase_reached'=>1,'phase_mode'=>'auto','phase_override'=>false]);}
        $s=$this->pdo->prepare('INSERT INTO combat_participants (encounter_id,participant_type,reference_id,display_name,initiative,selected_level,selected_phase,max_hp,current_hp,current_shield,sort_order,runtime_state_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$encounterId,$type,$reference,$name,(float)($data['initiative']??0),$level,$data['selected_phase']??null,$maxHp,max(0,(int)($data['current_hp']??$entity['current_hp']??$maxHp)),max(0,(float)($data['current_shield']??0)),(int)($data['sort_order']??0),$data['runtime_state_json']??Json::encode([])]);$id=(int)$this->pdo->lastInsertId();if($type==='character')$this->resourceService->createCombatSnapshot($id,(int)$reference);return$id;
    }
    public function updateParticipant(int $encounterId,int $participantId,array $data): void
    {
        $allowed=['display_name','initiative','selected_phase','current_hp','max_hp','sort_order'];$parts=[];$args=[];foreach($allowed as $key){if(array_key_exists($key,$data)){$parts[]="{$key}=?";$args[]=$data[$key];}}if(!$parts)return;$args[]=$encounterId;$args[]=$participantId;$s=$this->pdo->prepare('UPDATE combat_participants SET '.implode(',',$parts).' WHERE encounter_id=? AND id=?');$s->execute($args);
    }
    public function setBossPhase(int $encounterId,int $participantId,string $phase): void
    {
        $s=$this->pdo->prepare('SELECT * FROM combat_participants WHERE encounter_id=? AND id=?');$s->execute([$encounterId,$participantId]);$p=$s->fetch();
        if(!$p||$p['participant_type']!=='boss')throw new RuntimeException('Nur Bosse können eine Phase wechseln.');
        $phases=$this->phaseOptions($p);$selected=null;
        foreach($phases as $option)if((string)$option['id']===$phase){$selected=$option;break;}
        if(!$selected)throw new RuntimeException('Diese Bossphase ist für das ausgewählte Profil nicht vorhanden.');
        $state=json_decode((string)($p['runtime_state_json']??'{}'),true)?:[];$old=(int)($state['current_phase']??$p['selected_phase']??1);$new=(int)$selected['id'];$levelStmt=$this->pdo->prepare('SELECT combat_values_json,hp_calculation_json FROM boss_levels WHERE boss_id=? AND level=?');$levelStmt->execute([$p['reference_id'],$p['selected_level']]);$level=$levelStmt->fetch()?:[];$phaseMax=(new CombatProfileValueResolver())->phaseValue($level['combat_values_json']??null,'maximale_lp',['Maximale LP','Max LP'],$new);
        $state['current_phase']=$new;$state['highest_phase_reached']=max((int)($state['highest_phase_reached']??1),$new);$state['phase_mode']='manual';$state['phase_override']=true;
        if($phaseMax!==null&&$phaseMax>0){$this->pdo->prepare('UPDATE combat_participants SET selected_phase=?,runtime_state_json=?,max_hp=?,current_hp=LEAST(current_hp,?) WHERE encounter_id=? AND id=?')->execute([$new,Json::encode($state),(int)floor($phaseMax),(int)floor($phaseMax),$encounterId,$participantId]);}else{$this->pdo->prepare('UPDATE combat_participants SET selected_phase=?,runtime_state_json=? WHERE encounter_id=? AND id=?')->execute([$new,Json::encode($state),$encounterId,$participantId]);}
        if($old!==$new){$round=(int)$this->pdo->query('SELECT current_round FROM combat_encounters WHERE id='.(int)$encounterId)->fetchColumn();$message=$p['display_name'].' wechselt manuell von '.$this->phaseName($phases,$old).' zu '.$selected['name'].'.';$log=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,event_type,message,calculation_json) VALUES (?,?,?,?,?,?)');$log->execute([$encounterId,$round,$participantId,'phase',$message,Json::encode(['from'=>$old,'to'=>$new,'phase'=>$selected['name'],'manual'=>true])]);}
    }
    public function removeParticipant(int $encounterId,int $participantId): void { $s=$this->pdo->prepare('DELETE FROM combat_participants WHERE encounter_id=? AND id=?');$s->execute([$encounterId,$participantId]); }
    public function setParticipantResource(int $encounterId,int $participantId,string $resourceId,float $value): array
    {
        $check=$this->pdo->prepare('SELECT id FROM combat_participants WHERE encounter_id=? AND id=? AND participant_type=\'character\'');$check->execute([$encounterId,$participantId]);if(!$check->fetchColumn())throw new RuntimeException('Kampfteilnehmer nicht gefunden.');$result=$this->resourceService->setCombatCurrent($participantId,$resourceId,$value);$round=(int)$this->pdo->query('SELECT current_round FROM combat_encounters WHERE id='.(int)$encounterId)->fetchColumn();$log=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,event_type,message,calculation_json) VALUES (?,?,?,?,?,?)');$log->execute([$encounterId,$round,$participantId,'resource','Klassenressource '.$resourceId.' manuell auf '.$this->number($result['current']).' gesetzt.',Json::encode($result)]);return$result;
    }
    public function moveTurn(int $encounterId,int $direction): void
    {
        $this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT current_round,current_turn_index FROM combat_encounters WHERE id=? FOR UPDATE');$s->execute([$encounterId]);$e=$s->fetch();if(!$e)throw new RuntimeException('Kampf nicht gefunden.');$s=$this->pdo->prepare('SELECT COUNT(*) FROM combat_participants WHERE encounter_id=?');$s->execute([$encounterId]);$count=(int)$s->fetchColumn();if(!$count)throw new RuntimeException('Keine Teilnehmer vorhanden.');$index=(int)$e['current_turn_index'];$round=(int)$e['current_round'];if($direction>0){$index++;if($index>=$count){$index=0;$round++;}}else{$index--;if($index<0){$index=$count-1;$round=max(1,$round-1);}}$s=$this->pdo->prepare('UPDATE combat_encounters SET current_turn_index=?,current_round=? WHERE id=?');$s->execute([$index,$round,$encounterId]);$this->pdo->commit();}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }
    public function applyEffect(int $encounterId,array $data): array
    {
        $target=(int)($data['target_participant_id']??0);$validTarget=$this->pdo->prepare('SELECT id FROM combat_participants WHERE encounter_id=? AND id=?');$validTarget->execute([$encounterId,$target]);if(!$validTarget->fetchColumn())throw new RuntimeException('Ziel nicht gefunden.');
        if(($data['mode']??'damage')==='damage'&&(($data['calculation']??[])['attack']['hit']??null)===false)throw new RuntimeException('Der Trefferwurf ist fehlgeschlagen; es kann kein Schaden angewendet werden.');
        $this->consumeActionResources($encounterId,$data);
        if (($data['mode'] ?? 'damage') === 'shield') return $this->applyShield($encounterId, $data);
        if (($data['mode'] ?? 'damage') === 'damage') { $check=$this->pdo->prepare('SELECT current_shield FROM combat_participants WHERE encounter_id=? AND id=?');$check->execute([$encounterId,(int)($data['target_participant_id']??0)]);if((float)$check->fetchColumn()>0)return $this->applyDamageWithShield($encounterId,$data); }
        $target=(int)($data['target_participant_id']??0);$amount=max(0,(float)($data['amount']??0));$mode=$data['mode']??'damage';
        $this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT p.*,e.current_round FROM combat_participants p JOIN combat_encounters e ON e.id=p.encounter_id WHERE p.encounter_id=? AND p.id=? FOR UPDATE');$s->execute([$encounterId,$target]);$p=$s->fetch();if(!$p)throw new RuntimeException('Ziel nicht gefunden.');$before=(float)$p['current_hp'];$after=$mode==='healing'?min((float)$p['max_hp'],$before+$amount):max(0,$before-$amount);$this->pdo->prepare('UPDATE combat_participants SET current_hp=? WHERE id=?')->execute([$after,$target]);$calculation=$data['calculation']??[];$critical=$calculation['damage']??[];if($mode==='damage'&&($critical['critical_applied']??false)){$actor='Angriff';if(!empty($data['participant_id'])){$a=$this->pdo->prepare('SELECT display_name FROM combat_participants WHERE encounter_id=? AND id=?');$a->execute([$encounterId,$data['participant_id']]);$actor=(string)($a->fetchColumn()?:$actor);}$message=sprintf('%s trifft %s kritisch: normal %s, Krit-Bonus +%s (%s %%), nach Krit %s, final %s LP.',$actor,$p['display_name'],$this->number($critical['normal_damage']??0),$this->number($critical['critical_bonus']??0),$this->number($critical['critical_damage_percent']??0),$this->number($critical['damage_after_critical']??0),$this->number($amount));}else{$message=sprintf('%s: %s %s LP (%s → %s).',$p['display_name'],$mode==='healing'?'Heilung':'Schaden',$this->number($amount),$this->number($before),$this->number($after));}$s=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,target_participant_id,event_type,source_type,source_id,message,calculation_json) VALUES (?,?,?,?,?,?,?,?,?)');$s->execute([$encounterId,$p['current_round'],$data['participant_id']??null,$target,$mode,$data['source_type']??null,$data['source_id']??null,$message,Json::encode($calculation)]);$this->pdo->commit();return['before'=>$before,'after'=>$after,'message'=>$message];}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }

    private function consumeActionResources(int $encounterId,array $data): void
    {
        $costs=$data['resource_costs']??(($data['calculation']??[])['resource_costs']??[]);if(!is_array($costs)||!$costs)return;
        $participant=(int)($data['participant_id']??0);$execution=(string)($data['execution_id']??(($data['calculation']??[])['execution_id']??''));if(!$participant||$execution==='')throw new RuntimeException('Die Aktion besitzt keine gültige Ausführungs-ID.');
        $spent=0;$already=0;$validCosts=[];foreach($costs as $cost){$rid=(string)($cost['resource_id']??'');$amount=(float)($cost['amount']??0);if($rid===''||$amount<=0)continue;$validCosts[]=['resource_id'=>$rid,'amount'=>$amount];}
        foreach($validCosts as $cost){$check=$this->pdo->prepare('SELECT current_value FROM combat_participant_resources WHERE combat_participant_id=? AND class_resource_id=?');$check->execute([$participant,$cost['resource_id']]);$current=$check->fetchColumn();if($current===false||((float)$current<$cost['amount']))throw new RuntimeException('Nicht genug Klassenressource vorhanden.');}
        foreach($validCosts as $cost){$rid=$cost['resource_id'];$amount=$cost['amount'];$ok=$this->resourceService->spend($participant,$execution,$rid,$amount);if($ok)$spent++;else$already++;}
        if($spent===0&&$already>0)throw new RuntimeException('Diese Aktion wurde für diese Berechnung bereits ausgeführt.');
        if($spent>0){$round=(int)$this->pdo->query('SELECT current_round FROM combat_encounters WHERE id='.(int)$encounterId)->fetchColumn();$log=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,event_type,message,calculation_json) VALUES (?,?,?,?,?,?)');$log->execute([$encounterId,$round,$participant,'resource','Klassenressource für die Aktion verbraucht.',Json::encode(['execution_id'=>$execution,'resource_costs'=>$costs])]);}
    }
    private function characterContent(array $p): array
    {
        $characterId=(int)$p['reference_id'];
        $s=$this->pdo->prepare("SELECT cc.role,cc.class_id,cc.class_level,c.name class_name FROM character_classes cc JOIN classes c ON c.id=cc.class_id WHERE cc.character_id=? ORDER BY CASE cc.role WHEN 'primary' THEN 1 WHEN 'secondary_1' THEN 2 ELSE 3 END");$s->execute([$characterId]);$out=[];
        foreach($s->fetchAll() as $class){
            $action=$this->pdo->prepare('SELECT id,name,action_type,attack_formula attack_roll,damage_formula calculation,damage_type,description rule_text,resource_cost,resource_gain,unlock_level,raw_json FROM class_actions WHERE class_id=? AND unlock_level<=? ORDER BY unlock_level,id');$action->execute([$class['class_id'],(int)$class['class_level']]);
            foreach($action->fetchAll() as $row){$row['source_type']='class_action';$row['class_role']=$class['role'];$row['class_name']=$class['class_name'];$row['class_level']=$class['class_level'];$row['roll_inputs']=$this->rollInputs($row);$out[]=$row;}
            $profile=$this->pdo->prepare('SELECT id,profile_name,attack_formula attack_roll,damage_formula calculation,damage_type,description rule_text,start_die,handling,range_text,weight,raw_json FROM class_weapon_profiles WHERE class_id=? AND unlock_level<=? ORDER BY id');$profile->execute([$class['class_id'],(int)$class['class_level']]);
            foreach($profile->fetchAll() as $row){$row['name']='Exaltierte Waffe · '.$row['profile_name'];$row['source_type']='class_weapon';$row['class_role']=$class['role'];$row['class_name']=$class['class_name'];$row['class_level']=$class['class_level'];$row['roll_inputs']=$this->rollInputs($row);$out[]=$row;}
            $origin=mb_strtolower($class['class_id'])==='ursprungsvermaechtnis'||str_contains(mb_strtolower($class['class_id']),'ursprungsvermaechtnis');
            $sql=$origin?'SELECT a.id,a.name,a.type,a.attack_roll,a.calculation,a.damage_type,a.effect rule_text,NULL magic_attribute,a.unlock_level,a.costs resource_cost,a.class_resource,a.raw_json FROM abilities a WHERE a.class_id=? AND a.unlock_level<=? ORDER BY a.unlock_level,a.number':'SELECT a.id,a.name,a.type,a.attack_roll,a.calculation,a.damage_type,a.effect rule_text,NULL magic_attribute,a.unlock_level,a.costs resource_cost,a.class_resource,a.raw_json,ca.slot_number FROM character_abilities ca JOIN abilities a ON a.id=ca.ability_id WHERE ca.character_id=? AND a.class_id=? AND (ca.class_role=? OR ca.class_role IS NULL) AND ca.ability_level<=? AND a.unlock_level<=? ORDER BY ca.ability_level,ca.slot_number';$args=$origin?[$class['class_id'],(int)$class['class_level']]:[$characterId,$class['class_id'],$class['role'],(int)$class['class_level'],(int)$class['class_level']];$q=$this->pdo->prepare($sql);$q->execute($args);
            foreach($q->fetchAll() as $row){$row['source_type']='ability';$row['class_role']=$class['role'];$row['class_name']=$class['class_name'];$row['class_level']=$class['class_level'];$meta=WeaponCombatResolver::classify($row);$row=array_merge($row,$meta);$row['weapon_options']=$meta['requires_weapon']?$this->weaponCombat->equippedOptions($characterId):[];$row['roll_inputs']=$this->rollInputs($row);$out[]=$row;}
        }
        $weaponOptions=$this->weaponCombat->equippedOptions($characterId);
        foreach($weaponOptions as $option){$context=$option['weapon'];$out[]=['source_type'=>'weapon_attack','source_id'=>$option['weapon_id'],'id'=>$option['weapon_id'],'name'=>$option['name'].' · normaler Waffenangriff','type'=>'Waffenangriff','attack_roll'=>$context['attack_formula'],'calculation'=>$context['damage_formula'],'damage_type'=>$context['damage_type'],'weapon_slot'=>$option['slot'],'weapon'=>$context,'requires_weapon'=>false,'weapon_usage_type'=>'normal_weapon_attack','roll_inputs'=>$this->rollInputs(['attack_roll'=>$context['attack_formula'],'calculation'=>$context['damage_formula'],'weapon_attack'=>true])];}
        $q=$this->pdo->prepare("SELECT 'spell' source_type,s.id,s.name,s.effect_type type,s.attack_roll,s.calculation,s.damage_type,s.rule_effect rule_text,s.magic_attribute,NULL class_role,NULL class_name,NULL class_level,NULL unlock_level,s.raw_json FROM character_spells cs JOIN spells s ON s.id=cs.spell_id WHERE cs.character_id=?");$q->execute([$characterId]);foreach($q->fetchAll() as $row){$row['roll_inputs']=$this->rollInputs($row);$out[]=$row;}
        return $out;
    }

    private function rollInputs(array $row): array
    {
        $formula=(string)($row['calculation']??'');$attack=(string)($row['attack_roll']??'');$inputs=[];
        if($attack!==''&&preg_match('/(?:W20|waffen[- ]?angriff|angriffswurf)/iu',$attack))$inputs[]=['key'=>'hit','label'=>'Trefferwürfel','purpose'=>'hit','formula_dice'=>'W20'];
        $dice=preg_match_all('/\b(?:\d*)W\d+\b/iu',$formula,$matches)?array_values(array_unique(array_map('strtoupper',$matches[0]))):[];
        if($dice)$inputs[]=['key'=>!empty($row['weapon_attack'])?'weapon_damage':'damage','label'=>!empty($row['weapon_attack'])?'Waffenschadenswürfel':'Schadenswürfel','purpose'=>'damage','formula_dice'=>implode(' + ',$dice)];
        return $inputs;
    }

    private function participantContent(array $p): array
    {
        if($p['participant_type']==='character')return$this->characterContent($p);
        if($p['participant_type']==='character'){$s=$this->pdo->prepare("SELECT 'ability' source_type,a.id,a.name,a.type,a.attack_roll,a.calculation,a.damage_type,a.effect rule_text,NULL magic_attribute,ca.ability_level,ca.slot_number FROM character_abilities ca JOIN abilities a ON a.id=ca.ability_id JOIN characters ch ON ch.id=ca.character_id WHERE ca.character_id=? AND COALESCE(ca.ability_level,a.unlock_level)<=ch.level AND (ca.slot_number IS NULL OR ca.slot_number IN (1,2)) UNION ALL SELECT 'spell',s.id,s.name,s.effect_type,s.attack_roll,s.calculation,s.damage_type,s.rule_effect,s.magic_attribute,NULL,NULL FROM character_spells cs JOIN spells s ON s.id=cs.spell_id WHERE cs.character_id=?");$s->execute([(int)$p['reference_id'],(int)$p['reference_id']]);return$s->fetchAll();}
        if($p['participant_type']==='creature'){$s=$this->pdo->prepare("SELECT 'creature_action' source_type,id,name,type,attack_data attack_roll,damage_data calculation,damage_type,notes rule_text FROM creature_actions WHERE creature_id=? AND level=? ORDER BY number");$s->execute([$p['reference_id'],$p['selected_level']]);return$s->fetchAll();}
        $s=$this->pdo->prepare("SELECT 'boss_action' source_type,id,name,NULL type,attack_data attack_roll,damage_data calculation,NULL damage_type,notes rule_text,shared_rule_json FROM boss_actions WHERE boss_id=? AND level=? ORDER BY number");$s->execute([$p['reference_id'],$p['selected_level']]);$phase=(int)($p['selected_phase']??1);$actionPhase=$phase<=1?1:2;$rows=[];foreach($s->fetchAll() as $row){$rule=json_decode((string)($row['shared_rule_json']??''),true)?:[];$restriction=$rule['phase']??null;if($restriction!==null&&$restriction!==''){preg_match_all('/\d+/',(string)$restriction,$matches);$allowed=array_map('intval',$matches[0]);if($allowed&&!in_array($actionPhase,$allowed,true))continue;}$rows[]=$row;}return$rows;
    }
    private function characterClassDetails(int $characterId): array
    {
        $s=$this->pdo->prepare("SELECT cc.role,cc.class_id,cc.class_level FROM character_classes cc WHERE cc.character_id=? ORDER BY CASE cc.role WHEN 'primary' THEN 1 WHEN 'secondary_1' THEN 2 ELSE 3 END");$s->execute([$characterId]);$classes=[];$levels=[];$name=$this->pdo->prepare('SELECT name FROM classes WHERE id=?');foreach($s->fetchAll() as $row){$name->execute([$row['class_id']]);$row['name']=$name->fetchColumn()?:$row['class_id'];$classes[$row['role']]=$row;$levels[]=(int)$row['class_level'];}return['classes'=>$classes,'effective_level'=>$levels?max($levels):1];
    }

    private function participantDetails(array $p): array
    {
        if($p['participant_type']==='character'){$s=$this->pdo->prepare('SELECT c.level,c.critical_damage_percent,cl.name class_name,ca.attribute_code code,ca.value,ca.modifier,ca.bonus FROM characters c JOIN classes cl ON cl.id=c.class_id JOIN character_attributes ca ON ca.character_id=c.id WHERE c.id=? ORDER BY FIELD(ca.attribute_code,\'ST\',\'GE\',\'BW\',\'IN\',\'WA\',\'KR\',\'CH\',\'EM\',\'WI\',\'IT\',\'AU\')');$s->execute([(int)$p['reference_id']]);$rows=$s->fetchAll();return['level'=>$rows[0]['level']??null,'critical_damage_percent'=>(float)($rows[0]['critical_damage_percent']??0),'class_name'=>$rows[0]['class_name']??null,'attributes'=>$rows];}
        if($p['participant_type']==='creature'){$s=$this->pdo->prepare('SELECT attributes_json,combat_values_json,elemental_resistances_json FROM creature_levels WHERE creature_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$r=$s->fetch()?:[];$combatValues=json_decode((string)($r['combat_values_json']??'[]'),true)?:[];return['attributes'=>json_decode((string)($r['attributes_json']??'[]'),true)?:[],'combat_values'=>$combatValues,'critical_damage_percent'=>(new CombatValueParser())->criticalDamagePercent($combatValues),'resistances'=>json_decode((string)($r['elemental_resistances_json']??'[]'),true)?:[]];}
        $s=$this->pdo->prepare('SELECT attribute_profiles_json,combat_values_json,elemental_resistances_json,phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$r=$s->fetch()?:[];$profiles=json_decode((string)($r['attribute_profiles_json']??'[]'),true)?:[];$combatValues=json_decode((string)($r['combat_values_json']??'[]'),true)?:[];$phaseRows=(json_decode((string)($r['phase_values_json']??'[]'),true)[0]['rows']??[]);$state=json_decode((string)($p['runtime_state_json']??'{}'),true)?:[];$phase=max(1,(int)($state['current_phase']??$p['selected_phase']??1));$phaseData=$phaseRows[$phase-1]??[];$profile=(new CombatProfileValueResolver())->applyPhaseOverride($profiles[$phase-1]??$profiles[0]??[],$phaseData);return['attributes'=>$profile['attributes']??[],'attribute_profiles'=>$profiles,'combat_values'=>$combatValues,'critical_damage_percent'=>(new CombatValueParser())->criticalDamagePercent($combatValues,$phase),'resistances'=>json_decode((string)($r['elemental_resistances_json']??'[]'),true)?:[],'phases'=>$this->phaseOptions($p,$phaseRows),'current_phase'=>$phase,'phase_name'=>$phaseData['Phase']??($phaseData['Zustand']??$profile['name']??null),'phase_mode'=>$state['phase_mode']??'auto','phase_override'=>(bool)($state['phase_override']??false)];
    }

    private function syncBossPhase(array &$p): void
    {
        $s=$this->pdo->prepare('SELECT phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$phases=json_decode((string)$s->fetchColumn(),true)?:[];$rows=$phases[0]['rows']??[];if(!$rows)return;
        $state=json_decode((string)($p['runtime_state_json']??'{}'),true)?:[];if(($state['phase_override']??false)===true||($state['phase_mode']??'')==='manual')return;$highest=max(1,(int)($state['highest_phase_reached']??$p['selected_phase']??1));$current=$highest;$hp=(float)$p['current_hp'];
        foreach($rows as $index=>$row){$range=(string)($row['LP-Bereich']??'');if(preg_match('/(\d+(?:[.,]\d+)?)\s*(?:bis|-)\s*(\d+(?:[.,]\d+)?)/u',$range,$m)){if($hp<=(float)str_replace(',','.',$m[1])&&$hp>=(float)str_replace(',','.',$m[2])){$current=$index+1;break;}}}
        $current=max($highest,$current);if($current===(int)($p['selected_phase']??0)&&(int)($state['highest_phase_reached']??0)>=$current)return;
        $state['current_phase']=$current;$state['highest_phase_reached']=$current;$p['selected_phase']=$current;$p['runtime_state_json']=Json::encode($state);$this->pdo->prepare('UPDATE combat_participants SET selected_phase=?,runtime_state_json=? WHERE id=?')->execute([$current,$p['runtime_state_json'],$p['id']]);$round=(int)$this->pdo->query('SELECT current_round FROM combat_encounters WHERE id='.(int)$p['encounter_id'])->fetchColumn();$message=$p['display_name'].' erreicht Phase '.$current.'.';$log=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,event_type,message,calculation_json) VALUES (?,?,?,?,?,?)');$log->execute([$p['encounter_id'],$round,$p['id'],'phase',$message,Json::encode(['phase'=>$current])]);
    }

    private function phaseOptions(array $p,?array $rows=null): array
    {
        if($rows===null){$s=$this->pdo->prepare('SELECT phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$raw=json_decode((string)$s->fetchColumn(),true)?:[];$rows=$raw[0]['rows']??[];}
        $options=[];foreach($rows as $index=>$row){$name=trim((string)($row['Phase']??$row['Zustand']??$row['phase']??''));if($name==='')$name='Phase '.($index+1);$options[]=['id'=>$index+1,'name'=>$name];}return$options;
    }
    private function phaseName(array $phases,int $id): string { foreach($phases as $phase)if((int)$phase['id']===$id)return(string)$phase['name'];return'Phase '.$id; }

    private function applyDamageWithShield(int $encounterId, array $data): array
    {
        $target=(int)($data['target_participant_id']??0);$amount=max(0,(float)($data['amount']??0));$this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT p.*,e.current_round FROM combat_participants p JOIN combat_encounters e ON e.id=p.encounter_id WHERE p.encounter_id=? AND p.id=? FOR UPDATE');$s->execute([$encounterId,$target]);$p=$s->fetch();if(!$p)throw new RuntimeException('Ziel nicht gefunden.');$hpBefore=max(0,(float)$p['current_hp']);$shieldBefore=max(0,(float)($p['current_shield']??0));$absorbed=min($shieldBefore,$amount);$shieldAfter=max(0,$shieldBefore-$absorbed);$hpDamage=max(0,$amount-$absorbed);$hpAfter=max(0,$hpBefore-$hpDamage);$this->pdo->prepare('UPDATE combat_participants SET current_hp=?,current_shield=? WHERE id=?')->execute([$hpAfter,$shieldAfter,$target]);$calculation=$data['calculation']??[];$critical=$calculation['damage']??[];$message=sprintf('%s erleidet %s Schaden.',$p['display_name'],$this->number($amount));if($absorbed>0)$message.=' '.$this->number($absorbed).' Schaden werden vom Schild absorbiert.';if($hpDamage>0)$message.=' LP: '.$this->number($hpBefore).' → '.$this->number($hpAfter).'.';else $message.=' LP bleiben bei '.$this->number($hpBefore).'.';$log=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,target_participant_id,event_type,source_type,source_id,message,calculation_json) VALUES (?,?,?,?,?,?,?,?,?)');$log->execute([$encounterId,$p['current_round'],$data['participant_id']??null,$target,'damage',$data['source_type']??null,$data['source_id']??null,$message,Json::encode($calculation)]);$this->pdo->commit();return['before'=>$hpBefore,'after'=>$hpAfter,'shield_before'=>$shieldBefore,'shield_absorbed'=>$absorbed,'shield_after'=>$shieldAfter,'incoming_damage'=>$amount,'hp_damage'=>$hpDamage,'hp_before'=>$hpBefore,'hp_after'=>$hpAfter,'message'=>$message];}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }

    private function ensureRuntimeColumns(): void
    {
        try { $this->pdo->query('SELECT current_shield FROM combat_participants LIMIT 1'); }
        catch (\Throwable) { $this->pdo->exec('ALTER TABLE combat_participants ADD COLUMN current_shield DECIMAL(12,2) NOT NULL DEFAULT 0'); }
    }

    public function applyShield(int $encounterId, array $data): array
    {
        $target=(int)($data['target_participant_id']??0);$amount=max(0,(float)($data['amount']??0));
        $this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT p.*,e.current_round FROM combat_participants p JOIN combat_encounters e ON e.id=p.encounter_id WHERE p.encounter_id=? AND p.id=? FOR UPDATE');$s->execute([$encounterId,$target]);$p=$s->fetch();if(!$p)throw new RuntimeException('Ziel nicht gefunden.');$before=max(0,(float)($p['current_shield']??0));$after=$before+$amount;$this->pdo->prepare('UPDATE combat_participants SET current_shield=? WHERE id=?')->execute([$after,$target]);$message=sprintf('%s erhält %s Schild (%s → %s).',$p['display_name'],$this->number($amount),$this->number($before),$this->number($after));$log=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,target_participant_id,event_type,source_type,source_id,message,calculation_json) VALUES (?,?,?,?,?,?,?,?,?)');$log->execute([$encounterId,$p['current_round'],$data['participant_id']??null,$target,'shield',$data['source_type']??null,$data['source_id']??null,$message,Json::encode($data['calculation']??[])]);$this->pdo->commit();return['shield_before'=>$before,'shield_after'=>$after,'applied'=>$amount,'message'=>$message];}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }

    private function number(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }
}
