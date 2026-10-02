<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use Aetherfall\Formula\FormulaEngine;
use PDO;
use RuntimeException;

/** Resolves the currently equipped weapon without trusting client supplied definitions. */
final class WeaponCombatResolver
{
    private const ATTRIBUTES = [
        'stärke' => 'ST', 'staerke' => 'ST', 'st' => 'ST',
        'geschicklichkeit' => 'GE', 'ge' => 'GE',
        'beweglichkeit' => 'BW', 'bw' => 'BW',
        'intelligenz' => 'IN', 'in' => 'IN',
        'wahrnehmung' => 'WA', 'wa' => 'WA',
        'kreativität' => 'KR', 'kreativitaet' => 'KR', 'kr' => 'KR',
        'charisma' => 'CH', 'ch' => 'CH',
        'empathie' => 'EM', 'em' => 'EM',
        'willenskraft' => 'WI', 'wi' => 'WI',
        'intuition' => 'IT', 'it' => 'IT',
        'ausweichen' => 'AU', 'au' => 'AU',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function equipped(int $characterId, string $slot): ?array
    {
        if (!in_array($slot, ['hand_1', 'hand_2'], true)) {
            throw new RuntimeException('Ungültiger Waffenslot.');
        }
        $s = $this->pdo->prepare('SELECT cws.slot,w.id,w.name,w.display_name,w.subtitle,w.base_weapon_type,w.category,w.quality,w.item_level,w.is_magical,w.is_elemental,w.elements_json,w.core_die,w.handling,w.range_text,w.damage_type,w.weight,w.attack_attribute,w.attack_formula,w.damage_formula,w.formula_variables_json,w.critical_modification,w.special_properties,w.active_ability,w.class_restriction,w.appearance,w.raw_json FROM character_weapon_slots cws JOIN weapons w ON w.id=cws.weapon_id WHERE cws.character_id=? AND cws.slot=?');
        $s->execute([$characterId, $slot]);
        $row = $s->fetch();
        if (!$row) return null;
        $row['is_magical'] = (bool)$row['is_magical'];
        $row['is_elemental'] = (bool)$row['is_elemental'];
        $row['elements'] = json_decode((string)($row['elements_json'] ?? '[]'), true) ?: [];
        $row['formula_variables'] = json_decode((string)($row['formula_variables_json'] ?? '{}'), true) ?: [];
        $row['combat_profiles'] = $this->profiles((string)$row['id']);
        unset($row['elements_json'], $row['formula_variables_json']);
        return $row;
    }

    public function equippedOptions(int $characterId): array
    {
        $options = [];
        foreach (['hand_1', 'hand_2'] as $slot) {
            $weapon = $this->equipped($characterId, $slot);
            if ($weapon) {
                $profiles = $weapon['combat_profiles'] ?? [];
                $selected = $this->suggestedProfile($profiles);
                $available = array_map(static fn(array $profile): array => ['key' => (string)$profile['profile_key'], 'label' => (string)($profile['label'] ?? $profile['profile_key'])], $profiles);
                $options[] = ['slot' => $slot, 'weapon_id' => (string)$weapon['id'], 'name' => (string)($weapon['display_name'] ?: $weapon['name']), 'profiles' => $profiles, 'available_profiles' => $available, 'suggested_profile' => $selected, 'weapon' => $this->context($weapon, null, $selected)];
            }
        }
        return $options;
    }

    /** Pick a useful action default without persisting it to character equipment. */
    public function suggestedProfile(array $profiles): ?string
    {
        if (!$profiles) return null;
        foreach ($profiles as $profile) if ((string)($profile['profile_key'] ?? '') === 'one_handed') return 'one_handed';
        return (string)($profiles[0]['profile_key'] ?? '') ?: null;
    }

    public function context(array $weapon, ?string $attributeOverride = null, ?string $profileKey = null): array
    {
        $attribute = $attributeOverride ?: (string)($weapon['attack_attribute'] ?? '');
        $code = self::attributeCode($attribute);
        $profiles = $weapon['combat_profiles'] ?? $this->profiles((string)($weapon['id'] ?? ''));
        if (!$profiles && trim((string)($weapon['damage_formula'] ?? '')) !== '') {
            $profiles = [['profile_key' => 'default', 'label' => 'Standard', 'attack_formula' => null, 'attack_attribute' => null, 'damage_formula' => (string)$weapon['damage_formula'], 'damage_type' => null, 'handling' => $weapon['handling'] ?? null, 'sort_order' => 0]];
        }
        if (count($profiles) > 1 && ($profileKey === null || $profileKey === '')) throw new RuntimeException('Für diese Waffe muss ein Waffenprofil ausgewählt werden.');
        $profile = null;
        if ($profiles) {
            $key = $profileKey ?: (string)$profiles[0]['profile_key'];
            foreach ($profiles as $candidate) if ((string)$candidate['profile_key'] === $key) { $profile = $candidate; break; }
            if (!$profile) throw new RuntimeException('Das gewählte Waffenprofil ist für diese Waffe nicht verfügbar.');
        }
        $attack = trim((string)($profile['attack_formula'] ?? $weapon['attack_formula'] ?? ''));
        $damage = self::cleanDamageFormula((string)($profile['damage_formula'] ?? $weapon['damage_formula'] ?? ''));
        if ($damage === '') throw new RuntimeException('Keine Schadensformel für das gewählte Waffenprofil vorhanden.');
        $attribute = $attributeOverride ?: (string)($profile['attack_attribute'] ?? $attribute);
        $code = self::attributeCode($attribute);
        if ($attributeOverride && $code) {
            $attack = self::replaceAttribute($attack, $code);
            $damage = self::replaceAttribute($damage, $code);
        }
        return [
            'weapon_id' => (string)$weapon['id'],
            'weapon_name' => (string)($weapon['display_name'] ?: $weapon['name']),
            'attack_attribute' => $attribute,
            'attack_attribute_code' => $code,
            'attack_formula' => $attack,
            'damage_formula' => $damage,
            'damage_type' => $profile['damage_type'] ?? ($weapon['damage_type'] ?? null),
            'critical_modification' => $weapon['critical_modification'] ?? null,
            'handling' => $weapon['handling'] ?? null,
            'profile_key' => $profile['profile_key'] ?? null,
            'profile_label' => $profile['label'] ?? null,
            'profiles' => $profiles,
            'formula_variables' => $this->formulaVariables($weapon),
        ];
    }

    private function profiles(string $weaponId): array
    {
        if ($weaponId === '') return [];
        $stmt = $this->pdo->prepare('SELECT profile_key,label,handling,attack_formula,attack_attribute,damage_formula,damage_type,sort_order,raw_json FROM weapon_combat_profiles WHERE weapon_id=? ORDER BY sort_order,profile_key');
        $stmt->execute([$weaponId]);
        return array_map(static function (array $row): array { $row['sort_order'] = (int)$row['sort_order']; unset($row['raw_json']); return $row; }, $stmt->fetchAll());
    }

    private function formulaVariables(array $weapon): array
    {
        $variables = $weapon['formula_variables'] ?? [];
        if (is_string($variables)) $variables = json_decode($variables, true) ?: [];
        return is_array($variables) ? $variables : [];
    }

    /** Resolve weapon-local formulas through FormulaEngine, with cycle detection. */
    public function localVariables(array $weapon, array $variables): array
    {
        $definitions = $this->formulaVariables($weapon);
        $resolved = [];
        $engine = new FormulaEngine();
        $resolve = function (string $name, array $stack = []) use (&$resolve, &$resolved, $definitions, $variables, $engine): float {
            if (array_key_exists($name, $resolved)) return $resolved[$name];
            if (in_array($name, $stack, true)) throw new RuntimeException('Zyklus in Waffenformelvariablen: ' . implode(' → ', [...$stack, $name]));
            if (!array_key_exists($name, $definitions)) throw new RuntimeException('Unbekannte Waffenformelvariable: ' . $name);
            $context = $variables;
            foreach ($definitions as $other => $_) if ($other !== $name) {
                try { $context[$other] = $resolve((string)$other, [...$stack, $name]); } catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'Zyklus')) throw $e; }
            }
            $result = $engine->evaluate((string)$definitions[$name], ['variables' => $context]);
            if (!$result['supported'] || $result['value'] === null) throw new RuntimeException('Waffenformelvariable ' . $name . ' konnte nicht aufgelöst werden.');
            return $resolved[$name] = (float)$result['value'];
        };
        foreach (array_keys($definitions) as $name) $resolve((string)$name);
        return $resolved;
    }

    public static function attributeCode(string $attribute): ?string
    {
        $value = mb_strtolower(trim($attribute));
        foreach (self::ATTRIBUTES as $name => $code) {
            if ($value === $name || str_contains($value, $name)) return $code;
        }
        return null;
    }

    public static function cleanDamageFormula(string $formula): string
    {
        $formula = trim($formula);
        if (preg_match('/^@\{(?:default\s*=)?(.+?)\}$/isu', $formula, $m)) $formula = trim($m[1]);
        if (str_contains($formula, '@{')) {
            $parts = [];
            if (preg_match_all('/(?:default|one_handed|two_handed)\s*=\s*([^;}]*)/isu', $formula, $m)) {
                foreach ($m[1] as $part) if (trim($part) !== '') $parts[] = trim($part);
                if ($parts) $formula = $parts[0];
            }
        }
        // Imported formulas append the damage type as prose (e.g. "Schlagschaden.").
        $formula = preg_replace('/\s*;\s*(?:verursacht|zusätzlich|entsprechend|der\s+beim\s+Angriff).*$/iu', '', $formula) ?? $formula;
        $formula = preg_replace('/\s+(?:Schlag|Hieb|Stich|Wucht|Feuer|Wasser|Erde|Luft|Licht|Dunkelheit)schaden\.?\s*$/iu', '', $formula) ?? $formula;
        $formula = preg_replace('/\s+(?:Hieb|Stich|Schlag)(?:-\s*oder\s+(?:Hieb|Stich|Schlag))schaden\.?\s*$/iu', '', $formula) ?? $formula;
        return trim($formula, " \t\r\n.;:");
    }

    public static function replaceAttribute(string $formula, string $code): string
    {
        $map = ['ST' => 'Stärke', 'GE' => 'Geschicklichkeit', 'BW' => 'Beweglichkeit', 'IN' => 'Intelligenz', 'WA' => 'Wahrnehmung', 'KR' => 'Kreativität', 'CH' => 'Charisma', 'EM' => 'Empathie', 'WI' => 'Willenskraft', 'IT' => 'Intuition', 'AU' => 'Ausweichen'];
        $name = $map[$code] ?? $code;
        $formula = preg_replace('/(?:Stärke|Geschicklichkeit|Beweglichkeit|Intelligenz|Wahrnehmung|Kreativität|Charisma|Empathie|Willenskraft|Intuition|Ausweichen|ST|GE|BW|IN|WA|KR|CH|EM|WI|IT|AU)(?=-(?:Modifikator|Mod\.?|Bonus))/iu', $name, $formula) ?? $formula;
        return $formula;
    }

    /** Classifies only explicit weapon attack/damage references, not incidental weapon flavour text. */
    public static function classify(array $source): array
    {
        $raw = [];
        if (!empty($source['raw_json'])) $raw = json_decode((string)$source['raw_json'], true) ?: [];
        $structured = '';
        foreach (['weapon_reference', 'Waffenbezug', 'equipment_reference', 'Ausrüstungsbezug'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) $structured .= ' ' . (string)$source[$key];
            if (isset($raw[$key]) && is_scalar($raw[$key])) $structured .= ' ' . (string)$raw[$key];
        }
        $attack = trim((string)($source['attack_roll'] ?? ''));
        $calculation = trim((string)($source['calculation'] ?? ''));
        $text = mb_strtolower($structured . ' ' . $attack . ' ' . $calculation);
        $normalAttack = (bool)preg_match('/normal(?:er|en|e|em)?\s+(?:einen\s+)?(?:waffen[- ]?)?angriff|normal(?:er|en)?\s+angriffswurf\s+(?:der|mit|einer|der verwendeten|der gekoppelten)\s+(?:verwendeten\s+|gekoppelten\s+)?(?:waffe|waffen)/iu', $text);
        $normalDamage = (bool)preg_match('/normal(?:er|en)?\s+waffenschaden|normal(?:en)?\s+schaden\s+der\s+waffe/iu', $text);
        $weaponAttack = $normalAttack || $normalDamage;
        return [
            'requires_weapon' => $weaponAttack,
            'weapon_usage_type' => $normalAttack ? 'normal_weapon_attack' : ($normalDamage ? 'normal_weapon_damage' : null),
            'uses_normal_weapon_attack' => $normalAttack,
            'uses_normal_weapon_damage' => $normalDamage,
            'weapon_reference' => trim($structured),
        ];
    }
}
