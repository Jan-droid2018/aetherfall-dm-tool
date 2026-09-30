<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use PDO;
use RuntimeException;

final class CharacterClassService
{
    public const ROLES = ['primary','secondary_1','secondary_2'];
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    public function classes(int $characterId): array
    {
        $s=$this->pdo->prepare("SELECT cc.* FROM character_classes cc WHERE cc.character_id=? ORDER BY CASE cc.role WHEN 'primary' THEN 1 WHEN 'secondary_1' THEN 2 ELSE 3 END");$s->execute([$characterId]);$out=[];$name=$this->pdo->prepare('SELECT name FROM classes WHERE id=?');foreach($s->fetchAll() as $row){$name->execute([$row['class_id']]);$row['name']=$name->fetchColumn()?:$row['class_id'];$out[$row['role']]=$row;}return$out;
    }
    public function effectiveLevel(int $characterId): int { $rows=$this->classes($characterId);return max(array_merge([1],array_values(array_map(static fn($r)=>(int)$r['class_level'],$rows)))); }
    public function isOrigin(string $classId): bool { return mb_strtolower($classId)==='ursprungsvermaechtnis'||str_contains(mb_strtolower($classId),'ursprungsvermaechtnis'); }
    public function slotCount(string $role,string $classId): int { return $this->isOrigin($classId)?0:($role==='primary'?2:1); }
    public function save(int $characterId,array $relations): int
    {
        $normalized=[];$seen=[];$origin=false;
        foreach(self::ROLES as $role){$row=$relations[$role]??null;if(!$row||empty($row['class_id']))continue;$class=(string)$row['class_id'];$level=(int)($row['class_level']??1);if($level<1||$level>40)throw new RuntimeException('Klassenstufen müssen zwischen 1 und 40 liegen.');if(isset($seen[$class]))throw new RuntimeException('Eine Klasse darf nicht mehrfach ausgewählt werden.');$seen[$class]=true;$normalized[$role]=['class_id'=>$class,'class_level'=>$level];$origin=$origin||$this->isOrigin($class);}
        if(!isset($normalized['primary']))throw new RuntimeException('Eine Hauptklasse ist erforderlich.');if($origin&&(count($normalized)>1))throw new RuntimeException('Ursprungsvermächtnis kann nicht mit Nebenklassen kombiniert werden.');
        $this->pdo->beginTransaction();try{$this->pdo->prepare('DELETE FROM character_classes WHERE character_id=?')->execute([$characterId]);$s=$this->pdo->prepare('INSERT INTO character_classes (character_id,class_id,role,class_level) VALUES (?,?,?,?)');foreach($normalized as $role=>$row)$s->execute([$characterId,$row['class_id'],$role,$row['class_level']]);$max=max(array_column($normalized,'class_level'));$this->pdo->prepare('UPDATE characters SET class_id=?,level=? WHERE id=?')->execute([$normalized['primary']['class_id'],$max,$characterId]);$this->pdo->commit();return$max;}catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
    }
    private function ensureSchema(): void
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$ddl=$mysql?'CREATE TABLE IF NOT EXISTS character_classes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,character_id BIGINT UNSIGNED NOT NULL,class_id VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,role VARCHAR(20) NOT NULL,class_level SMALLINT UNSIGNED NOT NULL,UNIQUE KEY uq_cc_role (character_id,role),UNIQUE KEY uq_cc_class (character_id,class_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'CREATE TABLE IF NOT EXISTS character_classes (id INTEGER PRIMARY KEY AUTOINCREMENT,character_id INTEGER NOT NULL,class_id VARCHAR(120) NOT NULL,role VARCHAR(20) NOT NULL,class_level INTEGER NOT NULL,UNIQUE(character_id,role),UNIQUE(character_id,class_id))';$this->pdo->exec($ddl);if($mysql){try{$this->pdo->exec('ALTER TABLE character_classes MODIFY class_id VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');}catch(\Throwable){}}
        $s=$this->pdo->query('SELECT c.id,c.class_id,c.level FROM characters c LEFT JOIN character_classes cc ON cc.character_id=c.id WHERE cc.id IS NULL');$rows=$s?$s->fetchAll():[];if($rows){$ins=$this->pdo->prepare('INSERT INTO character_classes (character_id,class_id,role,class_level) VALUES (?,?,?,?)');foreach($rows as $r)$ins->execute([$r['id'],$r['class_id'],'primary',max(1,min(40,(int)$r['level']))]);}
    }
}
