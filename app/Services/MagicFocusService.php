<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use PDO;

/** Resolves the magic attribute supplied by the character's equipped focus. */
final class MagicFocusService
{
    /** @var array<string,string> */
    private const ATTRIBUTES = [
        'ST' => 'Stärke', 'GE' => 'Geschicklichkeit', 'BW' => 'Beweglichkeit',
        'IN' => 'Intelligenz', 'WA' => 'Wahrnehmung', 'KR' => 'Kreativität',
        'CH' => 'Charisma', 'EM' => 'Empathie', 'WI' => 'Willenskraft',
        'IT' => 'Intuition', 'AU' => 'Ausweichen',
    ];

    /** @var array<string,string> */
    private const ALIASES = [
        'st' => 'ST', 'stärke' => 'ST', 'staerke' => 'ST',
        'ge' => 'GE', 'geschicklichkeit' => 'GE',
        'bw' => 'BW', 'beweglichkeit' => 'BW',
        'in' => 'IN', 'intelligenz' => 'IN',
        'wa' => 'WA', 'wahrnehmung' => 'WA',
        'kr' => 'KR', 'kreativität' => 'KR', 'kreativitaet' => 'KR',
        'ch' => 'CH', 'charisma' => 'CH',
        'em' => 'EM', 'empathie' => 'EM',
        'wi' => 'WI', 'willenskraft' => 'WI',
        'it' => 'IT', 'intuition' => 'IT',
        'au' => 'AU', 'ausweichen' => 'AU',
    ];

    public function __construct(private PDO $pdo) {}

    /** @return array<string,string> */
    public static function attributeNames(): array { return self::ATTRIBUTES; }

    public static function attributeCode(?string $value): ?string
    {
        $key = mb_strtolower(trim((string)$value), 'UTF-8');
        return $key === '' ? null : (self::ALIASES[$key] ?? null);
    }

    public static function attributeName(?string $value): ?string
    {
        $code = self::attributeCode($value);
        return $code === null ? null : self::ATTRIBUTES[$code];
    }

    /** Return the currently equipped focus, including its normalized attribute code. */
    public function getEquippedMagicFocus(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT f.* FROM character_magic_focus s JOIN magic_foci f ON f.id=s.magic_focus_id WHERE s.character_id=?');
        $stmt->execute([$characterId]);
        $focus = $stmt->fetch();
        if (!$focus) return null;
        foreach (['is_magical', 'is_elemental'] as $key) if (array_key_exists($key, $focus)) $focus[$key] = (bool)$focus[$key];
        foreach (['element_binding', 'class_binding', 'magic_attack'] as $key) {
            if (isset($focus[$key]) && is_string($focus[$key])) $focus[$key] = json_decode($focus[$key], true) ?: $focus[$key];
        }
        $focus['magic_attribute_code'] = self::attributeCode((string)($focus['magic_attribute'] ?? ''));
        $focus['magic_attribute_name'] = self::attributeName((string)($focus['magic_attribute'] ?? ''));
        return $focus;
    }

    /** Resolve the equipped focus and the live character attribute values. */
    public function getMagicAttribute(int $characterId): ?array
    {
        $focus = $this->getEquippedMagicFocus($characterId);
        if (!$focus || !$focus['magic_attribute_code']) return null;
        $code = (string)$focus['magic_attribute_code'];
        $stmt = $this->pdo->prepare('SELECT value,modifier,bonus FROM character_attributes WHERE character_id=? AND attribute_code=?');
        $stmt->execute([$characterId, $code]);
        $attribute = $stmt->fetch() ?: [];
        return [
            'code' => $code,
            'name' => $focus['magic_attribute_name'] ?: self::ATTRIBUTES[$code],
            'value' => array_key_exists('value', $attribute) ? (float)$attribute['value'] : null,
            'modifier' => array_key_exists('modifier', $attribute) ? (float)$attribute['modifier'] : null,
            'bonus' => array_key_exists('bonus', $attribute) ? (float)$attribute['bonus'] : null,
            'focus_id' => $focus['id'] ?? null,
            'focus_name' => ($focus['display_name'] ?? '') ?: ($focus['name'] ?? null),
        ];
    }

    public function getMagicAttributeModifier(int $characterId): ?float
    {
        $attribute = $this->getMagicAttribute($characterId);
        return $attribute && $attribute['modifier'] !== null ? (float)$attribute['modifier'] : null;
    }

    public function getMagicAttributeBonus(int $characterId): ?float
    {
        $attribute = $this->getMagicAttribute($characterId);
        return $attribute && $attribute['bonus'] !== null ? (float)$attribute['bonus'] : null;
    }

    /** Variables consumed by FormulaEngine. */
    public function formulaVariables(int $characterId): ?array
    {
        $attribute = $this->getMagicAttribute($characterId);
        if (!$attribute || $attribute['modifier'] === null || $attribute['bonus'] === null) return null;
        $variables = [
            'Magieattribut-Modifikator' => $attribute['modifier'],
            'Magieattribut-Bonus' => $attribute['bonus'],
        ];
        $code = $attribute['code'];
        $variables[$code.'-Magieattribut-Modifikator'] = $attribute['modifier'];
        $variables[$code.'-Magieattribut-Bonus'] = $attribute['bonus'];
        return $variables;
    }

    public static function formulaNeedsMagicAttribute(string $formula): bool
    {
        return (bool)preg_match('/Magieattribut(?:\s*[-–—]\s*|\s+)(?:Modifikator|Bonus)|Magieattribut\s*[-–—]\s*(?:Mod\.?|Bon\.?)/iu', $formula);
    }
}
