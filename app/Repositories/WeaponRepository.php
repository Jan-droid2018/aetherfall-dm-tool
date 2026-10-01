<?php
declare(strict_types=1);

namespace Aetherfall\Repositories;

use PDO;

final class WeaponRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function all(array $filters = []): array
    {
        $sql = 'SELECT id,name,display_name,subtitle,base_weapon_type,category,quality,item_level,is_magical,is_elemental,elements_json,core_die,handling,range_text,damage_type,weight,attack_attribute,attack_formula,damage_formula,critical_modification,special_properties,active_ability,class_restriction,appearance FROM weapons WHERE 1=1';
        $args = [];
        $search = trim((string)($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (name LIKE ? OR display_name LIKE ? OR base_weapon_type LIKE ? OR category LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($args, $needle, $needle, $needle, $needle);
        }
        foreach (['quality' => 'quality', 'category' => 'category'] as $filter => $column) {
            if (($value = trim((string)($filters[$filter] ?? ''))) !== '') {
                $sql .= " AND {$column}=?";
                $args[] = $value;
            }
        }
        if (($value = trim((string)($filters['item_level'] ?? ''))) !== '' && ctype_digit($value)) {
            $sql .= ' AND item_level=?';
            $args[] = (int)$value;
        }
        if (array_key_exists('is_magical', $filters) && $filters['is_magical'] !== '' && $filters['is_magical'] !== null) {
            $raw = $filters['is_magical'];
            $args[] = in_array(strtolower((string)$raw), ['1', 'true', 'ja', 'yes'], true) ? 1 : 0;
            $sql .= ' AND is_magical=?';
        }
        $sql .= ' ORDER BY COALESCE(display_name,name), id';
        $limit = (int)($filters['limit'] ?? 500);
        $limit = max(1, min(1000, $limit));
        $sql .= ' LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['is_magical'] = (bool)$row['is_magical'];
            $row['is_elemental'] = (bool)$row['is_elemental'];
            $row['elements'] = json_decode((string)($row['elements_json'] ?? '[]'), true) ?: [];
            unset($row['elements_json']);
        }
        return $rows;
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,name,display_name,subtitle,base_weapon_type,category,quality,item_level,is_magical,is_elemental,elements_json,core_die,handling,range_text,damage_type,weight,attack_attribute,attack_formula,damage_formula,critical_modification,special_properties,active_ability,class_restriction,appearance,raw_json FROM weapons WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['is_magical'] = (bool)$row['is_magical'];
        $row['is_elemental'] = (bool)$row['is_elemental'];
        $row['elements'] = json_decode((string)($row['elements_json'] ?? '[]'), true) ?: [];
        unset($row['elements_json']);
        return $row;
    }

    public function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS weapons (id VARCHAR(190) PRIMARY KEY,name VARCHAR(255) NOT NULL,display_name VARCHAR(255) NULL,subtitle VARCHAR(255) NULL,base_weapon_type VARCHAR(190) NULL,category VARCHAR(190) NULL,quality VARCHAR(80) NULL,item_level INTEGER NULL,is_magical INTEGER NOT NULL DEFAULT 0,is_elemental INTEGER NOT NULL DEFAULT 0,elements_json TEXT NULL,core_die VARCHAR(80) NULL,handling VARCHAR(80) NULL,range_text VARCHAR(190) NULL,damage_type VARCHAR(190) NULL,weight VARCHAR(80) NULL,attack_attribute VARCHAR(190) NULL,attack_formula TEXT NULL,damage_formula TEXT NULL,critical_modification TEXT NULL,special_properties TEXT NULL,active_ability TEXT NULL,class_restriction VARCHAR(190) NULL,appearance TEXT NULL,schema_version VARCHAR(80) NOT NULL,source_file VARCHAR(255) NOT NULL,source_hash VARCHAR(64) NOT NULL,raw_json {$text} NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    }
}
