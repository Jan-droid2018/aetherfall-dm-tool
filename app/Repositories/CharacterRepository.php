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
    public function __construct(private PDO $pdo) {}

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
        $stmt = $this->pdo->prepare('SELECT a.* FROM character_abilities ca JOIN abilities a ON a.id=ca.ability_id WHERE ca.character_id=? ORDER BY a.unlock_level,a.number');
        $stmt->execute([$id]); $character['abilities'] = $stmt->fetchAll();
        $stmt = $this->pdo->prepare('SELECT s.*,e.name AS element_name FROM character_spells cs JOIN spells s ON s.id=cs.spell_id JOIN spell_elements e ON e.id=s.element_id WHERE cs.character_id=? ORDER BY e.name,s.grade,s.number');
        $stmt->execute([$id]); $character['spells'] = $stmt->fetchAll();
        return $character;
    }

    public function save(array $data, ?int $id = null): int
    {
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

        $abilityIds = array_values(array_unique(array_map('strval', $data['ability_ids'] ?? [])));
        if ($abilityIds) {
            $placeholders = implode(',', array_fill(0, count($abilityIds), '?'));
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM abilities WHERE class_id=? AND unlock_level<=? AND id IN ({$placeholders})");
            $stmt->execute([$data['class_id'], $data['level'], ...$abilityIds]);
            if ((int)$stmt->fetchColumn() !== count($abilityIds)) throw new RuntimeException('Mindestens eine Fähigkeit gehört nicht zur Klasse oder ist noch nicht freigeschaltet.');
        }
        $spellIds = array_values(array_unique(array_map('strval', $data['spell_ids'] ?? [])));
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
                $this->pdo->prepare('DELETE FROM character_spells WHERE character_id=?')->execute([$id]);
            }
            $attr = $this->pdo->prepare('INSERT INTO character_attributes (character_id,attribute_code,value,modifier,bonus) VALUES (?,?,?,?,?)');
            foreach ($calculatedAttributes as $row) $attr->execute([$id,$row['code'],$row['value'],$row['modifier'],$row['bonus']]);
            $link = $this->pdo->prepare('INSERT INTO character_abilities (character_id,ability_id) VALUES (?,?)');
            foreach ($abilityIds as $abilityId) $link->execute([$id,$abilityId]);
            $link = $this->pdo->prepare('INSERT INTO character_spells (character_id,spell_id) VALUES (?,?)');
            foreach ($spellIds as $spellId) $link->execute([$id,$spellId]);
            $this->pdo->commit(); return $id;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try { $this->pdo->prepare('DELETE FROM characters WHERE id=?')->execute([$id]); $this->pdo->commit(); }
        catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}

