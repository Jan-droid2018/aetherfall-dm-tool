<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use PDO;
use RuntimeException;

/**
 * Persistent character skills.
 *
 * A skill's base value is calculated exactly once from the character's saved
 * attribute values. The current value and bonus are editable; the modifier is
 * always derived from the current value and is never accepted from clients.
 */
final class CharacterSkillService
{
    /** @var list<array{id:string,external_id:string,name:string,category:string,primary_attribute:string,secondary_attribute:string,sort_order:int}> */
    private const DEFINITIONS = [
        ['id' => 'ueberzeugen', 'external_id' => 'ueberzeugen', 'name' => 'Überzeugen', 'category' => 'social', 'primary_attribute' => 'CH', 'secondary_attribute' => 'EM', 'sort_order' => 1],
        ['id' => 'verhandeln', 'external_id' => 'verhandeln', 'name' => 'Verhandeln', 'category' => 'social', 'primary_attribute' => 'CH', 'secondary_attribute' => 'IN', 'sort_order' => 2],
        ['id' => 'diplomatie', 'external_id' => 'diplomatie', 'name' => 'Diplomatie', 'category' => 'social', 'primary_attribute' => 'EM', 'secondary_attribute' => 'CH', 'sort_order' => 3],
        ['id' => 'einschuechtern', 'external_id' => 'einschuechtern', 'name' => 'Einschüchtern', 'category' => 'social', 'primary_attribute' => 'WI', 'secondary_attribute' => 'CH', 'sort_order' => 4],
        ['id' => 'anfuehren', 'external_id' => 'anfuehren', 'name' => 'Anführen', 'category' => 'social', 'primary_attribute' => 'CH', 'secondary_attribute' => 'WI', 'sort_order' => 5],
        ['id' => 'luegen', 'external_id' => 'luegen', 'name' => 'Lügen', 'category' => 'social', 'primary_attribute' => 'CH', 'secondary_attribute' => 'KR', 'sort_order' => 6],
        ['id' => 'menschenkenntnis', 'external_id' => 'menschenkenntnis', 'name' => 'Menschenkenntnis', 'category' => 'social', 'primary_attribute' => 'EM', 'secondary_attribute' => 'IT', 'sort_order' => 7],

        ['id' => 'athletik', 'external_id' => 'athletik', 'name' => 'Athletik', 'category' => 'physical', 'primary_attribute' => 'ST', 'secondary_attribute' => 'WI', 'sort_order' => 8],
        ['id' => 'klettern', 'external_id' => 'klettern', 'name' => 'Klettern', 'category' => 'physical', 'primary_attribute' => 'ST', 'secondary_attribute' => 'BW', 'sort_order' => 9],
        ['id' => 'akrobatik', 'external_id' => 'akrobatik', 'name' => 'Akrobatik', 'category' => 'physical', 'primary_attribute' => 'BW', 'secondary_attribute' => 'GE', 'sort_order' => 10],
        ['id' => 'balance', 'external_id' => 'balance', 'name' => 'Balance', 'category' => 'physical', 'primary_attribute' => 'BW', 'secondary_attribute' => 'WI', 'sort_order' => 11],
        ['id' => 'schleichen', 'external_id' => 'schleichen', 'name' => 'Schleichen', 'category' => 'physical', 'primary_attribute' => 'BW', 'secondary_attribute' => 'IT', 'sort_order' => 12],
        ['id' => 'sprinten', 'external_id' => 'sprinten', 'name' => 'Sprinten', 'category' => 'physical', 'primary_attribute' => 'BW', 'secondary_attribute' => 'ST', 'sort_order' => 13],
        ['id' => 'schwimmen', 'external_id' => 'schwimmen', 'name' => 'Schwimmen', 'category' => 'physical', 'primary_attribute' => 'ST', 'secondary_attribute' => 'WI', 'sort_order' => 14],
        ['id' => 'werfen', 'external_id' => 'werfen', 'name' => 'Werfen', 'category' => 'physical', 'primary_attribute' => 'GE', 'secondary_attribute' => 'ST', 'sort_order' => 15],
        ['id' => 'reiten', 'external_id' => 'reiten', 'name' => 'Reiten', 'category' => 'physical', 'primary_attribute' => 'EM', 'secondary_attribute' => 'BW', 'sort_order' => 16],

        ['id' => 'geschichte', 'external_id' => 'geschichte', 'name' => 'Geschichte', 'category' => 'knowledge', 'primary_attribute' => 'IT', 'secondary_attribute' => 'IN', 'sort_order' => 17],
        ['id' => 'naturkunde', 'external_id' => 'naturkunde', 'name' => 'Naturkunde', 'category' => 'knowledge', 'primary_attribute' => 'IT', 'secondary_attribute' => 'WA', 'sort_order' => 18],
        ['id' => 'heilkunde', 'external_id' => 'heilkunde', 'name' => 'Heilkunde', 'category' => 'knowledge', 'primary_attribute' => 'IN', 'secondary_attribute' => 'EM', 'sort_order' => 19],
        ['id' => 'psychologie', 'external_id' => 'psychologie', 'name' => 'Psychologie', 'category' => 'knowledge', 'primary_attribute' => 'EM', 'secondary_attribute' => 'IN', 'sort_order' => 20],
        ['id' => 'mathematik', 'external_id' => 'mathematik', 'name' => 'Mathematik', 'category' => 'knowledge', 'primary_attribute' => 'IN', 'secondary_attribute' => 'WI', 'sort_order' => 21],
        ['id' => 'technik', 'external_id' => 'technik', 'name' => 'Technik', 'category' => 'knowledge', 'primary_attribute' => 'IN', 'secondary_attribute' => 'GE', 'sort_order' => 22],
        ['id' => 'recherche', 'external_id' => 'recherche', 'name' => 'Recherche', 'category' => 'knowledge', 'primary_attribute' => 'WA', 'secondary_attribute' => 'IN', 'sort_order' => 23],
        ['id' => 'sprachen', 'external_id' => 'sprachen', 'name' => 'Sprachen', 'category' => 'knowledge', 'primary_attribute' => 'IN', 'secondary_attribute' => 'CH', 'sort_order' => 24],

        ['id' => 'zimmerer', 'external_id' => 'zimmerer', 'name' => 'Zimmerer', 'category' => 'craft', 'primary_attribute' => 'GE', 'secondary_attribute' => 'IN', 'sort_order' => 25],
        ['id' => 'grobschmied', 'external_id' => 'grobschmied', 'name' => 'Grobschmied', 'category' => 'craft', 'primary_attribute' => 'ST', 'secondary_attribute' => 'WI', 'sort_order' => 26],
        ['id' => 'plattner', 'external_id' => 'plattner', 'name' => 'Plattner', 'category' => 'craft', 'primary_attribute' => 'GE', 'secondary_attribute' => 'IN', 'sort_order' => 27],
        ['id' => 'goldschmied', 'external_id' => 'goldschmied', 'name' => 'Goldschmied', 'category' => 'craft', 'primary_attribute' => 'GE', 'secondary_attribute' => 'KR', 'sort_order' => 28],
        ['id' => 'gerber', 'external_id' => 'gerber', 'name' => 'Gerber', 'category' => 'craft', 'primary_attribute' => 'WA', 'secondary_attribute' => 'GE', 'sort_order' => 29],
        ['id' => 'weber', 'external_id' => 'weber', 'name' => 'Weber', 'category' => 'craft', 'primary_attribute' => 'GE', 'secondary_attribute' => 'KR', 'sort_order' => 30],
        ['id' => 'alchemist', 'external_id' => 'alchemist', 'name' => 'Alchemist', 'category' => 'craft', 'primary_attribute' => 'IN', 'secondary_attribute' => 'WA', 'sort_order' => 31],
        ['id' => 'gourmet', 'external_id' => 'gourmet', 'name' => 'Gourmet', 'category' => 'craft', 'primary_attribute' => 'KR', 'secondary_attribute' => 'WA', 'sort_order' => 32],
        ['id' => 'minenarbeiter', 'external_id' => 'minenarbeiter', 'name' => 'Minenarbeiter', 'category' => 'craft', 'primary_attribute' => 'ST', 'secondary_attribute' => 'WA', 'sort_order' => 33],
        ['id' => 'gaertner', 'external_id' => 'gaertner', 'name' => 'Gärtner', 'category' => 'craft', 'primary_attribute' => 'WA', 'secondary_attribute' => 'IT', 'sort_order' => 34],
        ['id' => 'fischer', 'external_id' => 'fischer', 'name' => 'Fischer', 'category' => 'craft', 'primary_attribute' => 'IT', 'secondary_attribute' => 'WA', 'sort_order' => 35],

        ['id' => 'kochen', 'external_id' => 'kochen', 'name' => 'Kochen', 'category' => 'everyday', 'primary_attribute' => 'KR', 'secondary_attribute' => 'IT', 'sort_order' => 36],
        ['id' => 'backen', 'external_id' => 'backen', 'name' => 'Backen', 'category' => 'everyday', 'primary_attribute' => 'WA', 'secondary_attribute' => 'KR', 'sort_order' => 37],
        ['id' => 'gartenbau', 'external_id' => 'gartenbau', 'name' => 'Gartenbau', 'category' => 'everyday', 'primary_attribute' => 'IT', 'secondary_attribute' => 'WA', 'sort_order' => 38],
        ['id' => 'angeln', 'external_id' => 'angeln', 'name' => 'Angeln', 'category' => 'everyday', 'primary_attribute' => 'WA', 'secondary_attribute' => 'IT', 'sort_order' => 39],
        ['id' => 'jagen', 'external_id' => 'jagen', 'name' => 'Jagen', 'category' => 'everyday', 'primary_attribute' => 'WA', 'secondary_attribute' => 'BW', 'sort_order' => 40],
        ['id' => 'musizieren', 'external_id' => 'musizieren', 'name' => 'Musizieren', 'category' => 'everyday', 'primary_attribute' => 'CH', 'secondary_attribute' => 'KR', 'sort_order' => 41],
        ['id' => 'tanzen', 'external_id' => 'tanzen', 'name' => 'Tanzen', 'category' => 'everyday', 'primary_attribute' => 'BW', 'secondary_attribute' => 'CH', 'sort_order' => 42],
        ['id' => 'malen', 'external_id' => 'malen', 'name' => 'Malen', 'category' => 'everyday', 'primary_attribute' => 'KR', 'secondary_attribute' => 'GE', 'sort_order' => 43],
        ['id' => 'schreiben', 'external_id' => 'schreiben', 'name' => 'Schreiben', 'category' => 'everyday', 'primary_attribute' => 'KR', 'secondary_attribute' => 'IN', 'sort_order' => 44],
        ['id' => 'navigation', 'external_id' => 'navigation', 'name' => 'Navigation', 'category' => 'everyday', 'primary_attribute' => 'IT', 'secondary_attribute' => 'WA', 'sort_order' => 45],
        ['id' => 'erste_hilfe', 'external_id' => 'erste_hilfe', 'name' => 'Erste Hilfe', 'category' => 'everyday', 'primary_attribute' => 'EM', 'secondary_attribute' => 'IN', 'sort_order' => 46],
        ['id' => 'tierpfleger', 'external_id' => 'tierpfleger', 'name' => 'Tierpfleger', 'category' => 'everyday', 'primary_attribute' => 'EM', 'secondary_attribute' => 'IT', 'sort_order' => 47],
    ];

    private CharacterAttributeCalculator $attributeCalculator;

    public function __construct(private PDO $pdo)
    {
        $this->attributeCalculator = new CharacterAttributeCalculator();
        $this->ensureSchema();
    }

    /** @return list<array<string,mixed>> */
    public function definitions(): array
    {
        $this->ensureSchema();
        return $this->pdo->query('SELECT id,external_id,name,category,primary_attribute,secondary_attribute,sort_order FROM skill_definitions ORDER BY sort_order,id')->fetchAll();
    }

    public function isInitialized(int $characterId): bool
    {
        $statement = $this->pdo->prepare('SELECT skills_initialized_at FROM characters WHERE id=?');
        $statement->execute([$characterId]);
        $value = $statement->fetchColumn();
        return $value !== false && $value !== null;
    }

    /** @return list<array<string,mixed>> */
    public function getCharacterSkills(int $characterId): array
    {
        $this->ensureSchema();
        $query = $this->pdo->prepare('SELECT sd.id AS skill_definition_id,sd.external_id,sd.name,sd.category,sd.primary_attribute,sd.secondary_attribute,sd.sort_order,cs.base_value,cs.value,cs.bonus FROM character_skills cs JOIN skill_definitions sd ON sd.id=cs.skill_definition_id WHERE cs.character_id=? ORDER BY sd.sort_order,sd.id');
        $query->execute([$characterId]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['base_value'] = (int)$row['base_value'];
            $row['value'] = (int)$row['value'];
            $row['bonus'] = (int)$row['bonus'];
            $row['modifier'] = $this->calculateModifier($row['value']);
        }
        unset($row);
        return $rows;
    }

    public function calculateModifier(int $value): int
    {
        return $this->attributeCalculator->calculateModifier($value);
    }

    public function calculateBaseValue(int $characterId, array $definition): int
    {
        $query = $this->pdo->prepare('SELECT attribute_code,value FROM character_attributes WHERE character_id=? AND attribute_code IN (?,?)');
        $query->execute([$characterId, $definition['primary_attribute'], $definition['secondary_attribute']]);
        $attributes = [];
        foreach ($query->fetchAll() as $row) $attributes[(string)$row['attribute_code']] = (int)$row['value'];
        if (!isset($attributes[$definition['primary_attribute']], $attributes[$definition['secondary_attribute']])) {
            throw new RuntimeException('Für die Fertigkeitsinitialisierung fehlen gespeicherte Attribute.');
        }
        return (int)floor((($attributes[$definition['primary_attribute']] * 2) + $attributes[$definition['secondary_attribute']]) / 3);
    }

    /** @return list<array<string,mixed>> */
    public function initializeCharacterSkills(int $characterId): array
    {
        $this->ensureSchema();
        $state = $this->pdo->prepare('SELECT skills_initialized_at FROM characters WHERE id=?');
        $state->execute([$characterId]);
        $initializedAt = $state->fetchColumn();
        if ($initializedAt === false) throw new RuntimeException('Charakter nicht gefunden.');
        if ($initializedAt !== null) return $this->getCharacterSkills($characterId);

        $ownedTransaction = !$this->pdo->inTransaction();
        if ($ownedTransaction) $this->pdo->beginTransaction();
        try {
            $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = $mysql
                ? 'INSERT INTO character_skills (character_id,skill_definition_id,base_value,value,bonus) VALUES (?,?,?,?,0) ON DUPLICATE KEY UPDATE base_value=VALUES(base_value),value=VALUES(value)'
                : 'INSERT INTO character_skills (character_id,skill_definition_id,base_value,value,bonus) VALUES (?,?,?,?,0) ON CONFLICT(character_id,skill_definition_id) DO NOTHING';
            $insert = $this->pdo->prepare($sql);
            foreach ($this->definitions() as $definition) {
                $base = $this->calculateBaseValue($characterId, $definition);
                $insert->execute([$characterId, $definition['id'], $base, $base]);
            }
            $this->pdo->prepare('UPDATE characters SET skills_initialized_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$characterId]);
            if ($ownedTransaction) $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($ownedTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->getCharacterSkills($characterId);
    }

    /** @param array<int,array<string,mixed>> $skills */
    public function applySubmittedSkills(int $characterId, array $skills): array
    {
        $this->ensureSchema();
        $state = $this->pdo->prepare('SELECT skills_initialized_at FROM characters WHERE id=?');
        $state->execute([$characterId]);
        $initializedAt = $state->fetchColumn();
        if ($initializedAt === false) throw new RuntimeException('Charakter nicht gefunden.');
        if ($initializedAt === null) throw new RuntimeException('Fertigkeiten müssen zuerst initialisiert werden.');
        foreach ($skills as $skill) {
            if (!is_array($skill)) throw new RuntimeException('Ungültige Fertigkeitsdaten.');
            $definitionId = trim((string)($skill['skill_definition_id'] ?? $skill['id'] ?? ''));
            if ($definitionId === '') throw new RuntimeException('Fertigkeitsdefinition fehlt.');
            $value = $this->integerValue($skill['value'] ?? null, 'Fertigkeitswerte');
            $bonus = $this->integerValue($skill['bonus'] ?? 0, 'Fertigkeitsboni', false);
            $exists = $this->pdo->prepare('SELECT 1 FROM skill_definitions WHERE id=?');
            $exists->execute([$definitionId]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Unbekannte Fertigkeit: ' . $definitionId);
            $skillExists = $this->pdo->prepare('SELECT 1 FROM character_skills WHERE character_id=? AND skill_definition_id=?');
            $skillExists->execute([$characterId, $definitionId]);
            if (!$skillExists->fetchColumn()) throw new RuntimeException('Fertigkeit ist für diesen Charakter nicht initialisiert: ' . $definitionId);
            $update = $this->pdo->prepare('UPDATE character_skills SET value=?,bonus=? WHERE character_id=? AND skill_definition_id=?');
            $update->execute([$value, $bonus, $characterId, $definitionId]);
        }
        return $this->getCharacterSkills($characterId);
    }

    /** @return array<string,mixed> */
    public function updateSkill(int $characterId, string $skillDefinitionId, array $payload): array
    {
        $rows = $this->applySubmittedSkills($characterId, [[
            'skill_definition_id' => $skillDefinitionId,
            'value' => $payload['value'] ?? null,
            'bonus' => $payload['bonus'] ?? 0,
        ]]);
        foreach ($rows as $row) if ((string)$row['skill_definition_id'] === $skillDefinitionId) return $row;
        throw new RuntimeException('Fertigkeit nicht gefunden.');
    }

    private function integerValue(mixed $value, string $label, bool $validateRange = true): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) throw new RuntimeException($label . ' müssen ganze Zahlen sein.');
        if ($validateRange && ($parsed < CharacterAttributeCalculator::MIN_VALUE || $parsed > CharacterAttributeCalculator::MAX_VALUE)) {
            throw new RuntimeException($label . ' müssen ganze Zahlen zwischen 1 und 100 sein.');
        }
        return (int)$parsed;
    }

    private function ensureSchema(): void
    {
        static $ensured = [];
        $key = spl_object_id($this->pdo);
        if (isset($ensured[$key])) return;
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'VARCHAR(190)' : 'TEXT';
        $integer = $mysql ? 'INT' : 'INTEGER';
        $timestamp = $mysql ? 'DATETIME NULL' : 'DATETIME NULL';
        $createdAt = $mysql ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TEXT';
        $characterId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS skill_definitions (id {$id} PRIMARY KEY,external_id {$id} NOT NULL UNIQUE,name VARCHAR(190) NOT NULL,category VARCHAR(30) NOT NULL,primary_attribute VARCHAR(2) NOT NULL,secondary_attribute VARCHAR(2) NOT NULL,sort_order {$integer} NOT NULL,created_at {$createdAt},updated_at {$createdAt})");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS character_skills (character_id {$characterId} NOT NULL,skill_definition_id {$id} NOT NULL,base_value {$integer} NOT NULL,value {$integer} NOT NULL,bonus {$integer} NOT NULL DEFAULT 0,created_at {$createdAt},updated_at {$createdAt},PRIMARY KEY(character_id,skill_definition_id))");
        try {
            $this->pdo->query('SELECT skills_initialized_at FROM characters LIMIT 1');
        } catch (\Throwable) {
            $this->pdo->exec('ALTER TABLE characters ADD COLUMN skills_initialized_at ' . $timestamp);
        }
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $seed = $mysql
            ? 'INSERT INTO skill_definitions (id,external_id,name,category,primary_attribute,secondary_attribute,sort_order) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE external_id=VALUES(external_id),name=VALUES(name),category=VALUES(category),primary_attribute=VALUES(primary_attribute),secondary_attribute=VALUES(secondary_attribute),sort_order=VALUES(sort_order)'
            : 'INSERT INTO skill_definitions (id,external_id,name,category,primary_attribute,secondary_attribute,sort_order) VALUES (?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET external_id=excluded.external_id,name=excluded.name,category=excluded.category,primary_attribute=excluded.primary_attribute,secondary_attribute=excluded.secondary_attribute,sort_order=excluded.sort_order';
        $insert = $this->pdo->prepare($seed);
        foreach (self::DEFINITIONS as $definition) {
            $insert->execute([$definition['id'], $definition['external_id'], $definition['name'], $definition['category'], $definition['primary_attribute'], $definition['secondary_attribute'], $definition['sort_order']]);
        }
        $ensured[$key] = true;
    }
}
