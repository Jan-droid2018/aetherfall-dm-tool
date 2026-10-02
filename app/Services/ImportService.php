<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use Aetherfall\Support\Json;
use PDO;
use Throwable;

final class ImportService
{
    private array $counts = [
        'Klassen' => 0, 'Fähigkeiten' => 0, 'Kreaturen' => 0,
        'Kreaturenstufen' => 0, 'Kreaturenaktionen' => 0, 'Bosse' => 0,
        'Bossstufen' => 0, 'Bossaktionen' => 0, 'Elemente' => 0, 'Zauber' => 0,
        'Waffen' => 0, 'Waffen neu' => 0, 'Waffen aktualisiert' => 0, 'Waffen übersprungen' => 0,
        'Waffenprofile' => 0, 'Mehrprofil-Waffen' => 0, 'Waffenvariablen' => 0,
        'Klassenressourcen' => 0, 'Klassenaktionen' => 0, 'Klassenwaffenprofile' => 0,
        'Rüstungen' => 0, 'Rüstungen neu' => 0, 'Rüstungen aktualisiert' => 0, 'Rüstungen übersprungen' => 0,
        'Schilde' => 0, 'Magiefoki' => 0, 'Magiefoki neu' => 0, 'Magiefoki aktualisiert' => 0,
        'Magiefoki übersprungen' => 0, 'Elementarfoki' => 0, 'Sonderfoki' => 0,
    ];
    private array $warnings = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function importAll(): array
    {
        $this->ensureWeaponTable();
        $this->ensureEquipmentTables();
        $this->ensureClassActionTables();
        $this->pdo->beginTransaction();
        try {
            $this->importClasses();
            $this->seedClassAdjustments();
            $this->importSpells();
            $this->importWeapons();
            $this->importArmors();
            $this->importMagicFoci();
            $this->importCreatures();
            $this->importBosses();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['counts' => $this->counts, 'warnings' => $this->warnings];
    }

    private function files(string $area): array
    {
        return array_values(array_filter(
            glob(BASE_PATH . "/json/{$area}/*.json") ?: [],
            static fn(string $file): bool => basename($file) !== '_manifest.json'
        ));
    }

    private function ensureWeaponTable(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $raw = $mysql ? 'LONGTEXT' : 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS weapons (id VARCHAR(190) PRIMARY KEY,name VARCHAR(255) NOT NULL,display_name VARCHAR(255) NULL,subtitle VARCHAR(255) NULL,base_weapon_type VARCHAR(190) NULL,category VARCHAR(190) NULL,quality VARCHAR(80) NULL,item_level INTEGER NULL,is_magical INTEGER NOT NULL DEFAULT 0,is_elemental INTEGER NOT NULL DEFAULT 0,elements_json TEXT NULL,core_die VARCHAR(80) NULL,handling VARCHAR(80) NULL,range_text VARCHAR(190) NULL,damage_type VARCHAR(190) NULL,weight VARCHAR(80) NULL,attack_attribute VARCHAR(190) NULL,attack_formula TEXT NULL,damage_formula TEXT NULL,formula_variables_json TEXT NULL,critical_modification TEXT NULL,special_properties TEXT NULL,active_ability TEXT NULL,class_restriction VARCHAR(190) NULL,appearance TEXT NULL,schema_version VARCHAR(80) NOT NULL,source_file VARCHAR(255) NOT NULL,source_hash VARCHAR(64) NOT NULL,raw_json {$raw} NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        try { $this->pdo->query('SELECT formula_variables_json FROM weapons LIMIT 1'); } catch (Throwable) { try { $this->pdo->exec('ALTER TABLE weapons ADD COLUMN formula_variables_json TEXT NULL'); } catch (Throwable) {} }
        $profileId = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS weapon_combat_profiles (id {$profileId},weapon_id VARCHAR(190) NOT NULL,profile_key VARCHAR(80) NOT NULL,label VARCHAR(190) NOT NULL,handling VARCHAR(190) NULL,attack_formula TEXT NULL,attack_attribute VARCHAR(190) NULL,damage_formula TEXT NULL,damage_type VARCHAR(190) NULL,sort_order INTEGER NOT NULL DEFAULT 0,raw_json {$raw} NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(weapon_id,profile_key))");
    }

    private function ensureEquipmentTables(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS armors (id VARCHAR(190) PRIMARY KEY,external_id VARCHAR(190) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,display_name VARCHAR(255) NULL,item_kind VARCHAR(80) NOT NULL,base_item_name VARCHAR(255) NULL,armor_slot VARCHAR(20) NULL,armor_archetype VARCHAR(190) NULL,quality VARCHAR(80) NULL,item_level INTEGER NULL,is_magical INTEGER NOT NULL DEFAULT 0,physical_defense DECIMAL(12,2) NULL,magical_defense DECIMAL(12,2) NULL,shield_class VARCHAR(80) NULL,resistances_json {$text} NULL,elements_json {$text} NULL,special_properties {$text} NULL,active_ability {$text} NULL,class_binding {$text} NULL,appearance {$text} NULL,schema_version VARCHAR(80) NOT NULL,source_file VARCHAR(255) NOT NULL,source_hash VARCHAR(64) NOT NULL,raw_json {$text} NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS magic_foci (id VARCHAR(190) PRIMARY KEY,external_id VARCHAR(190) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,subtitle VARCHAR(255) NULL,display_name VARCHAR(255) NULL,item_type VARCHAR(190) NULL,base_focus_type VARCHAR(190) NULL,category VARCHAR(190) NULL,quality VARCHAR(80) NULL,item_level INTEGER NULL,is_magical INTEGER NOT NULL DEFAULT 0,standard_magic_attack TEXT NULL,magic_attack TEXT NULL,final_magic_attack TEXT NULL,handling VARCHAR(190) NULL,magic_attribute VARCHAR(190) NULL,weight VARCHAR(80) NULL,element_binding {$text} NULL,is_elemental INTEGER NOT NULL DEFAULT 0,attack_roll TEXT NULL,critical_modification TEXT NULL,class_binding {$text} NULL,active_ability {$text} NULL,special_properties {$text} NULL,effect_calculation {$text} NULL,spell_interaction {$text} NULL,lore {$text} NULL,special_rules {$text} NULL,schema_version VARCHAR(80) NOT NULL,source_file VARCHAR(255) NOT NULL,source_hash VARCHAR(64) NOT NULL,raw_json {$text} NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    }

    private function normalizeArmorSlot(mixed $slot): ?string
    {
        $value = mb_strtolower(trim((string)$slot));
        $value = strtr($value, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
        $value = preg_replace('/\s*\/.*$/', '', $value) ?? $value;
        return match ($value) {
            'head', 'kopf', 'helm', 'helmet' => 'head',
            'chest', 'brust', 'torso' => 'chest',
            'hands', 'hand', 'haende', 'hande', 'arme', 'hands/arme' => 'hands',
            'legs', 'beine' => 'legs',
            'feet', 'fuesse', 'fusse' => 'feet',
            default => null,
        };
    }

    private function importArmors(): void
    {
        $sql = 'INSERT INTO armors (id,external_id,name,display_name,item_kind,base_item_name,armor_slot,armor_archetype,quality,item_level,is_magical,physical_defense,magical_defense,shield_class,resistances_json,elements_json,special_properties,active_ability,class_binding,appearance,schema_version,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 24, '?')) . ') ON DUPLICATE KEY UPDATE external_id=VALUES(external_id),name=VALUES(name),display_name=VALUES(display_name),item_kind=VALUES(item_kind),base_item_name=VALUES(base_item_name),armor_slot=VALUES(armor_slot),armor_archetype=VALUES(armor_archetype),quality=VALUES(quality),item_level=VALUES(item_level),is_magical=VALUES(is_magical),physical_defense=VALUES(physical_defense),magical_defense=VALUES(magical_defense),shield_class=VALUES(shield_class),resistances_json=VALUES(resistances_json),elements_json=VALUES(elements_json),special_properties=VALUES(special_properties),active_ability=VALUES(active_ability),class_binding=VALUES(class_binding),appearance=VALUES(appearance),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $stmt = $this->pdo->prepare($sql);
        $existing = $this->pdo->prepare('SELECT source_hash FROM armors WHERE id=?');
        foreach ($this->files('armors') as $file) {
            $data = Json::decodeFile($file); $raw = (string)file_get_contents($file); $hash = hash('sha256', $raw); $id = (string)($data['id'] ?? '');
            if ($id === '') { $this->warnings[] = 'Rüstungsdatei ohne id: ' . basename($file); continue; }
            $existing->execute([$id]); $oldHash = $existing->fetchColumn(); $this->counts['Rüstungen']++;
            $itemKind = (string)($data['item_kind'] ?? 'armor'); $slotData = $data['slot'] ?? []; $slotKey = is_array($slotData) ? ($slotData['key'] ?? $slotData['name'] ?? null) : $slotData;
            $isShield = $itemKind === 'shield' || $this->normalizeArmorSlot($slotKey) === null && mb_stripos((string)$slotKey, 'schild') !== false;
            if ($isShield) $this->counts['Schilde']++;
            if ($oldHash !== false && (string)$oldHash === $hash) { $this->counts['Rüstungen übersprungen']++; continue; }
            $defense = is_array($data['defense'] ?? null) ? $data['defense'] : [];
            $physical = $defense['physical']['value'] ?? $defense['physical'] ?? null; $magical = $defense['magical']['general'] ?? $defense['magical']['value'] ?? $defense['magical'] ?? null;
            $magicFlag = $data['magical']['value'] ?? $data['magical'] ?? false;
            $base = $data['base_item'] ?? [];
            $stmt->execute([
                $id, $id, (string)($data['name'] ?? $data['display_name'] ?? $id), $data['display_name'] ?? null, $itemKind,
                is_array($base) ? ($base['name'] ?? $base['armor'] ?? null) : null, $isShield ? null : $this->normalizeArmorSlot($slotKey),
                $data['armor_archetype'] ?? null, $data['quality'] ?? null, isset($data['item_level']) ? (int)$data['item_level'] : null,
                (int)(bool)$magicFlag, is_numeric($physical) ? (float)$physical : null, is_numeric($magical) ? (float)$magical : null,
                $data['shield_class'] ?? null, $this->jsonOrNull($data['resistances'] ?? null), $this->jsonOrNull($data['elements'] ?? null),
                $this->jsonOrNull($data['special_properties'] ?? null), $this->jsonOrNull($data['active_ability'] ?? null), $this->jsonOrNull($data['class_binding'] ?? null),
                $this->jsonOrNull($data['appearance'] ?? null), $data['schema_version'] ?? 'aetherfall.armors.v1', $data['source_file'] ?? basename($file), $hash, $raw,
            ]);
            $this->counts[$oldHash === false ? 'Rüstungen neu' : 'Rüstungen aktualisiert']++;
        }
    }

    private function importMagicFoci(): void
    {
        $sql = 'INSERT INTO magic_foci (id,external_id,name,subtitle,display_name,item_type,base_focus_type,category,quality,item_level,is_magical,standard_magic_attack,magic_attack,final_magic_attack,handling,magic_attribute,weight,element_binding,is_elemental,attack_roll,critical_modification,class_binding,active_ability,special_properties,effect_calculation,spell_interaction,lore,special_rules,schema_version,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 32, '?')) . ') ON DUPLICATE KEY UPDATE external_id=VALUES(external_id),name=VALUES(name),subtitle=VALUES(subtitle),display_name=VALUES(display_name),item_type=VALUES(item_type),base_focus_type=VALUES(base_focus_type),category=VALUES(category),quality=VALUES(quality),item_level=VALUES(item_level),is_magical=VALUES(is_magical),standard_magic_attack=VALUES(standard_magic_attack),magic_attack=VALUES(magic_attack),final_magic_attack=VALUES(final_magic_attack),handling=VALUES(handling),magic_attribute=VALUES(magic_attribute),weight=VALUES(weight),element_binding=VALUES(element_binding),is_elemental=VALUES(is_elemental),attack_roll=VALUES(attack_roll),critical_modification=VALUES(critical_modification),class_binding=VALUES(class_binding),active_ability=VALUES(active_ability),special_properties=VALUES(special_properties),effect_calculation=VALUES(effect_calculation),spell_interaction=VALUES(spell_interaction),lore=VALUES(lore),special_rules=VALUES(special_rules),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $stmt = $this->pdo->prepare($sql); $existing = $this->pdo->prepare('SELECT source_hash FROM magic_foci WHERE id=?');
        foreach ($this->files('magic-foci') as $file) {
            $data = Json::decodeFile($file); $raw = (string)file_get_contents($file); $hash = hash('sha256', $raw); $id = (string)($data['id'] ?? '');
            if ($id === '') { $this->warnings[] = 'Magiefokusdatei ohne id: ' . basename($file); continue; }
            $existing->execute([$id]); $oldHash = $existing->fetchColumn(); $this->counts['Magiefoki']++;
            $binding = $data['element_binding'] ?? []; $elements = is_array($binding) ? ($binding['elements'] ?? []) : [];
            $elemental = (bool)($data['is_elemental'] ?? false) || (is_array($elements) && count($elements) > 0);
            if ($elemental) $this->counts['Elementarfoki']++;
            $classBinding = $data['class_binding'] ?? []; $special = ($data['source_type'] ?? '') === 'special_focus' || (is_array($classBinding) && (bool)($classBinding['is_class_focus'] ?? false));
            if ($special) $this->counts['Sonderfoki']++;
            if ($oldHash !== false && (string)$oldHash === $hash) { $this->counts['Magiefoki übersprungen']++; continue; }
            $attack = is_array($data['magic_attack'] ?? null) ? $data['magic_attack'] : [];
            $magicFlag = $data['magical']['value'] ?? $data['magical'] ?? false;
            $stmt->execute([
                $id, $id, (string)($data['name'] ?? $data['display_name'] ?? $id), $data['subtitle'] ?? null, $data['display_name'] ?? null,
                $data['item_type'] ?? null, $data['base_focus_type'] ?? null, $data['category'] ?? null, $data['quality'] ?? null, isset($data['item_level']) ? (int)$data['item_level'] : null,
                (int)(bool)$magicFlag, $attack['standard'] ?? null, $this->jsonOrNull($data['magic_attack'] ?? null), $attack['final'] ?? ($attack['effective'] ?? null),
                $data['handling'] ?? null, $data['magic_attribute'] ?? null, $data['weight'] ?? null, $this->jsonOrNull($data['element_binding'] ?? null), (int)$elemental,
                $this->jsonOrNull($data['attack_roll'] ?? null), $this->jsonOrNull($data['critical_modification'] ?? null), $this->jsonOrNull($data['class_binding'] ?? null),
                $this->jsonOrNull($data['active_ability'] ?? null), $this->jsonOrNull($data['special_properties'] ?? null), $this->jsonOrNull($data['effect_calculation'] ?? null),
                $this->jsonOrNull($data['spell_interaction'] ?? null), $this->jsonOrNull($data['lore'] ?? null), $this->jsonOrNull($data['special_rules'] ?? null),
                $data['schema_version'] ?? 'aetherfall.magic-foci.v1', $data['source_file'] ?? basename($file), $hash, $raw,
            ]);
            $this->counts[$oldHash === false ? 'Magiefoki neu' : 'Magiefoki aktualisiert']++;
        }
    }

    /** These ALTERs keep an existing installation compatible with the expanded importer. */
    private function ensureClassActionTables(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        try { $this->pdo->exec("CREATE TABLE IF NOT EXISTS class_actions (id VARCHAR(220) PRIMARY KEY,class_id VARCHAR(120) NOT NULL,external_id VARCHAR(190) NOT NULL,name VARCHAR(255) NOT NULL,action_type VARCHAR(80) NOT NULL DEFAULT 'class_action',unlock_level INTEGER NOT NULL DEFAULT 1,attack_formula TEXT NULL,damage_formula TEXT NULL,damage_type VARCHAR(190) NULL,resource_cost TEXT NULL,resource_gain TEXT NULL,weapon_mode VARCHAR(190) NULL,description TEXT NULL,source_file VARCHAR(255) NULL,source_hash VARCHAR(64) NULL,raw_json {$text} NOT NULL,UNIQUE(class_id,external_id))"); } catch (Throwable) {}
        try { $this->pdo->exec("CREATE TABLE IF NOT EXISTS class_weapon_profiles (id VARCHAR(220) PRIMARY KEY,class_id VARCHAR(120) NOT NULL,profile_name VARCHAR(190) NOT NULL,unlock_level INTEGER NOT NULL DEFAULT 1,start_die VARCHAR(80) NULL,attack_formula TEXT NULL,damage_formula TEXT NULL,damage_type VARCHAR(190) NULL,handling VARCHAR(80) NULL,range_text VARCHAR(190) NULL,weight VARCHAR(80) NULL,description TEXT NULL,source_file VARCHAR(255) NULL,source_hash VARCHAR(64) NULL,raw_json {$text} NOT NULL,UNIQUE(class_id,profile_name))"); } catch (Throwable) {}
        // Resource IDs are scoped to their owning class.  A global resource_id
        // index would make two classes overwrite each other's definitions.
        foreach (['fk_character_resource_definition','fk_participant_resource_definition'] as $foreignKey) {
            foreach (['character_class_resources','combat_participant_resources'] as $table) {
                try { $this->pdo->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$foreignKey}"); } catch (Throwable) {}
            }
        }
        try { $this->pdo->exec('ALTER TABLE class_resources DROP PRIMARY KEY, ADD PRIMARY KEY (class_id,resource_id)'); } catch (Throwable) {}
        try { $this->pdo->exec('DROP INDEX uq_class_resource_id ON class_resources'); } catch (Throwable) {}
        try { $this->pdo->exec('ALTER TABLE combat_resource_transactions ADD COLUMN character_class_id BIGINT UNSIGNED NULL AFTER execution_id'); } catch (Throwable) {}
        try { $this->pdo->exec('ALTER TABLE combat_resource_transactions MODIFY character_class_id BIGINT UNSIGNED NULL'); } catch (Throwable) {}
        try { $this->pdo->exec('ALTER TABLE combat_participant_resources DROP INDEX uq_participant_resource, ADD UNIQUE KEY uq_participant_resource (combat_participant_id,character_class_id,class_resource_id)'); } catch (Throwable) {}
        try { $this->pdo->exec('ALTER TABLE combat_resource_transactions DROP INDEX uq_resource_execution, ADD UNIQUE KEY uq_resource_execution (combat_participant_id,execution_id,character_class_id,class_resource_id)'); } catch (Throwable) {}
        $columns = [
            'form'=>'TEXT NULL','maximum_formula'=>'TEXT NULL','start_value'=>'TEXT NULL','base_generation'=>'TEXT NULL',
            'generation'=>'TEXT NULL','consumption'=>'TEXT NULL','relief'=>'TEXT NULL','persistence'=>'TEXT NULL',
            'recovery'=>'TEXT NULL','stacking'=>'TEXT NULL','transfer'=>'TEXT NULL','loss_decay'=>'TEXT NULL',
            'visibility'=>'TEXT NULL','multiclass_boundary'=>'TEXT NULL','base_function'=>'TEXT NULL','rounding'=>'TEXT NULL',
            'source_file'=>'VARCHAR(255) NULL','source_hash'=>'VARCHAR(64) NULL',
        ];
        foreach ($columns as $column=>$definition) { try { $this->pdo->query("SELECT {$column} FROM class_resources LIMIT 1"); } catch (Throwable) { try { $this->pdo->exec("ALTER TABLE class_resources ADD COLUMN {$column} {$definition}"); } catch (Throwable) {} } }
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return is_string($value) ? $value : Json::encode($value);
    }

    /** Normalize every damage profile emitted by the JSON parser.  The source
     * field may use an ASCII hyphen, en/em dash or a structured formulas map. */
    private function weaponProfiles(array $data): array
    {
        $profiles = [];
        $add = function (string $key, mixed $formula, ?string $label = null, array $meta = []) use (&$profiles): void {
            if (!is_scalar($formula)) return;
            $formula = trim((string)$formula, " \t\r\n`.;:");
            if ($formula === '' || $formula === '-') return;
            $key = $this->weaponProfileKey($key);
            $profiles[$key] = array_merge($profiles[$key] ?? [
                'key' => $key, 'label' => $label ?: $this->weaponProfileLabel($key),
                'handling' => null, 'attack_formula' => null, 'attack_attribute' => null,
                'damage_formula' => null, 'damage_type' => null, 'sort_order' => count($profiles),
            ], array_filter([
                'label' => $label ?: null, 'handling' => $meta['handling'] ?? null,
                'attack_formula' => $meta['attack_formula'] ?? null, 'attack_attribute' => $meta['attack_attribute'] ?? null,
                'damage_type' => $meta['damage_type'] ?? null, 'damage_formula' => $formula,
            ], static fn($v) => $v !== null && $v !== ''));
        };
        $damage = $data['formulas']['damage'] ?? null;
        if (is_array($damage)) {
            foreach ($damage as $key => $formula) $add((string)$key, $formula);
        } elseif (is_scalar($damage)) {
            $add('default', $damage, 'Standard');
        }
        foreach (['combat_profiles', 'weapon_profiles'] as $field) {
            if (!is_array($data[$field] ?? null)) continue;
            foreach ($data[$field] as $key => $profile) {
                if (is_scalar($profile)) { $add((string)$key, $profile); continue; }
                if (is_array($profile)) $add((string)($profile['key'] ?? $key), $profile['damage_formula'] ?? null, $profile['label'] ?? null, $profile);
            }
        }
        foreach (array_merge((array)($data['raw_fields'] ?? []), (array)($data['extra_fields'] ?? [])) as $field => $value) {
            if (preg_match('/^Schadensformel\s*[-–—]\s*(.+)$/u', (string)$field, $match)) $add($match[1], $value);
            elseif (mb_strtolower(trim((string)$field)) === 'schadensformel') $add('default', $value, 'Standard');
        }
        $attack = $data['formulas']['attack'] ?? null;
        $attribute = $data['attributes']['attack'] ?? null;
        foreach ($profiles as &$profile) {
            $profile['attack_formula'] ??= $attack;
            $profile['attack_attribute'] ??= $attribute;
            $profile['handling'] ??= $data['handling'] ?? null;
            $profile['damage_type'] ??= $data['damage_type'] ?? null;
        }
        unset($profile);
        uasort($profiles, static fn(array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['key'], $b['key']));
        return array_values($profiles);
    }

    private function weaponProfileKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','–'=>'-','—'=>'-']);
        if (str_contains($value, 'einhaend') || str_contains($value, 'einhänd') || str_contains($value, 'one_handed')) return 'one_handed';
        if (str_contains($value, 'zweihänd') || str_contains($value, 'zweihand') || str_contains($value, 'zweihaend') || str_contains($value, 'two_handed')) return 'two_handed';
        if ($value === 'default' || $value === 'standard' || $value === 'schadensformel') return 'default';
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? 'profile';
        return trim($value, '_') ?: 'profile';
    }

    private function weaponProfileLabel(string $key): string
    {
        return match ($key) { 'default' => 'Standard', 'one_handed' => 'Einhändig', 'two_handed' => 'Zweihändig', default => ucwords(str_replace('_', ' ', $key)) };
    }

    private function weaponFormulaVariables(array $data): array
    {
        $variables = [];
        $core = $data['formulas']['core_value'] ?? null;
        if (is_scalar($core) && trim((string)$core) !== '') $variables['Kernwert'] = trim((string)$core, " \t\r\n`.;:");
        foreach (array_merge((array)($data['raw_fields'] ?? []), (array)($data['extra_fields'] ?? [])) as $field => $value) {
            $name = trim((string)$field); $formula = is_scalar($value) ? trim((string)$value, " \t\r\n`.;:") : '';
            if ($name === '' || $formula === '' || $formula === '-') continue;
            if (isset($variables[$name]) || in_array(mb_strtolower($name), ['schadensformel','angriffswurf','kernwürfel','kernwuerfel','regelwirkung'], true) || !preg_match('/^[\p{L}][\p{L}\d_-]{0,40}$/u', $name)) continue;
            if (preg_match('/(?:Modifikator|Bonus|floor|ceil|⌊|⌈)/iu', $formula)) $variables[$name] = $formula;
        }
        return $variables;
    }

    private function importWeapons(): void
    {
        $sql = 'INSERT INTO weapons (id,name,display_name,subtitle,base_weapon_type,category,quality,item_level,is_magical,is_elemental,elements_json,core_die,handling,range_text,damage_type,weight,attack_attribute,attack_formula,damage_formula,formula_variables_json,critical_modification,special_properties,active_ability,class_restriction,appearance,schema_version,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 29, '?')) . ') ON DUPLICATE KEY UPDATE name=VALUES(name),display_name=VALUES(display_name),subtitle=VALUES(subtitle),base_weapon_type=VALUES(base_weapon_type),category=VALUES(category),quality=VALUES(quality),item_level=VALUES(item_level),is_magical=VALUES(is_magical),is_elemental=VALUES(is_elemental),elements_json=VALUES(elements_json),core_die=VALUES(core_die),handling=VALUES(handling),range_text=VALUES(range_text),damage_type=VALUES(damage_type),weight=VALUES(weight),attack_attribute=VALUES(attack_attribute),attack_formula=VALUES(attack_formula),damage_formula=VALUES(damage_formula),formula_variables_json=VALUES(formula_variables_json),critical_modification=VALUES(critical_modification),special_properties=VALUES(special_properties),active_ability=VALUES(active_ability),class_restriction=VALUES(class_restriction),appearance=VALUES(appearance),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $stmt = $this->pdo->prepare($sql);
        $existing = $this->pdo->prepare('SELECT source_hash FROM weapons WHERE id=?');
        $files = $this->files('weapons');
        $manifestPath = BASE_PATH . '/json/weapons/_manifest.json';
        if (is_file($manifestPath)) {
            try {
                $manifest = Json::decodeFile($manifestPath);
                $expected = (int)($manifest['weapon_count'] ?? $manifest['count'] ?? 0);
                if ($expected > 0 && $expected !== count($files)) {
                    $this->warnings[] = "Waffenmanifest erwartet {$expected} Dateien, gefunden wurden " . count($files) . '.';
                }
            } catch (Throwable $e) {
                $this->warnings[] = 'Waffenmanifest konnte nicht gelesen werden: ' . $e->getMessage();
            }
        }
        foreach ($files as $file) {
            $data = Json::decodeFile($file);
            $raw = (string)file_get_contents($file);
            $hash = hash('sha256', $raw);
            $existing->execute([(string)$data['id']]);
            $oldHash = $existing->fetchColumn();
            $this->counts['Waffen']++;
            $magical = $data['magical']['value'] ?? $data['magical'] ?? false;
            $profiles = $this->weaponProfiles($data);
            $damage = null;
            foreach ($profiles as $profile) if ($profile['key'] === 'default') { $damage = $profile['damage_formula']; break; }
            if ($damage === null && count($profiles) === 1) $damage = $profiles[0]['damage_formula'];
            $variables = $this->weaponFormulaVariables($data);
            $attrs = $data['attributes'] ?? [];
            $binding = $data['class_binding'] ?? [];
            $stmt->execute([
                (string)$data['id'], (string)($data['name'] ?? $data['display_name'] ?? $data['id']), $data['display_name'] ?? null,
                $data['subtitle'] ?? null, $data['base_weapon_type'] ?? null, $data['category'] ?? null, $data['quality'] ?? null,
                isset($data['item_level']) ? (int)$data['item_level'] : null, (int)(bool)$magical, (int)(bool)($data['is_elemental'] ?? false),
                $this->jsonOrNull($data['elements'] ?? []), $data['core_dice']['raw'] ?? null, $data['handling'] ?? null,
                $data['range'] ?? null, $data['damage_type'] ?? null, $data['weight'] ?? null, $attrs['attack'] ?? null,
                $data['formulas']['attack'] ?? null, $damage, $this->jsonOrNull($variables), $this->jsonOrNull($data['critical_modification'] ?? null),
                $this->jsonOrNull($data['special_properties'] ?? null), $this->jsonOrNull($data['active_ability'] ?? null),
                $binding['class_name'] ?? null, $data['appearance'] ?? null, $data['schema_version'] ?? 'aetherfall.weapons.v1',
                $data['source_file'] ?? basename($file), $hash, $raw,
            ]);
            $this->pdo->prepare('DELETE FROM weapon_combat_profiles WHERE weapon_id=?')->execute([(string)$data['id']]);
            $profileStmt = $this->pdo->prepare('INSERT INTO weapon_combat_profiles (weapon_id,profile_key,label,handling,attack_formula,attack_attribute,damage_formula,damage_type,sort_order,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($profiles as $profile) {
                $profileStmt->execute([(string)$data['id'], $profile['key'], $profile['label'], $profile['handling'], $profile['attack_formula'], $profile['attack_attribute'], $profile['damage_formula'], $profile['damage_type'], $profile['sort_order'], $this->jsonOrNull($profile)]);
                $this->counts['Waffenprofile']++;
            }
            if (count($profiles) > 1) $this->counts['Mehrprofil-Waffen']++;
            if ($variables) $this->counts['Waffenvariablen']++;
            if ($oldHash !== false && (string)$oldHash === $hash) { $this->counts['Waffen übersprungen']++; continue; }
            $this->counts[$oldHash === false ? 'Waffen neu' : 'Waffen aktualisiert']++;
        }
    }

    private function importClasses(): void
    {
        $classSql = 'INSERT INTO classes (id,name,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $resourceSql = 'INSERT INTO class_resources (class_id,resource_id,name,form,maximum_formula,start_value,base_generation,generation,consumption,relief,persistence,recovery,stacking,transfer,loss_decay,visibility,multiclass_boundary,base_function,rounding,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 22, '?')) . ') ON DUPLICATE KEY UPDATE resource_id=VALUES(resource_id),name=VALUES(name),form=VALUES(form),maximum_formula=VALUES(maximum_formula),start_value=VALUES(start_value),base_generation=VALUES(base_generation),generation=VALUES(generation),consumption=VALUES(consumption),relief=VALUES(relief),persistence=VALUES(persistence),recovery=VALUES(recovery),stacking=VALUES(stacking),transfer=VALUES(transfer),loss_decay=VALUES(loss_decay),visibility=VALUES(visibility),multiclass_boundary=VALUES(multiclass_boundary),base_function=VALUES(base_function),rounding=VALUES(rounding),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $abilitySql = 'INSERT INTO abilities (id,class_id,unlock_level,number,name,type,focus,action_cost,trigger_requirement,costs,class_resource,usage_limit,range_text,target_text,area_text,duration_text,attack_roll,saving_throw,dc,attribute_reference,damage_type,calculation,effect,target_effect,status_effect,stacking,end_condition,scaling,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 31, '?')) . ') ON DUPLICATE KEY UPDATE class_id=VALUES(class_id),unlock_level=VALUES(unlock_level),number=VALUES(number),name=VALUES(name),type=VALUES(type),focus=VALUES(focus),action_cost=VALUES(action_cost),trigger_requirement=VALUES(trigger_requirement),costs=VALUES(costs),class_resource=VALUES(class_resource),usage_limit=VALUES(usage_limit),range_text=VALUES(range_text),target_text=VALUES(target_text),area_text=VALUES(area_text),duration_text=VALUES(duration_text),attack_roll=VALUES(attack_roll),saving_throw=VALUES(saving_throw),dc=VALUES(dc),attribute_reference=VALUES(attribute_reference),damage_type=VALUES(damage_type),calculation=VALUES(calculation),effect=VALUES(effect),target_effect=VALUES(target_effect),status_effect=VALUES(status_effect),stacking=VALUES(stacking),end_condition=VALUES(end_condition),scaling=VALUES(scaling),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $classStmt = $this->pdo->prepare($classSql);
        $resourceStmt = $this->pdo->prepare($resourceSql);
        $abilityStmt = $this->pdo->prepare($abilitySql);
        foreach ($this->files('classes') as $file) {
            $data = Json::decodeFile($file);
            $raw = (string) file_get_contents($file);
            $hash = hash('sha256', $raw);
            $classStmt->execute([$data['id'], $data['name'], $data['schema_version'], $data['source_file'] ?? basename($file), $hash, $raw]);
            $resourceDefinitions = $data['resources'] ?? ($data['resource'] ?? null);
            if (is_array($resourceDefinitions) && (array_key_exists('id', $resourceDefinitions) || array_keys($resourceDefinitions) !== range(0, count($resourceDefinitions) - 1))) $resourceDefinitions = [$resourceDefinitions];
            if (is_array($resourceDefinitions)) foreach ($resourceDefinitions as $resource) {
                if (!is_array($resource)) continue;
                $resourceStmt->execute([
                    $data['id'], $resource['id'] ?? $data['id'] . '-resource', $resource['name'] ?? 'Klassenressource',
                    $resource['form'] ?? null, $this->resourceMaximum($resource), $resource['start_value'] ?? null,
                    $resource['base_generation'] ?? ($resource['base_access'] ?? null), $resource['generation'] ?? null,
                    $resource['consumption'] ?? null, $resource['relief'] ?? null, $resource['persistence'] ?? null,
                    $resource['recovery'] ?? null, $resource['stacking'] ?? null, $resource['transfer'] ?? null,
                    $resource['loss_decay'] ?? null, $resource['visibility'] ?? ($resource['visibility_recognition'] ?? null),
                    $resource['multiclass_boundary'] ?? null, $resource['base_function'] ?? null, $resource['rounding'] ?? null,
                    $data['source_file'] ?? basename($file), $hash, Json::encode($resource)
                ]);
                $this->counts['Klassenressourcen']++;
            }
            $this->importClassMechanics($data, $file, $hash);
            foreach ($data['levels'] ?? [] as $level) {
                foreach ($level['abilities'] ?? [] as $ability) {
                    $abilityStmt->execute([
                        $ability['id'], $data['id'], (int) ($ability['level'] ?? $level['level']), (int) ($ability['number'] ?? 0), $ability['name'],
                        $ability['type'] ?? null, $ability['focus'] ?? null, $ability['action_cost'] ?? null, $ability['trigger_requirement'] ?? null,
                        $ability['costs'] ?? null, $ability['class_resource'] ?? null, $ability['usage_limit'] ?? null, $ability['range'] ?? null,
                        $ability['target'] ?? null, $ability['area'] ?? null, $ability['duration'] ?? null, $ability['attack_roll'] ?? null,
                        $ability['saving_throw'] ?? null, $ability['dc'] ?? null, $ability['attribute_reference'] ?? null, $ability['damage_type'] ?? null,
                        $ability['calculation'] ?? null, $ability['effect'] ?? null, $ability['target_effect'] ?? null, $ability['status_effect'] ?? null,
                        $ability['stacking'] ?? null, $ability['end_condition'] ?? null, $ability['scaling'] ?? null,
                        $data['source_file'] ?? basename($file), $hash, Json::encode($ability),
                    ]);
                    $this->counts['Fähigkeiten']++;
                }
            }
            $this->counts['Klassen']++;
        }
    }

    private function resourceMaximum(array $resource): ?string
    {
        if (isset($resource['maximum']) && is_string($resource['maximum'])) return $resource['maximum'];
        return isset($resource['maximum']) ? Json::encode($resource['maximum']) : null;
    }

    private function formulaFromText(mixed $value): ?string
    {
        if (!is_string($value) || trim($value)==='') return null;
        if (preg_match('/`([^`]+)`/u', $value, $m)) $value=$m[1];
        $value = preg_replace('/\bStärke-Modifikator\b/u','ST-Modifikator',$value) ?? $value;
        $value = preg_replace('/\bStärke-Bonus\b/u','ST-Bonus',$value) ?? $value;
        $value = preg_replace('/\bTaktbrecher-Stufe\b/u','Taktbrecher-Stufe',$value) ?? $value;
        return trim($value);
    }

    private function tableRows(array $section): array
    {
        $rows=[]; foreach (($section['tables'] ?? []) as $table) foreach (($table['rows'] ?? []) as $row) if (is_array($row)) $rows[]=$row; return $rows;
    }

    private function rowValue(array $rows, string $label): ?string
    {
        foreach ($rows as $row) if (isset($row[0]) && mb_stripos(strip_tags((string)$row[0]), $label)!==false) return isset($row[1])?(string)$row[1]:null;
        return null;
    }

    /** Normalize only clearly identifiable class mechanics; free-form rules remain in raw_json. */
    private function importClassMechanics(array $data, string $file, string $hash): void
    {
        $classId=(string)($data['id']??''); $sections=$data['class_sections']??[]; if (!$classId || !is_array($sections)) return;
        $base=null; $profiles=null; $examples=null;
        foreach ($sections as $section) { $title=(string)($section['title']??''); if (str_contains($title,'Klassenbasis')) $base=$section; if (str_contains($title,'Manifestationsmuster')) $profiles=$section; if (str_contains($title,'Beispiele für die Zuordnung')) $examples=$section; }
        if (!$base || !$profiles) return;
        $baseRows=$this->tableRows($base); $attack=$this->formulaFromText($this->rowValue($baseRows,'Angriffswurf')); $damage=$this->formulaFromText($this->rowValue($baseRows,'Körperangriff'));
        if (!$attack && !$damage || $this->rowValue($baseRows,'Taktangriff')===null) return;
        $sql="INSERT INTO class_actions (id,class_id,external_id,name,action_type,unlock_level,attack_formula,damage_formula,damage_type,resource_cost,resource_gain,weapon_mode,description,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),attack_formula=VALUES(attack_formula),damage_formula=VALUES(damage_formula),damage_type=VALUES(damage_type),description=VALUES(description),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)";
        $stmt=$this->pdo->prepare($sql); $stmt->execute(["{$classId}-normaler-angriff",$classId,'normaler-angriff','Normaler Angriff','class_action',1,$attack,$damage,'Schlag',null,null,'unbewaffnet',$this->rowValue($baseRows,'Taktangriff'),$data['source_file']??basename($file),$hash,Json::encode(['section'=>$base])]); $this->counts['Klassenaktionen']++;
        $stmt->execute(["{$classId}-exaltierte-waffe",$classId,'exaltierte-waffe','Exaltierte Waffe','class_action',1,$attack,null,null,null,null,'class_weapon','Taktangriff mit der Exaltierten Waffe.',$data['source_file']??basename($file),$hash,Json::encode(['section'=>$base])]); $this->counts['Klassenaktionen']++;
        $profileStmt=$this->pdo->prepare("INSERT INTO class_weapon_profiles (id,class_id,profile_name,unlock_level,start_die,attack_formula,damage_formula,damage_type,handling,range_text,weight,description,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_die=VALUES(start_die),attack_formula=VALUES(attack_formula),damage_formula=VALUES(damage_formula),damage_type=VALUES(damage_type),handling=VALUES(handling),range_text=VALUES(range_text),weight=VALUES(weight),description=VALUES(description),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)");
        foreach ($this->tableRows($profiles) as $row) { $name=trim(strip_tags((string)($row[0]??''))); if ($name===''||!str_contains($name,'profil')) continue; $profileName=preg_replace('/\s+/',' ',str_replace('**','',$name))??$name; $die=trim((string)($row[1]??'')); $profileDamage=$this->formulaFromText($row[3]??''); $profileStmt->execute(["{$classId}-".strtolower(str_replace(' ','-', $profileName)),$classId,$profileName,1,$die,$attack,$profileDamage,'Physisch',null,null,null,$row[2]??null,$data['source_file']??basename($file),$hash,Json::encode(['section'=>$profiles,'row'=>$row])]); $this->counts['Klassenwaffenprofile']++; }
        $this->pdo->prepare("UPDATE class_weapon_profiles SET damage_type='Hieb' WHERE class_id=?")->execute([$classId]);
        // The base action uses the documented full profile until a concrete
        // manifestation profile is selected in the tracker.
        $this->pdo->prepare("UPDATE class_actions SET damage_formula=(SELECT damage_formula FROM class_weapon_profiles WHERE class_id=? AND profile_name='Vollprofil' LIMIT 1),damage_type='Hieb' WHERE class_id=? AND external_id='exaltierte-waffe'")->execute([$classId,$classId]);
    }

    private function seedClassAdjustments(): void
    {
        $map = [
            'blutzeichner'=>['WI','WA','CH'],'fernzeittraeger'=>['IN','GE','EM'],'glanzraeuber'=>['WA','IN','ST'],
            'glutpfleger'=>['EM','WA','ST'],'heimkehrhueter'=>['IT','EM','ST'],'klingenchor'=>['BW','GE','ST'],
            'kraftschatten'=>['WA','IN','CH'],'kraftverteiler'=>['EM','WI','ST'],'leerenmantel'=>['WI','IT','CH'],
            'nachhallmeister'=>['WA','IN','ST'],'namensbrecher'=>['IN','WA','ST'],'opferkelch'=>['EM','CH','ST'],
            'relaistraeger'=>['IN','WA','ST'],'rudelrufer'=>['EM','IT','IN'],'scherbenhueter'=>['WI','EM','ST'],
            'schicksalsformer'=>['IT','KR','ST'],'schmerzloeser'=>['EM','WI','ST'],'sternensplitter'=>['IT','WI','ST'],
            'taktbrecher'=>['ST','BW','IN'],'tauhueter'=>['WA','IN','CH'],'tiefenpanzer'=>['ST','WI','BW'],
            'ursprungsvermaechtnis'=>['WI','EM','ST'],'waffenarchivar'=>['GE','IN','EM'],'wundensammler'=>['EM','WI','CH'],
            'zeitdaempfer'=>['IT','WI','ST'],'zornleiter'=>['CH','EM','BW'],
        ];
        $exists = $this->pdo->prepare('SELECT 1 FROM classes WHERE id=?');
        $stmt = $this->pdo->prepare('INSERT INTO class_attribute_adjustments (class_id,role,attribute_code,adjustment) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE attribute_code=VALUES(attribute_code),adjustment=VALUES(adjustment)');
        foreach ($map as $classId => $attributes) {
            $exists->execute([$classId]);
            if (!$exists->fetchColumn()) {
                $this->warnings[] = "Klassenattribut-Seed nicht zugeordnet: {$classId}";
                continue;
            }
            foreach ([['primary', $attributes[0], 2], ['secondary', $attributes[1], 1], ['penalty', $attributes[2], -2]] as $row) {
                $stmt->execute([$classId, ...$row]);
            }
        }
    }

    private function importSpells(): void
    {
        $elementStmt = $this->pdo->prepare('INSERT INTO spell_elements (id,name,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)');
        $spellSql = 'INSERT INTO spells (id,element_id,grade,number,name,effect_type,cast_time,trigger_requirement,mana_cost_percent,mana_cost_raw,usage_limit,range_text,target_text,area_text,duration_text,concentration,magic_attribute,attack_roll,saving_throw,dc,damage_type,calculation,rule_effect,target_effect,status_effect,stacking,end_condition,upcast,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 31, '?')) . ') ON DUPLICATE KEY UPDATE element_id=VALUES(element_id),grade=VALUES(grade),number=VALUES(number),name=VALUES(name),effect_type=VALUES(effect_type),cast_time=VALUES(cast_time),trigger_requirement=VALUES(trigger_requirement),mana_cost_percent=VALUES(mana_cost_percent),mana_cost_raw=VALUES(mana_cost_raw),usage_limit=VALUES(usage_limit),range_text=VALUES(range_text),target_text=VALUES(target_text),area_text=VALUES(area_text),duration_text=VALUES(duration_text),concentration=VALUES(concentration),magic_attribute=VALUES(magic_attribute),attack_roll=VALUES(attack_roll),saving_throw=VALUES(saving_throw),dc=VALUES(dc),damage_type=VALUES(damage_type),calculation=VALUES(calculation),rule_effect=VALUES(rule_effect),target_effect=VALUES(target_effect),status_effect=VALUES(status_effect),stacking=VALUES(stacking),end_condition=VALUES(end_condition),upcast=VALUES(upcast),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $spellStmt = $this->pdo->prepare($spellSql);
        foreach ($this->files('spells') as $file) {
            $data = Json::decodeFile($file); $raw = (string) file_get_contents($file); $hash = hash('sha256', $raw);
            $elementStmt->execute([$data['id'], $data['element'], $data['schema_version'], $data['source_file'] ?? basename($file), $hash, $raw]);
            foreach ($data['grades'] ?? [] as $grade) {
                foreach ($grade['spells'] ?? [] as $spell) {
                    $concentration = $spell['concentration']['value'] ?? null;
                    $spellStmt->execute([
                        $spell['id'], $data['id'], (int) $spell['grade'], (int) $spell['number'], $spell['name'], $spell['effect_type'] ?? null,
                        $spell['cast_time'] ?? null, $spell['trigger_requirement'] ?? null, $spell['mana_cost']['percent'] ?? null, $spell['mana_cost']['raw'] ?? null,
                        $spell['usage_limit'] ?? null, $spell['range'] ?? null, $spell['target'] ?? null, $spell['area'] ?? null, $spell['duration'] ?? null,
                        $concentration === null ? null : (int) $concentration, $spell['magic_attribute'] ?? null, $spell['attack_roll'] ?? null,
                        $spell['saving_throw'] ?? null, $spell['dc'] ?? null, $spell['damage_type'] ?? null, $spell['calculation'] ?? null,
                        $spell['rule_effect'] ?? null, $spell['target_effect'] ?? null, $spell['status_effect'] ?? null, $spell['stacking'] ?? null,
                        $spell['end_condition'] ?? null, $spell['upcast'] ?? null, $data['source_file'] ?? basename($file), $hash, Json::encode($spell),
                    ]);
                    $this->counts['Zauber']++;
                }
            }
            $this->counts['Elemente']++;
        }
    }

    private function importCreatures(): void
    {
        $resolver = new CombatProfileValueResolver();
        $entityStmt = $this->pdo->prepare('INSERT INTO creatures (id,name,challenge_rating,encounter_rank,archetype,elemental_affinity,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),challenge_rating=VALUES(challenge_rating),encounter_rank=VALUES(encounter_rank),archetype=VALUES(archetype),elemental_affinity=VALUES(elemental_affinity),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)');
        $levelStmt = $this->pdo->prepare('INSERT INTO creature_levels (creature_id,level,max_hp,physical_defense,magic_defense,attributes_json,combat_values_json,hp_calculation_json,elemental_resistances_json,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE max_hp=VALUES(max_hp),physical_defense=VALUES(physical_defense),magic_defense=VALUES(magic_defense),attributes_json=VALUES(attributes_json),combat_values_json=VALUES(combat_values_json),hp_calculation_json=VALUES(hp_calculation_json),elemental_resistances_json=VALUES(elemental_resistances_json),raw_json=VALUES(raw_json)');
        $actionStmt = $this->pdo->prepare('INSERT INTO creature_actions (id,creature_id,level,number,name,type,attack_data,damage_data,damage_type,defense_save,range_text,notes,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE creature_id=VALUES(creature_id),level=VALUES(level),number=VALUES(number),name=VALUES(name),type=VALUES(type),attack_data=VALUES(attack_data),damage_data=VALUES(damage_data),damage_type=VALUES(damage_type),defense_save=VALUES(defense_save),range_text=VALUES(range_text),notes=VALUES(notes),raw_json=VALUES(raw_json)');
        foreach ($this->files('creatures') as $file) {
            $d = Json::decodeFile($file); $raw = (string) file_get_contents($file); $hash = hash('sha256', $raw); $core = $d['core'] ?? [];
            $entityStmt->execute([$d['id'], $d['name'], $core['challenge_rating']['value'] ?? null, $core['encounter_rank'] ?? null, $core['archetype'] ?? null, $core['elemental_affinity'] ?? null, $d['schema_version'], $d['source_file'] ?? basename($file), $hash, $raw]);
            foreach ($d['levels'] ?? [] as $level) {
                $levelStmt->execute([$d['id'], $level['level'], $resolver->maxHp($level['combat_values'] ?? [], $level['hp_calculation'] ?? []), $resolver->physicalDefense($level['combat_values'] ?? []), $resolver->magicDefense($level['combat_values'] ?? []), Json::encode($level['attributes'] ?? []), Json::encode($level['combat_values'] ?? []), Json::encode($level['hp_calculation'] ?? []), Json::encode($level['elemental_resistances'] ?? []), Json::encode($level)]);
                $details = [];
                foreach ($level['action_details'] ?? [] as $detail) { $details[mb_strtolower($detail['name'])] = $detail; }
                foreach ($level['actions']['actions'] ?? [] as $action) {
                    $detail = $details[mb_strtolower($action['name'])] ?? [];
                    $actionStmt->execute([$action['id'], $d['id'], $level['level'], $action['number'] ?? 0, $action['name'], $action['type'] ?? $detail['type'] ?? null, $action['attack_or_dc'] ?? $detail['attack_roll'] ?? null, $action['damage_effect'] ?? $detail['damage'] ?? null, $action['damage_type'] ?? $detail['damage_type'] ?? null, $action['defense_save'] ?? $detail['defense'] ?? null, $action['range_area'] ?? $detail['range'] ?? null, $action['notes'] ?? $detail['rule_effect'] ?? null, Json::encode(['profile' => $action, 'detail' => $detail])]);
                    $this->counts['Kreaturenaktionen']++;
                }
                $this->counts['Kreaturenstufen']++;
            }
            $this->counts['Kreaturen']++;
        }
    }

    private function importBosses(): void
    {
        $resolver = new CombatProfileValueResolver();
        $entityStmt = $this->pdo->prepare('INSERT INTO bosses (id,name,challenge_rating,encounter_rank,archetype,elemental_affinity,phase_count,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),challenge_rating=VALUES(challenge_rating),encounter_rank=VALUES(encounter_rank),archetype=VALUES(archetype),elemental_affinity=VALUES(elemental_affinity),phase_count=VALUES(phase_count),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)');
        $levelStmt = $this->pdo->prepare('INSERT INTO boss_levels (boss_id,level,max_hp,physical_defense,magic_defense,attribute_profiles_json,combat_values_json,hp_calculation_json,elemental_resistances_json,phase_values_json,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE max_hp=VALUES(max_hp),physical_defense=VALUES(physical_defense),magic_defense=VALUES(magic_defense),attribute_profiles_json=VALUES(attribute_profiles_json),combat_values_json=VALUES(combat_values_json),hp_calculation_json=VALUES(hp_calculation_json),elemental_resistances_json=VALUES(elemental_resistances_json),phase_values_json=VALUES(phase_values_json),raw_json=VALUES(raw_json)');
        $actionStmt = $this->pdo->prepare('INSERT INTO boss_actions (id,boss_id,level,number,name,attack_data,damage_data,notes,shared_rule_json,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE boss_id=VALUES(boss_id),level=VALUES(level),number=VALUES(number),name=VALUES(name),attack_data=VALUES(attack_data),damage_data=VALUES(damage_data),notes=VALUES(notes),shared_rule_json=VALUES(shared_rule_json),raw_json=VALUES(raw_json)');
        foreach ($this->files('bosses') as $file) {
            $d = Json::decodeFile($file); $raw = (string) file_get_contents($file); $hash = hash('sha256', $raw); $core = $d['core'] ?? [];
            $entityStmt->execute([$d['id'], $d['name'], $core['challenge_rating']['value'] ?? null, $core['encounter_rank'] ?? null, $core['archetype'] ?? null, $core['elemental_affinity'] ?? null, $core['phase_count']['value'] ?? null, $d['schema_version'], $d['source_file'] ?? basename($file), $hash, $raw]);
            $shared = [];
            foreach ($d['shared_action_details'] ?? [] as $detail) { $shared[mb_strtolower($detail['name'])] = $detail; }
            foreach ($d['levels'] ?? [] as $level) {
                $levelStmt->execute([$d['id'], $level['level'], $resolver->maxHp($level['combat_values'] ?? [], $level['hp_calculation'] ?? []), $resolver->physicalDefense($level['combat_values'] ?? []), $resolver->magicDefense($level['combat_values'] ?? []), Json::encode($level['attribute_profiles'] ?? []), Json::encode($level['combat_values'] ?? []), Json::encode($level['hp_calculation'] ?? []), Json::encode($level['elemental_resistances'] ?? []), Json::encode($level['phase_values'] ?? []), Json::encode($level)]);
                foreach ($level['actions']['actions'] ?? [] as $action) {
                    $rule = $shared[mb_strtolower($action['name'])] ?? null;
                    $actionStmt->execute([$action['id'], $d['id'], $level['level'], $action['number'] ?? 0, $action['name'], $action['attack_or_dc'] ?? null, $action['damage_effect'] ?? null, $action['notes'] ?? null, $rule ? Json::encode($rule) : null, Json::encode($action)]);
                    $this->counts['Bossaktionen']++;
                }
                $this->counts['Bossstufen']++;
            }
            $this->counts['Bosse']++;
        }
    }

}

