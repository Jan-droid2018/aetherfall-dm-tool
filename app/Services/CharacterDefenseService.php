<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use PDO;

/** Resolves a character's current defense directly from equipped armor rows. */
final class CharacterDefenseService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getPhysicalDefense(int $characterId): float
    {
        return (float)$this->getDefenseBreakdown($characterId)['physical'];
    }

    public function getMagicalDefense(int $characterId): float
    {
        return (float)$this->getDefenseBreakdown($characterId)['magical'];
    }

    public function getDefenseBreakdown(int $characterId): array
    {
        $slots = array_fill_keys(CharacterEquipmentService::ARMOR_SLOTS, null);
        $stmt = $this->pdo->prepare('SELECT s.slot,a.id,a.display_name,a.name,a.physical_defense,a.magical_defense FROM character_armor_slots s JOIN armors a ON a.id=s.armor_id WHERE s.character_id=? AND a.item_kind<>?');
        $stmt->execute([$characterId, 'shield']);
        foreach ($stmt->fetchAll() as $row) {
            $slot = (string)$row['slot'];
            if (!array_key_exists($slot, $slots)) continue;
            $slots[$slot] = [
                'id' => (string)$row['id'],
                'name' => (string)($row['display_name'] ?: $row['name']),
                'physical' => $this->numberOrZero($row['physical_defense']),
                'magical' => $this->numberOrZero($row['magical_defense']),
            ];
        }

        $physical = 0.0;
        $magical = 0.0;
        $physicalRows = [];
        $magicalRows = [];
        foreach ($slots as $slot => $armor) {
            $physicalValue = (float)($armor['physical'] ?? 0);
            $magicalValue = (float)($armor['magical'] ?? 0);
            $physical += $physicalValue;
            $magical += $magicalValue;
            $physicalRows[$slot] = ['value' => $physicalValue, 'name' => $armor['name'] ?? null];
            $magicalRows[$slot] = ['value' => $magicalValue, 'name' => $armor['name'] ?? null];
        }
        return [
            'physical' => $physical,
            'magical' => $magical,
            'physical_defense' => $physical,
            'magical_defense' => $magical,
            'breakdown' => ['physical' => $physicalRows, 'magical' => $magicalRows],
        ];
    }

    private function numberOrZero(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}
