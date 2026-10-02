<?php
declare(strict_types=1);

namespace Aetherfall\Services;

final class CombatCalculator
{
    private CriticalDamageCalculator $criticalDamageCalculator;

    public function __construct(?CriticalDamageCalculator $criticalDamageCalculator = null)
    {
        $this->criticalDamageCalculator = $criticalDamageCalculator ?? new CriticalDamageCalculator();
    }

    public function damage(float $raw, bool $critical, float $criticalPercent, float $resistancePercent = 0, float $defense = 0, float $bonus = 0, float $penalty = 0, bool $isDamage = true): array
    {
        $steps = [['label'=>'Rohwirkung','value'=>$raw]];
        $value = $raw;
        if ($bonus != 0) { $value += $bonus; $steps[]=['label'=>'Zusätzlicher Bonus','value'=>$bonus]; }
        if ($penalty != 0) { $value -= $penalty; $steps[]=['label'=>'Zusätzlicher Malus','value'=>-$penalty]; }
        $criticalResult = $this->criticalDamageCalculator->calculate($value, $criticalPercent, $critical, $isDamage);
        $value = $criticalResult['damage_after_critical'];
        if ($isDamage && $resistancePercent != 0) {
            $adjustment = -$value * ($resistancePercent / 100);
            $value += $adjustment; $steps[]=['label'=>"Resistenz ({$resistancePercent} %)",'value'=>$adjustment];
        }
        $damageBeforeDefense = $value;
        $applicableDefense = $isDamage ? max(0, $defense) : 0;
        if ($isDamage && $applicableDefense != 0) { $value -= $applicableDefense; $steps[]=['label'=>'Verteidigung','value'=>-$applicableDefense]; }
        $value = max(0, round($value, 2));
        $steps[] = ['label'=>$isDamage?'Finaler Schaden':'Finale Wirkung','value'=>$value];
        return array_merge($criticalResult, ['is_damage'=>$isDamage, 'value'=>$value, 'steps'=>$steps, 'damage_before_defense'=>$damageBeforeDefense, 'applicable_defense'=>$applicableDefense, 'damage_after_defense'=>$value]);
    }

    public function applyDamage(float $currentHp, float $damage): float { return max(0, $currentHp - max(0, $damage)); }
    public function applyHealing(float $currentHp, float $maxHp, float $healing): float { return min($maxHp, $currentHp + max(0, $healing)); }
}
