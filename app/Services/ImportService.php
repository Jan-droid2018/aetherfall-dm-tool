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
    ];
    private array $warnings = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function importAll(): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->importClasses();
            $this->seedClassAdjustments();
            $this->importSpells();
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

    private function importClasses(): void
    {
        $classSql = 'INSERT INTO classes (id,name,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $resourceSql = 'INSERT INTO class_resources (class_id,resource_id,name,raw_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE resource_id=VALUES(resource_id),name=VALUES(name),raw_json=VALUES(raw_json)';
        $abilitySql = 'INSERT INTO abilities (id,class_id,unlock_level,number,name,type,focus,action_cost,trigger_requirement,costs,class_resource,usage_limit,range_text,target_text,area_text,duration_text,attack_roll,saving_throw,dc,attribute_reference,damage_type,calculation,effect,target_effect,status_effect,stacking,end_condition,scaling,source_file,source_hash,raw_json) VALUES (' . implode(',', array_fill(0, 31, '?')) . ') ON DUPLICATE KEY UPDATE class_id=VALUES(class_id),unlock_level=VALUES(unlock_level),number=VALUES(number),name=VALUES(name),type=VALUES(type),focus=VALUES(focus),action_cost=VALUES(action_cost),trigger_requirement=VALUES(trigger_requirement),costs=VALUES(costs),class_resource=VALUES(class_resource),usage_limit=VALUES(usage_limit),range_text=VALUES(range_text),target_text=VALUES(target_text),area_text=VALUES(area_text),duration_text=VALUES(duration_text),attack_roll=VALUES(attack_roll),saving_throw=VALUES(saving_throw),dc=VALUES(dc),attribute_reference=VALUES(attribute_reference),damage_type=VALUES(damage_type),calculation=VALUES(calculation),effect=VALUES(effect),target_effect=VALUES(target_effect),status_effect=VALUES(status_effect),stacking=VALUES(stacking),end_condition=VALUES(end_condition),scaling=VALUES(scaling),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)';
        $classStmt = $this->pdo->prepare($classSql);
        $resourceStmt = $this->pdo->prepare($resourceSql);
        $abilityStmt = $this->pdo->prepare($abilitySql);
        foreach ($this->files('classes') as $file) {
            $data = Json::decodeFile($file);
            $raw = (string) file_get_contents($file);
            $hash = hash('sha256', $raw);
            $classStmt->execute([$data['id'], $data['name'], $data['schema_version'], $data['source_file'] ?? basename($file), $hash, $raw]);
            $resource = $data['resource'] ?? null;
            if (is_array($resource)) {
                $resourceStmt->execute([$data['id'], $resource['id'] ?? $data['id'] . '-resource', $resource['name'] ?? 'Klassenressource', Json::encode($resource)]);
            }
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
        $entityStmt = $this->pdo->prepare('INSERT INTO creatures (id,name,challenge_rating,encounter_rank,archetype,elemental_affinity,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),challenge_rating=VALUES(challenge_rating),encounter_rank=VALUES(encounter_rank),archetype=VALUES(archetype),elemental_affinity=VALUES(elemental_affinity),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)');
        $levelStmt = $this->pdo->prepare('INSERT INTO creature_levels (creature_id,level,max_hp,physical_defense,magic_defense,attributes_json,combat_values_json,hp_calculation_json,elemental_resistances_json,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE max_hp=VALUES(max_hp),physical_defense=VALUES(physical_defense),magic_defense=VALUES(magic_defense),attributes_json=VALUES(attributes_json),combat_values_json=VALUES(combat_values_json),hp_calculation_json=VALUES(hp_calculation_json),elemental_resistances_json=VALUES(elemental_resistances_json),raw_json=VALUES(raw_json)');
        $actionStmt = $this->pdo->prepare('INSERT INTO creature_actions (id,creature_id,level,number,name,type,attack_data,damage_data,damage_type,defense_save,range_text,notes,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE creature_id=VALUES(creature_id),level=VALUES(level),number=VALUES(number),name=VALUES(name),type=VALUES(type),attack_data=VALUES(attack_data),damage_data=VALUES(damage_data),damage_type=VALUES(damage_type),defense_save=VALUES(defense_save),range_text=VALUES(range_text),notes=VALUES(notes),raw_json=VALUES(raw_json)');
        foreach ($this->files('creatures') as $file) {
            $d = Json::decodeFile($file); $raw = (string) file_get_contents($file); $hash = hash('sha256', $raw); $core = $d['core'] ?? [];
            $entityStmt->execute([$d['id'], $d['name'], $core['challenge_rating']['value'] ?? null, $core['encounter_rank'] ?? null, $core['archetype'] ?? null, $core['elemental_affinity'] ?? null, $d['schema_version'], $d['source_file'] ?? basename($file), $hash, $raw]);
            foreach ($d['levels'] ?? [] as $level) {
                $values = $level['combat_values']['values'] ?? [];
                $levelStmt->execute([$d['id'], $level['level'], $this->number($values['maximale_lp'] ?? null), $this->number($values['physische_verteidigung'] ?? null), $this->number($values['magieverteidigung'] ?? null), Json::encode($level['attributes'] ?? []), Json::encode($level['combat_values'] ?? []), Json::encode($level['hp_calculation'] ?? []), Json::encode($level['elemental_resistances'] ?? []), Json::encode($level)]);
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
        $entityStmt = $this->pdo->prepare('INSERT INTO bosses (id,name,challenge_rating,encounter_rank,archetype,elemental_affinity,phase_count,schema_version,source_file,source_hash,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),challenge_rating=VALUES(challenge_rating),encounter_rank=VALUES(encounter_rank),archetype=VALUES(archetype),elemental_affinity=VALUES(elemental_affinity),phase_count=VALUES(phase_count),schema_version=VALUES(schema_version),source_file=VALUES(source_file),source_hash=VALUES(source_hash),raw_json=VALUES(raw_json)');
        $levelStmt = $this->pdo->prepare('INSERT INTO boss_levels (boss_id,level,max_hp,physical_defense,magic_defense,attribute_profiles_json,combat_values_json,hp_calculation_json,elemental_resistances_json,phase_values_json,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE max_hp=VALUES(max_hp),physical_defense=VALUES(physical_defense),magic_defense=VALUES(magic_defense),attribute_profiles_json=VALUES(attribute_profiles_json),combat_values_json=VALUES(combat_values_json),hp_calculation_json=VALUES(hp_calculation_json),elemental_resistances_json=VALUES(elemental_resistances_json),phase_values_json=VALUES(phase_values_json),raw_json=VALUES(raw_json)');
        $actionStmt = $this->pdo->prepare('INSERT INTO boss_actions (id,boss_id,level,number,name,attack_data,damage_data,notes,shared_rule_json,raw_json) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE boss_id=VALUES(boss_id),level=VALUES(level),number=VALUES(number),name=VALUES(name),attack_data=VALUES(attack_data),damage_data=VALUES(damage_data),notes=VALUES(notes),shared_rule_json=VALUES(shared_rule_json),raw_json=VALUES(raw_json)');
        foreach ($this->files('bosses') as $file) {
            $d = Json::decodeFile($file); $raw = (string) file_get_contents($file); $hash = hash('sha256', $raw); $core = $d['core'] ?? [];
            $entityStmt->execute([$d['id'], $d['name'], $core['challenge_rating']['value'] ?? null, $core['encounter_rank'] ?? null, $core['archetype'] ?? null, $core['elemental_affinity'] ?? null, $core['phase_count']['value'] ?? null, $d['schema_version'], $d['source_file'] ?? basename($file), $hash, $raw]);
            $shared = [];
            foreach ($d['shared_action_details'] ?? [] as $detail) { $shared[mb_strtolower($detail['name'])] = $detail; }
            foreach ($d['levels'] ?? [] as $level) {
                $values = $level['combat_values']['values'] ?? [];
                $levelStmt->execute([$d['id'], $level['level'], $this->number($values['maximale_lp'] ?? null), $this->number($values['physische_verteidigung'] ?? null), $this->number($values['magieverteidigung'] ?? null), Json::encode($level['attribute_profiles'] ?? []), Json::encode($level['combat_values'] ?? []), Json::encode($level['hp_calculation'] ?? []), Json::encode($level['elemental_resistances'] ?? []), Json::encode($level['phase_values'] ?? []), Json::encode($level)]);
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

    private function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) { return $value; }
        if (is_string($value) && preg_match('/-?\d+(?:[.,]\d+)?/', str_replace('`', '', $value), $m)) {
            return (float) str_replace(',', '.', $m[0]);
        }
        return null;
    }
}

