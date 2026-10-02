<?php
declare(strict_types=1);

namespace Aetherfall\Services;

/**
 * Splits the descriptive calculation text of an action into isolated combat
 * effects.  The formula engine only ever receives the formula field returned
 * by this resolver, never the surrounding German rule text.
 */
final class CombatEffectResolver
{
    public static function describe(array $source, ?array $weaponContext = null): array
    {
        $calculation = trim((string)($source['calculation'] ?? ''));
        $effects = [];
        if ($calculation === '') return $effects;

        [$main, $secondary] = self::splitSecondary($calculation);
        $hasWeaponDamage = $weaponContext !== null && (bool)preg_match('/normal(?:er|en|e|em)?\s+waffenschaden/iu', $main);

        if ($hasWeaponDamage) {
            $weaponFormula = trim((string)($weaponContext['damage_formula'] ?? ''));
            $combined = self::weaponFormulaWithExtras($main, $weaponFormula);
            if ($combined !== '') {
                $effects[] = [
                    'key' => 'weapon_damage',
                    'type' => 'damage',
                    'source' => 'weapon',
                    'condition' => self::isOnHit($main) ? 'on_hit' : 'always',
                    'formula' => $combined,
                    'roll_key' => 'weapon_damage',
                    'label' => 'Waffenschadenswürfel',
                ];
            }
        } else {
            $formula = self::stripRulePrefix($main);
            if ($formula !== '' && self::looksMathematical($formula)) {
                $effects[] = [
                    'key' => self::effectKey($source),
                    'type' => self::effectType($source),
                    'source' => 'ability',
                    'condition' => self::isOnHit($main) ? 'on_hit' : 'always',
                    'formula' => $formula,
                    'roll_key' => self::effectKey($source),
                    'label' => self::effectLabel(self::effectType($source)),
                ];
            }
        }

        if ($secondary !== null) {
            $type = $secondary['type'];
            $effects[] = [
                'key' => $type === 'shield' ? 'shield' : 'healing',
                'type' => $type,
                'source' => 'ability',
                'condition' => $secondary['condition'],
                'formula' => $secondary['formula'],
                'roll_key' => $type === 'shield' ? 'shield' : 'healing',
                'label' => self::effectLabel($type),
            ];
        }

        if (!$effects && $weaponContext !== null && trim((string)($weaponContext['damage_formula'] ?? '')) !== '') {
            $effects[] = [
                'key' => 'weapon_damage',
                'type' => 'damage',
                'source' => 'weapon',
                'condition' => 'always',
                'formula' => trim((string)$weaponContext['damage_formula']),
                'roll_key' => 'weapon_damage',
                'label' => 'Waffenschadenswürfel',
            ];
        }

        return $effects;
    }

    /** @return array{0:string,1:?array{type:string,formula:string,condition:string}} */
    public static function splitSecondary(string $calculation): array
    {
        $pattern = '/\b(Schutzwert|Schildwert|Barrierewert|Heilung)\s+(?:danach\s*)?(?:=|:)\s*(.+)$/isu';
        if (!preg_match($pattern, $calculation, $match, PREG_OFFSET_CAPTURE)) return [trim($calculation), null];
        $offset = (int)$match[0][1];
        $main = rtrim(substr($calculation, 0, $offset), " .;\t\r\n:");
        $formula = trim((string)$match[2][0], " .;\t\r\n");
        $label = mb_strtolower((string)$match[1][0]);
        $type = $label === 'heilung' ? 'healing' : 'shield';
        return [$main, ['type' => $type, 'formula' => $formula, 'condition' => self::isOnHit($main) ? 'on_hit' : 'after_damage']];
    }

    private static function weaponFormulaWithExtras(string $main, string $weaponFormula): string
    {
        if ($weaponFormula === '') return '';
        // Keep the imported weapon formula unwrapped. FormulaEngine::extractMath
        // intentionally starts at the first die token; wrapping it would leave
        // a trailing ')' in the extracted expression.
        $combined = preg_replace('/normal(?:er|en|e|em)?\s+waffenschaden/iu', $weaponFormula, $main, 1) ?? $weaponFormula;
        $combined = self::stripRulePrefix($combined);
        $combined = preg_replace('/\bgenau(?:\s+einmal)?\b/iu', '', $combined) ?? $combined;
        $combined = trim($combined, " .;\t\r\n:");
        return $combined !== '' && self::looksMathematical($combined) ? $combined : $weaponFormula;
    }

    private static function stripRulePrefix(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^(?:bei\s+treffer|wenn\s+du\s+triffst|triffst\s+du)\s*:\s*/iu', '', $text) ?? $text;
        return trim($text, " .;\t\r\n:");
    }

    private static function looksMathematical(string $text): bool
    {
        return (bool)preg_match('/(?:\d*W\d+|[+\-*\/()]|⌊|⌈|\b(?:floor|ceil|abrunden|aufrunden)\b)/iu', $text);
    }

    private static function isOnHit(string $text): bool
    {
        return (bool)preg_match('/bei\s+treffer|wenn\s+du\s+triffst|triffst\s+du/iu', $text);
    }

    private static function effectType(array $source): string
    {
        $text = mb_strtolower(trim((string)($source['type'] ?? '') . ' ' . (string)($source['effect'] ?? '') . ' ' . (string)($source['rule_text'] ?? '')));
        if (preg_match('/heil|regeneration/u', $text)) return 'healing';
        if (preg_match('/schild|barriere|schutz/u', $text)) return 'shield';
        return 'damage';
    }

    private static function effectKey(array $source): string
    {
        return self::effectType($source) === 'shield' ? 'shield' : (self::effectType($source) === 'healing' ? 'healing' : 'damage');
    }

    private static function effectLabel(string $type): string
    {
        return match ($type) {
            'shield' => 'Schutzwürfel',
            'healing' => 'Heilungswürfel',
            default => 'Schadenswürfel',
        };
    }
}
