<?php
declare(strict_types=1);

namespace Aetherfall\Repositories;

use PDO;

final class ArmorRepository
{
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    public function all(array $filters = []): array
    {
        $sql = 'SELECT id,external_id,name,display_name,item_kind,base_item_name,armor_slot,armor_archetype,quality,item_level,is_magical,physical_defense,magical_defense,shield_class,resistances_json,elements_json FROM armors WHERE 1=1';
        $args = [];
        if (array_key_exists('include_shields', $filters) && !in_array(strtolower((string)$filters['include_shields']), ['1','true','ja','yes'], true)) { $sql .= ' AND item_kind<>?'; $args[] = 'shield'; }
        $search = trim((string)($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') { $sql .= ' AND (name LIKE ? OR display_name LIKE ? OR base_item_name LIKE ?)'; $needle = '%' . $search . '%'; array_push($args, $needle, $needle, $needle); }
        foreach (['slot'=>'armor_slot','quality'=>'quality','armor_archetype'=>'armor_archetype'] as $filter=>$column) {
            $value = trim((string)($filters[$filter] ?? '')); if ($value !== '') { $sql .= " AND {$column}=?"; $args[] = $value; }
        }
        if (($level = trim((string)($filters['item_level'] ?? ''))) !== '' && ctype_digit($level)) { $sql .= ' AND item_level=?'; $args[] = (int)$level; }
        $sql .= ' ORDER BY COALESCE(display_name,name), id'; $limit = max(1, min(1000, (int)($filters['limit'] ?? 500))); $sql .= ' LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql); $stmt->execute($args); return array_map([$this, 'normalize'], $stmt->fetchAll());
    }

    public function find(string $id, bool $includeShield = false): ?array
    {
        $sql = 'SELECT id,external_id,name,display_name,item_kind,base_item_name,armor_slot,armor_archetype,quality,item_level,is_magical,physical_defense,magical_defense,shield_class,resistances_json,elements_json,special_properties,active_ability,class_binding,appearance,raw_json FROM armors WHERE id=?';
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$id]); $row = $stmt->fetch();
        if (!$row || (!$includeShield && ($row['item_kind'] ?? '') === 'shield')) return null;
        return $this->normalize($row, true);
    }

    private function normalize(array $row, bool $includeRaw = false): array
    {
        foreach (['is_magical'] as $key) $row[$key] = (bool)$row[$key];
        foreach (['resistances_json'=>'resistances','elements_json'=>'elements'] as $from=>$to) { $row[$to] = json_decode((string)($row[$from] ?? 'null'), true); unset($row[$from]); }
        if (!$includeRaw) { /* list rows intentionally omit the full source document */ }
        return $row;
    }

    public function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS armors (id VARCHAR(190) PRIMARY KEY,external_id VARCHAR(190) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,display_name VARCHAR(255) NULL,item_kind VARCHAR(80) NOT NULL,base_item_name VARCHAR(255) NULL,armor_slot VARCHAR(20) NULL,armor_archetype VARCHAR(190) NULL,quality VARCHAR(80) NULL,item_level INTEGER NULL,is_magical INTEGER NOT NULL DEFAULT 0,physical_defense DECIMAL(12,2) NULL,magical_defense DECIMAL(12,2) NULL,shield_class VARCHAR(80) NULL,resistances_json {$text} NULL,elements_json {$text} NULL,special_properties {$text} NULL,active_ability {$text} NULL,class_binding {$text} NULL,appearance {$text} NULL,schema_version VARCHAR(80) NOT NULL,source_file VARCHAR(255) NOT NULL,source_hash VARCHAR(64) NOT NULL,raw_json {$text} NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    }
}
