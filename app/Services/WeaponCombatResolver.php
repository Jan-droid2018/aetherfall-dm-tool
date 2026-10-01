<?php
declare(strict_types=1);

namespace Aetherfall\Services;

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
        $s = $this->pdo->prepare('SELECT cws.slot,w.id,w.name,w.display_name,w.subtitle,w.base_weapon_type,w.category,w.quality,w.item_level,w.is_magical,w.is_elemental,w.elements_json,w.core_die,w.handling,w.range_text,w.damage_type,w.weight,w.attack_attribute,w.attack_formula,w.damage_formula,w.critical_modification,w.special_properties,w.active_ability,w.class_restriction,w.appearance,w.raw_json FROM character_weapon_slots cws JOIN weapons w ON w.id=cws.weapon_id WHERE cws.character_id=? AND cws.slot=?');
        $s->execute([$characterId, $slot]);
        $row = $s->fetch();
        if (!$row) return null;
        $row['is_magical'] = (bool)$row['is_magical'];
        $row['is_elemental'] = (bool)$row['is_elemental'];
        $row['elements'] = json_decode((string)($row['elements_json'] ?? '[]'), true) ?: [];
        unset($row['elements_json']);
        return $row;
    }

    public function equippedOptions(int $characterId): array
    {
        $options = [];
        foreach (['hand_1', 'hand_2'] as $slot) {
            $weapon = $this->equipped($characterId, $slot);
            if ($weapon) $options[] = ['slot' => $slot, 'weapon_id' => (string)$weapon['id'], 'name' => (string)($weapon['display_name'] ?: $weapon['name']), 'weapon' => $this->context($weapon)];
        }
        return $options;
    }

    public function context(array $weapon, ?string $attributeOverride = null): array
    {
        $attribute = $attributeOverride ?: (string)($weapon['attack_attribute'] ?? '');
        $code = self::attributeCode($attribute);
        $attack = trim((string)($weapon['attack_formula'] ?? ''));
        $damage = self::cleanDamageFormula((string)($weapon['damage_formula'] ?? ''));
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
            'damage_type' => $weapon['damage_type'] ?? null,
            'critical_modification' => $weapon['critical_modification'] ?? null,
            'handling' => $weapon['handling'] ?? null,
        ];
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
        $formula = preg_replace('/\s+(?:Schlag|Hieb|Stich|Wucht|Feuer|Wasser|Erde|Luft|Licht|Dunkelheit)schaden\.?\s*$/iu', '', $formula) ?? $formula;
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
