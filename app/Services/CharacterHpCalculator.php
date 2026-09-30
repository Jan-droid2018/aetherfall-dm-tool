<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use InvalidArgumentException;

final class CharacterHpCalculator
{
    public const BASE_HP = 100;
    public const PRIMARY_ATTRIBUTE_MULTIPLIER = 10;
    public const SECONDARY_ATTRIBUTE_MULTIPLIER = 5;
    public const LEVEL_HP_MULTIPLIER = 10;

    public function effectiveLevel(array $classLevels): int
    {
        $levels=array_map('intval',$classLevels);return max(array_merge([1],$levels));
    }

    public function calculateMaxHp(
        int $level,
        int $primaryModifier,
        float $primaryBonus,
        int $secondaryModifier,
        float $secondaryBonus
    ): int {
        if ($level < 1) {
            throw new InvalidArgumentException('Die Charakterstufe muss mindestens 1 sein.');
        }

        $maxHp = self::BASE_HP
            + (($primaryModifier + $primaryBonus) * self::PRIMARY_ATTRIBUTE_MULTIPLIER)
            + (($secondaryModifier + $secondaryBonus) * self::SECONDARY_ATTRIBUTE_MULTIPLIER)
            + ($level * self::LEVEL_HP_MULTIPLIER);

        if ($maxHp < 1) {
            throw new InvalidArgumentException('Die berechneten maximalen LP müssen positiv sein.');
        }

        return (int) floor($maxHp);
    }

    public function resolveCurrentHp(?int $submittedCurrentHp, int $maxHp, bool $isNew, ?int $existingCurrentHp = null): int
    {
        if ($submittedCurrentHp === null) {
            $currentHp = $isNew ? $maxHp : $existingCurrentHp;
        } else {
            $currentHp = $submittedCurrentHp;
        }

        if ($currentHp === null || $currentHp < 0) {
            throw new InvalidArgumentException('Aktuelle LP müssen mindestens 0 sein.');
        }

        return min($currentHp, $maxHp);
    }
}
