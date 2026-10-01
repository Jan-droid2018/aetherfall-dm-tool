<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use PDO;
use RuntimeException;

final class CharacterEquipmentService
{
    public const ARMOR_SLOTS=['head','chest','hands','legs','feet'];
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    public function armorSlots(int $characterId): array
    {
        $result=array_fill_keys(self::ARMOR_SLOTS,null);$stmt=$this->pdo->prepare('SELECT s.slot,a.* FROM character_armor_slots s JOIN armors a ON a.id=s.armor_id WHERE s.character_id=?');$stmt->execute([$characterId]);
        foreach($stmt->fetchAll() as $row){$slot=(string)$row['slot'];if(in_array($slot,self::ARMOR_SLOTS,true)){$result[$slot]=$row;foreach(['is_magical'] as $k)$result[$slot][$k]=(bool)$result[$slot][$k];foreach(['resistances_json'=>'resistances','elements_json'=>'elements'] as $from=>$to){$result[$slot][$to]=json_decode((string)($result[$slot][$from]??'null'),true);unset($result[$slot][$from]);}}}
        return $result;
    }

    public function setArmorSlot(int $characterId,string $slot,string $armorId): array
    {
        $this->assertCharacter($characterId);if(!in_array($slot,self::ARMOR_SLOTS,true))throw new RuntimeException('Ungültiger Rüstungsslot.');
        $stmt=$this->pdo->prepare('SELECT * FROM armors WHERE id=?');$stmt->execute([$armorId]);$armor=$stmt->fetch();
        if(!$armor)throw new RuntimeException('Rüstung nicht gefunden.');if(($armor['item_kind']??'')==='shield')throw new RuntimeException('Schilde können hier nicht ausgerüstet werden.');if(($armor['armor_slot']??null)!==$slot)throw new RuntimeException('Diese Rüstung gehört nicht in diesen Slot.');
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';if($mysql)$q='INSERT INTO character_armor_slots (character_id,slot,armor_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE armor_id=VALUES(armor_id)';else$q='INSERT OR REPLACE INTO character_armor_slots (character_id,slot,armor_id) VALUES (?,?,?)';$this->pdo->prepare($q)->execute([$characterId,$slot,$armorId]);return $this->armorSlots($characterId)[$slot];
    }

    public function clearArmorSlot(int $characterId,string $slot): void { $this->assertCharacter($characterId);if(!in_array($slot,self::ARMOR_SLOTS,true))throw new RuntimeException('Ungültiger Rüstungsslot.');$this->pdo->prepare('DELETE FROM character_armor_slots WHERE character_id=? AND slot=?')->execute([$characterId,$slot]); }

    public function magicFocus(int $characterId): ?array { $stmt=$this->pdo->prepare('SELECT f.* FROM character_magic_focus s JOIN magic_foci f ON f.id=s.magic_focus_id WHERE s.character_id=?');$stmt->execute([$characterId]);$row=$stmt->fetch();if($row&&isset($row['is_magical']))$row['is_magical']=(bool)$row['is_magical'];if($row&&isset($row['is_elemental']))$row['is_elemental']=(bool)$row['is_elemental'];if($row){foreach(['element_binding'=>'element_binding','class_binding'=>'class_binding','magic_attack'=>'magic_attack'] as $from=>$to){if(isset($row[$from])&&is_string($row[$from]))$row[$to]=json_decode($row[$from],true)?:$row[$from];}}return $row?:null; }
    public function setMagicFocus(int $characterId,string $focusId): ?array { $this->assertCharacter($characterId);$stmt=$this->pdo->prepare('SELECT * FROM magic_foci WHERE id=?');$stmt->execute([$focusId]);if(!$stmt->fetch())throw new RuntimeException('Magiefokus nicht gefunden.');$mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$q=$mysql?'INSERT INTO character_magic_focus (character_id,magic_focus_id) VALUES (?,?) ON DUPLICATE KEY UPDATE magic_focus_id=VALUES(magic_focus_id)':'INSERT OR REPLACE INTO character_magic_focus (character_id,magic_focus_id) VALUES (?,?)';$this->pdo->prepare($q)->execute([$characterId,$focusId]);return $this->magicFocus($characterId); }
    public function clearMagicFocus(int $characterId): void { $this->assertCharacter($characterId);$this->pdo->prepare('DELETE FROM character_magic_focus WHERE character_id=?')->execute([$characterId]); }

    private function assertCharacter(int $id): void { $s=$this->pdo->prepare('SELECT 1 FROM characters WHERE id=?');$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Charakter nicht gefunden.'); }
    public function ensureSchema(): void { $this->pdo->exec('CREATE TABLE IF NOT EXISTS character_armor_slots (character_id INTEGER NOT NULL,slot VARCHAR(20) NOT NULL,armor_id VARCHAR(190) NOT NULL,PRIMARY KEY(character_id,slot))');$this->pdo->exec('CREATE TABLE IF NOT EXISTS character_magic_focus (character_id INTEGER PRIMARY KEY,magic_focus_id VARCHAR(190) NOT NULL)'); }
}
