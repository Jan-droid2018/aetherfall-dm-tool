<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use Aetherfall\Support\Json;
use PDO;
use RuntimeException;

final class EncounterService
{
    public function __construct(private PDO $pdo) {}
    public function all(): array { return $this->pdo->query("SELECT e.*,COUNT(p.id) participant_count FROM combat_encounters e LEFT JOIN combat_participants p ON p.encounter_id=e.id GROUP BY e.id ORDER BY e.id DESC")->fetchAll(); }
    public function create(?string $name): int { $s=$this->pdo->prepare('INSERT INTO combat_encounters (name) VALUES (?)');$s->execute([$name ?: 'Neuer Kampf']);return (int)$this->pdo->lastInsertId(); }
    public function find(int $id): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM combat_encounters WHERE id=?');$s->execute([$id]);$e=$s->fetch();if(!$e)return null;
        $s=$this->pdo->prepare('SELECT * FROM combat_participants WHERE encounter_id=? ORDER BY initiative DESC,sort_order ASC,id ASC');$s->execute([$id]);$e['participants']=$s->fetchAll();
        foreach($e['participants'] as &$p){
            $p['details']=$this->participantDetails($p);
            if($p['participant_type']==='character'&&isset($p['details']['level']))$p['selected_level']=(int)$p['details']['level'];
            $p['content']=$this->participantContent($p);
        }
        $s=$this->pdo->prepare('SELECT * FROM combat_log WHERE encounter_id=? ORDER BY id DESC LIMIT 100');$s->execute([$id]);$e['log']=$s->fetchAll();return $e;
    }
    public function addParticipant(int $encounterId,array $data): int
    {
        $type=$data['participant_type']??'';$reference=(string)($data['reference_id']??'');$level=isset($data['selected_level'])?(int)$data['selected_level']:null;
        if(!in_array($type,['character','creature','boss'],true)||$reference==='')throw new RuntimeException('Ungültiger Teilnehmer.');
        if($type==='character'){$s=$this->pdo->prepare('SELECT name,level,max_hp,current_hp FROM characters WHERE id=?');$s->execute([(int)$reference]);}
        elseif($type==='creature'){$s=$this->pdo->prepare('SELECT c.name,cl.max_hp,cl.max_hp current_hp FROM creatures c JOIN creature_levels cl ON cl.creature_id=c.id WHERE c.id=? AND cl.level=?');$s->execute([$reference,$level]);}
        else{$s=$this->pdo->prepare('SELECT b.name,bl.max_hp,bl.max_hp current_hp FROM bosses b JOIN boss_levels bl ON bl.boss_id=b.id WHERE b.id=? AND bl.level=?');$s->execute([$reference,$level]);}
        $entity=$s->fetch();if(!$entity)throw new RuntimeException('Regelobjekt oder Stufenprofil nicht gefunden.');
        if($type==='character')$level=(int)$entity['level'];
        $maxHp=(int)($data['max_hp']??$entity['max_hp']??0); if($maxHp<1)throw new RuntimeException('Für dieses Profil muss Max LP manuell angegeben werden.');
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM combat_participants WHERE encounter_id=? AND participant_type=? AND reference_id=?');$count->execute([$encounterId,$type,$reference]);$n=(int)$count->fetchColumn()+1;
        $name=trim((string)($data['display_name']??'')) ?: $entity['name'].($n>1?" #{$n}":'');
        $s=$this->pdo->prepare('INSERT INTO combat_participants (encounter_id,participant_type,reference_id,display_name,initiative,selected_level,selected_phase,max_hp,current_hp,sort_order,runtime_state_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$encounterId,$type,$reference,$name,(float)($data['initiative']??0),$level,$data['selected_phase']??null,$maxHp,(int)($data['current_hp']??$entity['current_hp']??$maxHp),(int)($data['sort_order']??0),Json::encode([])]);return(int)$this->pdo->lastInsertId();
    }
    public function updateParticipant(int $encounterId,int $participantId,array $data): void
    {
        $allowed=['display_name','initiative','selected_phase','current_hp','max_hp','sort_order'];$parts=[];$args=[];foreach($allowed as $key){if(array_key_exists($key,$data)){$parts[]="{$key}=?";$args[]=$data[$key];}}if(!$parts)return;$args[]=$encounterId;$args[]=$participantId;$s=$this->pdo->prepare('UPDATE combat_participants SET '.implode(',',$parts).' WHERE encounter_id=? AND id=?');$s->execute($args);
    }
    public function removeParticipant(int $encounterId,int $participantId): void { $s=$this->pdo->prepare('DELETE FROM combat_participants WHERE encounter_id=? AND id=?');$s->execute([$encounterId,$participantId]); }
    public function moveTurn(int $encounterId,int $direction): void
    {
        $this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT current_round,current_turn_index FROM combat_encounters WHERE id=? FOR UPDATE');$s->execute([$encounterId]);$e=$s->fetch();if(!$e)throw new RuntimeException('Kampf nicht gefunden.');$s=$this->pdo->prepare('SELECT COUNT(*) FROM combat_participants WHERE encounter_id=?');$s->execute([$encounterId]);$count=(int)$s->fetchColumn();if(!$count)throw new RuntimeException('Keine Teilnehmer vorhanden.');$index=(int)$e['current_turn_index'];$round=(int)$e['current_round'];if($direction>0){$index++;if($index>=$count){$index=0;$round++;}}else{$index--;if($index<0){$index=$count-1;$round=max(1,$round-1);}}$s=$this->pdo->prepare('UPDATE combat_encounters SET current_turn_index=?,current_round=? WHERE id=?');$s->execute([$index,$round,$encounterId]);$this->pdo->commit();}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }
    public function applyEffect(int $encounterId,array $data): array
    {
        $target=(int)($data['target_participant_id']??0);$amount=max(0,(float)($data['amount']??0));$mode=$data['mode']??'damage';
        $this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT p.*,e.current_round FROM combat_participants p JOIN combat_encounters e ON e.id=p.encounter_id WHERE p.encounter_id=? AND p.id=? FOR UPDATE');$s->execute([$encounterId,$target]);$p=$s->fetch();if(!$p)throw new RuntimeException('Ziel nicht gefunden.');$before=(float)$p['current_hp'];$after=$mode==='healing'?min((float)$p['max_hp'],$before+$amount):max(0,$before-$amount);$this->pdo->prepare('UPDATE combat_participants SET current_hp=? WHERE id=?')->execute([$after,$target]);$calculation=$data['calculation']??[];$critical=$calculation['damage']??[];if($mode==='damage'&&($critical['critical_applied']??false)){$actor='Angriff';if(!empty($data['participant_id'])){$a=$this->pdo->prepare('SELECT display_name FROM combat_participants WHERE encounter_id=? AND id=?');$a->execute([$encounterId,$data['participant_id']]);$actor=(string)($a->fetchColumn()?:$actor);}$message=sprintf('%s trifft %s kritisch: normal %s, Krit-Bonus +%s (%s %%), nach Krit %s, final %s LP.',$actor,$p['display_name'],$this->number($critical['normal_damage']??0),$this->number($critical['critical_bonus']??0),$this->number($critical['critical_damage_percent']??0),$this->number($critical['damage_after_critical']??0),$this->number($amount));}else{$message=sprintf('%s: %s %s LP (%s → %s).',$p['display_name'],$mode==='healing'?'Heilung':'Schaden',$this->number($amount),$this->number($before),$this->number($after));}$s=$this->pdo->prepare('INSERT INTO combat_log (encounter_id,round_number,participant_id,target_participant_id,event_type,source_type,source_id,message,calculation_json) VALUES (?,?,?,?,?,?,?,?,?)');$s->execute([$encounterId,$p['current_round'],$data['participant_id']??null,$target,$mode,$data['source_type']??null,$data['source_id']??null,$message,Json::encode($calculation)]);$this->pdo->commit();return['before'=>$before,'after'=>$after,'message'=>$message];}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }
    private function participantContent(array $p): array
    {
        if($p['participant_type']==='character'){$s=$this->pdo->prepare("SELECT 'ability' source_type,a.id,a.name,a.type,a.attack_roll,a.calculation,a.damage_type,a.effect rule_text FROM character_abilities ca JOIN abilities a ON a.id=ca.ability_id WHERE ca.character_id=? UNION ALL SELECT 'spell',s.id,s.name,s.effect_type,s.attack_roll,s.calculation,s.damage_type,s.rule_effect FROM character_spells cs JOIN spells s ON s.id=cs.spell_id WHERE cs.character_id=?");$s->execute([(int)$p['reference_id'],(int)$p['reference_id']]);return$s->fetchAll();}
        if($p['participant_type']==='creature'){$s=$this->pdo->prepare("SELECT 'creature_action' source_type,id,name,type,attack_data attack_roll,damage_data calculation,damage_type,notes rule_text FROM creature_actions WHERE creature_id=? AND level=? ORDER BY number");$s->execute([$p['reference_id'],$p['selected_level']]);return$s->fetchAll();}
        $s=$this->pdo->prepare("SELECT 'boss_action' source_type,id,name,NULL type,attack_data attack_roll,damage_data calculation,NULL damage_type,notes rule_text FROM boss_actions WHERE boss_id=? AND level=? ORDER BY number");$s->execute([$p['reference_id'],$p['selected_level']]);return$s->fetchAll();
    }
    private function participantDetails(array $p): array
    {
        if($p['participant_type']==='character'){$s=$this->pdo->prepare('SELECT c.level,c.critical_damage_percent,cl.name class_name,ca.attribute_code code,ca.value,ca.modifier,ca.bonus FROM characters c JOIN classes cl ON cl.id=c.class_id JOIN character_attributes ca ON ca.character_id=c.id WHERE c.id=? ORDER BY FIELD(ca.attribute_code,\'ST\',\'GE\',\'BW\',\'IN\',\'WA\',\'KR\',\'CH\',\'EM\',\'WI\',\'IT\',\'AU\')');$s->execute([(int)$p['reference_id']]);$rows=$s->fetchAll();return['level'=>$rows[0]['level']??null,'critical_damage_percent'=>(float)($rows[0]['critical_damage_percent']??0),'class_name'=>$rows[0]['class_name']??null,'attributes'=>$rows];}
        if($p['participant_type']==='creature'){$s=$this->pdo->prepare('SELECT attributes_json,combat_values_json,elemental_resistances_json FROM creature_levels WHERE creature_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$r=$s->fetch()?:[];$combatValues=json_decode((string)($r['combat_values_json']??'[]'),true)?:[];return['attributes'=>json_decode((string)($r['attributes_json']??'[]'),true)?:[],'combat_values'=>$combatValues,'critical_damage_percent'=>(new CombatValueParser())->criticalDamagePercent($combatValues),'resistances'=>json_decode((string)($r['elemental_resistances_json']??'[]'),true)?:[]];}
        $s=$this->pdo->prepare('SELECT attribute_profiles_json,combat_values_json,elemental_resistances_json,phase_values_json FROM boss_levels WHERE boss_id=? AND level=?');$s->execute([$p['reference_id'],$p['selected_level']]);$r=$s->fetch()?:[];$profiles=json_decode((string)($r['attribute_profiles_json']??'[]'),true)?:[];return['attributes'=>$profiles[0]['attributes']??[],'attribute_profiles'=>$profiles,'combat_values'=>json_decode((string)($r['combat_values_json']??'[]'),true)?:[],'resistances'=>json_decode((string)($r['elemental_resistances_json']??'[]'),true)?:[],'phases'=>json_decode((string)($r['phase_values_json']??'[]'),true)?:[]];
    }

    private function number(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }
}
