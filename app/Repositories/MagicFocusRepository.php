<?php
declare(strict_types=1);

namespace Aetherfall\Repositories;

use PDO;

final class MagicFocusRepository
{
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    public function all(array $filters = []): array
    {
        $sql = 'SELECT id,external_id,name,subtitle,display_name,item_type,base_focus_type,category,quality,item_level,is_magical,standard_magic_attack,magic_attack,final_magic_attack,handling,magic_attribute,weight,element_binding,is_elemental,attack_roll,class_binding FROM magic_foci WHERE 1=1'; $args=[];
        $search = trim((string)($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') { $sql .= ' AND (name LIKE ? OR display_name LIKE ? OR category LIKE ? OR base_focus_type LIKE ?)'; $needle='%'.$search.'%'; array_push($args,$needle,$needle,$needle,$needle); }
        foreach (['quality'=>'quality','category'=>'category','magic_attribute'=>'magic_attribute'] as $filter=>$column) { $value=trim((string)($filters[$filter]??'')); if($value!==''){$sql.=" AND {$column}=?";$args[]=$value;} }
        if (array_key_exists('elemental',$filters) && $filters['elemental'] !== '') { $sql.=' AND is_elemental=?'; $args[]=(int)in_array(strtolower((string)$filters['elemental']),['1','true','ja','yes'],true); }
        $level=trim((string)($filters['item_level']??'')); if ($level!=='' && ctype_digit($level)) { $sql.=' AND item_level=?'; $args[]=(int)$level; }
        $sql.=' ORDER BY COALESCE(display_name,name), id'; $limit=max(1,min(1000,(int)($filters['limit']??500))); $sql.=' LIMIT '.$limit;
        $stmt=$this->pdo->prepare($sql);$stmt->execute($args);return array_map([$this,'normalize'],$stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,external_id,name,subtitle,display_name,item_type,base_focus_type,category,quality,item_level,is_magical,standard_magic_attack,magic_attack,final_magic_attack,handling,magic_attribute,weight,element_binding,is_elemental,attack_roll,critical_modification,class_binding,active_ability,special_properties,effect_calculation,spell_interaction,lore,special_rules,raw_json FROM magic_foci WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();return $row?$this->normalize($row,true):null;
    }

    private function normalize(array $row, bool $includeRaw=false): array
    {
        $row['is_magical']=(bool)$row['is_magical'];$row['is_elemental']=(bool)$row['is_elemental'];
        foreach(['element_binding'=>'element_binding','class_binding'=>'class_binding'] as $from=>$to){$row[$to]=json_decode((string)($row[$from]??'null'),true);}
        if (isset($row['magic_attack']) && is_string($row['magic_attack'])) $row['magic_attack']=json_decode($row['magic_attack'],true)?:$row['magic_attack'];
        return $row;
    }

    public function ensureSchema(): void
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$text=$mysql?'LONGTEXT':'TEXT';$this->pdo->exec("CREATE TABLE IF NOT EXISTS magic_foci (id VARCHAR(190) PRIMARY KEY,external_id VARCHAR(190) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,subtitle VARCHAR(255) NULL,display_name VARCHAR(255) NULL,item_type VARCHAR(190) NULL,base_focus_type VARCHAR(190) NULL,category VARCHAR(190) NULL,quality VARCHAR(80) NULL,item_level INTEGER NULL,is_magical INTEGER NOT NULL DEFAULT 0,standard_magic_attack TEXT NULL,magic_attack TEXT NULL,final_magic_attack TEXT NULL,handling VARCHAR(190) NULL,magic_attribute VARCHAR(190) NULL,weight VARCHAR(80) NULL,element_binding {$text} NULL,is_elemental INTEGER NOT NULL DEFAULT 0,attack_roll TEXT NULL,critical_modification TEXT NULL,class_binding {$text} NULL,active_ability {$text} NULL,special_properties {$text} NULL,effect_calculation {$text} NULL,spell_interaction {$text} NULL,lore {$text} NULL,special_rules {$text} NULL,schema_version VARCHAR(80) NOT NULL,source_file VARCHAR(255) NOT NULL,source_hash VARCHAR(64) NOT NULL,raw_json {$text} NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    }
}
