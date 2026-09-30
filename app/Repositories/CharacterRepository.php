<?php
declare(strict_types=1);

namespace Aetherfall\Repositories;

use Aetherfall\Services\CharacterAttributeCalculator;
use Aetherfall\Services\CharacterHpCalculator;
use Aetherfall\Services\CharacterValidator;
use Aetherfall\Support\Logger;
use PDO;
use RuntimeException;

final class CharacterRepository
{
    public function __construct(private PDO $pdo) { $this->ensureAbilitySlots(); $this->ensureSpellSlots(); new \Aetherfall\Services\CharacterClassService($pdo); }

    public function all(): array
    {
        return $this->pdo->query('SELECT c.*,cl.name AS class_name FROM characters c JOIN classes cl ON cl.id=c.class_id ORDER BY c.name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*,cl.name AS class_name FROM characters c JOIN classes cl ON cl.id=c.class_id WHERE c.id=?');
        $stmt->execute([$id]); $character = $stmt->fetch();
        if (!$character) return null;
        $stmt = $this->pdo->prepare('SELECT ca.*,ad.name FROM character_attributes ca JOIN attribute_definitions ad ON ad.code=ca.attribute_code WHERE ca.character_id=? ORDER BY ad.sort_order');
        $stmt->execute([$id]); $character['attributes'] = $stmt->fetchAll();
        $stmt = $this->pdo->prepare('SELECT a.*,ca.class_role,ca.ability_level,ca.slot_number FROM character_abilities ca JOIN abilities a ON a.id=ca.ability_id WHERE ca.character_id=? ORDER BY COALESCE(ca.ability_level,a.unlock_level),ca.class_role,ca.slot_number,a.number');
        $stmt->execute([$id]); $character['abilities'] = $stmt->fetchAll();
        $stmt = $this->pdo->prepare('SELECT s.*,e.name AS element_name FROM character_spells cs JOIN spells s ON s.id=cs.spell_id JOIN spell_elements e ON e.id=s.element_id WHERE cs.character_id=? ORDER BY e.name,s.grade,s.number');
        $stmt->execute([$id]); $character['spells'] = $stmt->fetchAll();
        try{$slot=$this->pdo->prepare('SELECT * FROM character_spell_slots WHERE character_id=? ORDER BY grade,slot_number');$slot->execute([$id]);$character['spell_slots']=$slot->fetchAll();}catch(\Throwable){$character['spell_slots']=[];}
        $classService=new \Aetherfall\Services\CharacterClassService($this->pdo);$character['classes']=$classService->classes($id);$character['effective_level']=$classService->effectiveLevel($id);
        return $character;
    }

    public function save(array $data, ?int $id = null): int
    {
        if(!isset($data['classes'])&&!isset($data['primary_class_id'])&&isset($data['class_id']))$data['classes']=['primary'=>['class_id'=>$data['class_id'],'class_level'=>$data['level']??1]];
        $relations=$data['classes']??[];if(!$relations&&isset($data['primary_class_id'])){$relations=['primary'=>['class_id'=>$data['primary_class_id'],'class_level'=>$data['primary_class_level']??$data['level']??1],'secondary_1'=>['class_id'=>$data['secondary_class_1_id']??null,'class_level'=>$data['secondary_class_1_level']??1],'secondary_2'=>['class_id'=>$data['secondary_class_2_id']??null,'class_level'=>$data['secondary_class_2_level']??1]];}$relations=array_filter($relations,static fn($row)=>is_array($row)&&!empty($row['class_id']));$origin=false;if($relations){if(!isset($relations['primary']))throw new RuntimeException('Eine Hauptklasse ist erforderlich.');$seen=[];$max=1;foreach($relations as $role=>$row){if(!in_array($role,['primary','secondary_1','secondary_2'],true))throw new RuntimeException('Ungültige Klassenrolle.');$cid=(string)$row['class_id'];$lvl=(int)($row['class_level']??1);if($lvl<1||$lvl>40)throw new RuntimeException('Klassenstufen müssen zwischen 1 und 40 liegen.');if(isset($seen[$cid]))throw new RuntimeException('Eine Klasse darf nicht mehrfach ausgewählt werden.');$seen[$cid]=true;$max=max($max,$lvl);$origin=$origin||str_contains(mb_strtolower($cid),'ursprungsvermaechtnis');}if($origin&&count($relations)>1)throw new RuntimeException('Ursprungsvermächtnis kann nicht mit Nebenklassen kombiniert werden.');$data['class_id']=(string)$relations['primary']['class_id'];$data['level']=$max;}
        $errors = (new CharacterValidator())->validate($data);
        if ($errors) throw new RuntimeException(json_encode(['validation' => $errors], JSON_UNESCAPED_UNICODE));
        $class = $this->pdo->prepare('SELECT 1 FROM classes WHERE id=?'); $class->execute([$data['class_id']]);
        if (!$class->fetchColumn()) throw new RuntimeException('Die gewählte Klasse existiert nicht.');

        $attributeCalculator = new CharacterAttributeCalculator();
        $hpCalculator = new CharacterHpCalculator();
        $calculatedAttributes = [];
        foreach ($data['attributes'] as $row) {
            $code = strtoupper((string)$row['code']);
            $value = (int)$row['value'];
            $calculatedAttributes[$code] = [
                'code' => $code,
                'value' => $value,
                'modifier' => $attributeCalculator->calculateModifier($value),
                'bonus' => (float)$row['bonus'],
            ];
        }

        $adjustments = $this->pdo->prepare("SELECT role,attribute_code FROM class_attribute_adjustments WHERE class_id=? AND role IN ('primary','secondary')");
        $adjustments->execute([$data['class_id']]);
        $roles = [];
        foreach ($adjustments->fetchAll() as $adjustment) {
            $roles[$adjustment['role']] = $adjustment['attribute_code'];
        }
        if (!isset($roles['primary'], $roles['secondary'], $calculatedAttributes[$roles['primary']], $calculatedAttributes[$roles['secondary']])) {
            $technical = new RuntimeException('Ungültige Klassenattribut-Konfiguration für Klasse ' . $data['class_id']);
            Logger::error($technical);
            throw new RuntimeException('Für diese Klasse ist kein gültiges Haupt- bzw. Sekundärattribut hinterlegt.');
        }
        $primary = $calculatedAttributes[$roles['primary']];
        $secondary = $calculatedAttributes[$roles['secondary']];
        $maxHp = $hpCalculator->calculateMaxHp(
            (int)$data['level'],
            $primary['modifier'],
            $primary['bonus'],
            $secondary['modifier'],
            $secondary['bonus']
        );

        $existingCurrentHp = null;
        if ($id !== null) {
            $existing = $this->pdo->prepare('SELECT current_hp FROM characters WHERE id=?');
            $existing->execute([$id]);
            $existingCurrentHp = $existing->fetchColumn();
            if ($existingCurrentHp === false) throw new RuntimeException('Charakter nicht gefunden.');
            $existingCurrentHp = (int)$existingCurrentHp;
        }
        $submittedCurrentHp = array_key_exists('current_hp', $data) && $data['current_hp'] !== '' && $data['current_hp'] !== null
            ? (int)$data['current_hp']
            : null;
        $currentHp = $hpCalculator->resolveCurrentHp($submittedCurrentHp, $maxHp, $id === null, $existingCurrentHp);

        $abilityIds = array_values(array_unique(array_map('strval', $data['ability_ids'] ?? [])));$spellSlots=$data['spell_slots']??[];$abilitySlots=$data['ability_slots']??[];if(!empty($origin)){$abilityIds=[];$abilitySlots=[];}
        $slotRows=[];$seen=[];
        if($abilitySlots){$roleKeys=array_intersect(array_keys($abilitySlots),['primary','secondary_1','secondary_2']);if($roleKeys){foreach($roleKeys as $role){$maxSlot=$role==='primary'?2:1;foreach((array)$abilitySlots[$role] as $level=>$slots)foreach((array)$slots as $slot=>$abilityId)if($abilityId!==null&&$abilityId!==''){$level=(int)$level;$slot=(int)$slot;if($level<1||$level>40||$slot<1||$slot>$maxSlot)throw new RuntimeException('Ungültiger Fähigkeitsslot.');$abilityId=(string)$abilityId;if(isset($seen[$abilityId]))throw new RuntimeException('Eine Fähigkeit darf nicht doppelt ausgewählt werden.');$seen[$abilityId]=true;$slotRows[]=['role'=>$role,'level'=>$level,'slot'=>$slot,'id'=>$abilityId];$abilityIds[]=$abilityId;}}}else{foreach($abilitySlots as $level=>$slots)foreach((array)$slots as $slot=>$abilityId)if($abilityId!==null&&$abilityId!==''){$level=(int)$level;$slot=(int)$slot;if($level<1||$level>(int)$data['level']||$slot<1||$slot>2)throw new RuntimeException('Ungültiger Fähigkeitsslot.');$abilityId=(string)$abilityId;if(isset($seen[$abilityId]))throw new RuntimeException('Eine Fähigkeit darf nicht doppelt ausgewählt werden.');$seen[$abilityId]=true;$slotRows[]=['role'=>'primary','level'=>$level,'slot'=>$slot,'id'=>$abilityId];$abilityIds[]=$abilityId;}}}
        foreach($slotRows as $row){$check=$this->pdo->prepare('SELECT class_id,unlock_level FROM abilities WHERE id=?');$check->execute([$row['id']]);$ability=$check->fetch();$roleClass=$relations[$row['role']]['class_id']??$data['class_id'];$roleLevel=(int)($relations[$row['role']]['class_level']??$data['level']);if(!$ability||$ability['class_id']!==$roleClass||(int)$ability['unlock_level']!==$row['level']||$row['level']>$roleLevel)throw new RuntimeException('Fähigkeit gehört nicht zur Klasse oder zur gewählten Klassenstufe.');}
        if ($abilityIds && !$slotRows) {
            $placeholders = implode(',', array_fill(0, count($abilityIds), '?'));
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM abilities WHERE class_id=? AND unlock_level<=? AND id IN ({$placeholders})");
            $stmt->execute([$data['class_id'], $data['level'], ...$abilityIds]);
            if ((int)$stmt->fetchColumn() !== count($abilityIds)) throw new RuntimeException('Mindestens eine Fähigkeit gehört nicht zur Klasse oder ist noch nicht freigeschaltet.');
        }
        $spellIds = array_values(array_unique(array_map('strval', $data['spell_ids'] ?? [])));
        if($spellSlots){$spellIds=[];$seenSpells=[];foreach($spellSlots as $grade=>$slots){$grade=(int)$grade;if($grade<1||$grade>10)throw new RuntimeException('Zaubergrad muss zwischen 1 und 10 liegen.');foreach((array)$slots as $slot=>$spellId)if($spellId!==''){if(isset($seenSpells[$spellId]))throw new RuntimeException('Ein Zauber darf nicht doppelt in Slots liegen.');$seenSpells[$spellId]=true;$spellIds[]=(string)$spellId;$check=$this->pdo->prepare('SELECT grade FROM spells WHERE id=?');$check->execute([(string)$spellId]);if((int)$check->fetchColumn()!==$grade)throw new RuntimeException('Zauber gehört nicht zum gewählten Grad.');}}}
        if ($spellIds) {
            $placeholders = implode(',', array_fill(0, count($spellIds), '?'));
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM spells WHERE id IN ({$placeholders})"); $stmt->execute($spellIds);
            if ((int)$stmt->fetchColumn() !== count($spellIds)) throw new RuntimeException('Mindestens ein Zauber existiert nicht.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO characters (name,player_name,class_id,level,max_hp,current_hp,critical_damage_percent) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$data['name'],$data['player_name'],$data['class_id'],$data['level'],$maxHp,$currentHp,$data['critical_damage_percent']]);
                $id = (int)$this->pdo->lastInsertId();
            } else {
                $stmt = $this->pdo->prepare('UPDATE characters SET name=?,player_name=?,class_id=?,level=?,max_hp=?,current_hp=?,critical_damage_percent=? WHERE id=?');
                $stmt->execute([$data['name'],$data['player_name'],$data['class_id'],$data['level'],$maxHp,$currentHp,$data['critical_damage_percent'],$id]);
                $this->pdo->prepare('DELETE FROM character_attributes WHERE character_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM character_abilities WHERE character_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM character_spells WHERE character_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM character_spell_slots WHERE character_id=?')->execute([$id]);
            }
            if($relations){$this->pdo->prepare('DELETE FROM character_classes WHERE character_id=?')->execute([$id]);$classLink=$this->pdo->prepare('INSERT INTO character_classes (character_id,class_id,role,class_level) VALUES (?,?,?,?)');foreach($relations as $role=>$row)$classLink->execute([$id,(string)$row['class_id'],$role,(int)$row['class_level']]);}
            $attr = $this->pdo->prepare('INSERT INTO character_attributes (character_id,attribute_code,value,modifier,bonus) VALUES (?,?,?,?,?)');
            foreach ($calculatedAttributes as $row) $attr->execute([$id,$row['code'],$row['value'],$row['modifier'],$row['bonus']]);
            $link = $this->pdo->prepare('INSERT INTO character_abilities (character_id,ability_id,class_role,ability_level,slot_number) VALUES (?,?,?,?,?)');
            if ($slotRows) { foreach ($slotRows as $row) $link->execute([$id,$row['id'],$row['role'],$row['level'],$row['slot']]); }
            else { foreach ($abilityIds as $index=>$abilityId) { $level=(int)($this->pdo->query("SELECT unlock_level FROM abilities WHERE id=".$this->pdo->quote($abilityId))->fetchColumn()?:1);$link->execute([$id,$abilityId,'primary',$level,($index%2)+1]); } }
            $link = $this->pdo->prepare('INSERT INTO character_spells (character_id,spell_id) VALUES (?,?)');
            foreach ($spellIds as $spellId) $link->execute([$id,$spellId]);
            if($spellSlots){$slotLink=$this->pdo->prepare('INSERT INTO character_spell_slots (character_id,grade,slot_number,spell_id) VALUES (?,?,?,?)');foreach($spellSlots as $grade=>$slots)foreach((array)$slots as $slot=>$spellId)if($spellId!=='')$slotLink->execute([$id,(int)$grade,(int)$slot,(string)$spellId]);}
            $this->pdo->commit(); return $id;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    private function ensureSpellSlots(): void
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$ddl=$mysql?'CREATE TABLE IF NOT EXISTS character_spell_slots (character_id BIGINT UNSIGNED NOT NULL,grade TINYINT UNSIGNED NOT NULL,slot_number SMALLINT UNSIGNED NOT NULL,spell_id VARCHAR(190) NULL,PRIMARY KEY(character_id,grade,slot_number))':'CREATE TABLE IF NOT EXISTS character_spell_slots (character_id INTEGER NOT NULL,grade INTEGER NOT NULL,slot_number INTEGER NOT NULL,spell_id VARCHAR(190) NULL,PRIMARY KEY(character_id,grade,slot_number))';try{$this->pdo->exec($ddl);}catch(\Throwable){}
    }

    private function ensureAbilitySlots(): void
    {
        foreach (['class_role'=>'VARCHAR(20) NULL','ability_level'=>'SMALLINT NULL','slot_number'=>'TINYINT NULL'] as $column=>$definition) {
            try { $this->pdo->query("SELECT {$column} FROM character_abilities LIMIT 1"); }
            catch (\Throwable) { $this->pdo->exec("ALTER TABLE character_abilities ADD COLUMN {$column} {$definition}"); }
        }
        try { $this->pdo->exec("UPDATE character_abilities ca JOIN abilities a ON a.id=ca.ability_id SET ca.ability_level=a.unlock_level WHERE ca.ability_level IS NULL"); } catch (\Throwable) {}
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            try {
                $schema = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
                $index = $this->pdo->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') FROM information_schema.statistics WHERE table_schema=? AND table_name='character_abilities' AND index_name='uq_character_ability_slot'");
                $index->execute([$schema]);
                $columns=(string)$index->fetchColumn();
                if ($columns !== 'character_id,class_role,ability_level,slot_number') {
                    if ($columns !== '') $this->pdo->exec('ALTER TABLE character_abilities DROP INDEX uq_character_ability_slot');
                    $this->pdo->exec('ALTER TABLE character_abilities ADD UNIQUE KEY uq_character_ability_slot (character_id,class_role,ability_level,slot_number)');
                }
            } catch (\Throwable) {}
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try { $this->pdo->prepare('DELETE FROM characters WHERE id=?')->execute([$id]); $this->pdo->commit(); }
        catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}

