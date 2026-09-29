<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use InvalidArgumentException;

final class CriticalDamageCalculator
{
    public function calculate(
        float $normalDamage,
        float $criticalDamagePercent,
        bool $critical,
        bool $isDamage = true
    ): array {
        if ($criticalDamagePercent < 0) {
            throw new InvalidArgumentException('Kritischer Schaden muss mindestens 0 Prozent betragen.');
        }

        $criticalApplied = $critical && $isDamage;
        $criticalBonus = $criticalApplied
            ? $normalDamage * ($criticalDamagePercent / 100)
            : 0.0;

        return [
            'normal_damage' => $normalDamage,
            'critical' => $critical,
            'critical_applied' => $criticalApplied,
            'critical_damage_percent' => $criticalDamagePercent,
            'critical_bonus' => $criticalBonus,
            'damage_after_critical' => $normalDamage + $criticalBonus,
        ];
    }
}
