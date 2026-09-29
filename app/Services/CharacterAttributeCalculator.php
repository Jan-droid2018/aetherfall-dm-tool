<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use InvalidArgumentException;

final class CharacterAttributeCalculator
{
    public const MIN_VALUE = 1;
    public const MAX_VALUE = 100;

    public function calculateModifier(int $attributeValue): int
    {
        if ($attributeValue < self::MIN_VALUE || $attributeValue > self::MAX_VALUE) {
            throw new InvalidArgumentException(sprintf(
                'Charakterattribute müssen zwischen %d und %d liegen.',
                self::MIN_VALUE,
                self::MAX_VALUE
            ));
        }

        return (int) floor(($attributeValue - 10) / 2);
    }
}

