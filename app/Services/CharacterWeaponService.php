<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use Aetherfall\Repositories\WeaponRepository;
use PDO;
use RuntimeException;

final class CharacterWeaponService
{
    private const SLOTS = ['hand_1', 'hand_2'];

    public function __construct(private PDO $pdo, private ?WeaponRepository $weapons = null)
    {
        $this->weapons ??= new WeaponRepository($pdo);
        $this->ensureSchema();
    }

    public function slots(int $characterId): array
    {
        $result = ['hand_1' => null, 'hand_2' => null];
        $stmt = $this->pdo->prepare('SELECT cws.slot,w.id,w.name,w.display_name,w.subtitle,w.base_weapon_type,w.category,w.quality,w.item_level,w.is_magical,w.is_elemental,w.elements_json,w.core_die,w.handling,w.range_text,w.damage_type,w.weight,w.attack_attribute,w.attack_formula,w.damage_formula,w.critical_modification,w.special_properties,w.active_ability,w.class_restriction,w.appearance FROM character_weapon_slots cws LEFT JOIN weapons w ON w.id=cws.weapon_id WHERE cws.character_id=?');
        $stmt->execute([$characterId]);
        foreach ($stmt->fetchAll() as $row) {
            if (!in_array($row['slot'], self::SLOTS, true) || !$row['id']) continue;
            $slot = (string)$row['slot'];
            unset($row['slot']);
            $row['is_magical'] = (bool)$row['is_magical'];
            $row['is_elemental'] = (bool)$row['is_elemental'];
            $row['elements'] = json_decode((string)($row['elements_json'] ?? '[]'), true) ?: [];
            unset($row['elements_json']);
            $result[$slot] = $row;
        }
        return $result;
    }

    public function setSlot(int $characterId, string $slot, string $weaponId): array
    {
        $this->assertSlot($slot);
        $weapon = $this->weapons->find($weaponId);
        if (!$weapon) throw new RuntimeException('Die gewählte Waffe existiert nicht.');
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->pdo->prepare('INSERT INTO character_weapon_slots (character_id,slot,weapon_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE weapon_id=VALUES(weapon_id)');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO character_weapon_slots (character_id,slot,weapon_id) VALUES (?,?,?) ON CONFLICT(character_id,slot) DO UPDATE SET weapon_id=excluded.weapon_id');
        }
        $stmt->execute([$characterId, $slot, $weaponId]);
        return $weapon;
    }

    public function clearSlot(int $characterId, string $slot): void
    {
        $this->assertSlot($slot);
        $this->pdo->prepare('DELETE FROM character_weapon_slots WHERE character_id=? AND slot=?')->execute([$characterId, $slot]);
    }

    private function assertSlot(string $slot): void
    {
        if (!in_array($slot, self::SLOTS, true)) throw new RuntimeException('Ungültiger Waffenslot.');
    }

    private function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS character_weapon_slots (character_id {$id} NOT NULL,slot VARCHAR(20) NOT NULL,weapon_id VARCHAR(190) NULL,PRIMARY KEY(character_id,slot))");
    }
}
