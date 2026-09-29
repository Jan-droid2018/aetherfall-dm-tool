<?php
declare(strict_types=1);

namespace Aetherfall\Services;

final class CombatValueParser
{
    public function criticalDamagePercent(array|string|null $combatValues): ?float
    {
        if (is_string($combatValues)) {
            $combatValues = json_decode($combatValues, true);
        }
        if (!is_array($combatValues)) {
            return null;
        }

        $raw = $combatValues['values']['kritischer_schaden']
            ?? $combatValues['kritischer_schaden']
            ?? null;

        if (is_int($raw) || is_float($raw)) {
            return max(0.0, (float)$raw);
        }
        if (!is_string($raw) || !preg_match('/[-+]?\d+(?:[.,]\d+)?/', $raw, $match)) {
            return null;
        }

        return max(0.0, (float)str_replace(',', '.', $match[0]));
    }
}
