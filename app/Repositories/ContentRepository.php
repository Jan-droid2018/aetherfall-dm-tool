<?php
declare(strict_types=1);

namespace Aetherfall\Repositories;

use PDO;

final class ContentRepository
{
    public function __construct(private PDO $pdo) {}
    public function stats(): array
    {
        $result=[]; foreach (['classes'=>'Klassen','abilities'=>'Fähigkeiten','creatures'=>'Kreaturen','bosses'=>'Bosse','spells'=>'Zauber'] as $table=>$label) $result[$label]=(int)$this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $result['Letzter Import']=$this->pdo->query("SELECT finished_at FROM import_runs WHERE status='success' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null; return $result;
    }
    public function classes(): array { return $this->pdo->query("SELECT c.id,c.name,GROUP_CONCAT(CONCAT(caa.role,':',caa.attribute_code,':',caa.adjustment) ORDER BY FIELD(caa.role,'primary','secondary','penalty')) adjustments FROM classes c LEFT JOIN class_attribute_adjustments caa ON caa.class_id=c.id GROUP BY c.id,c.name ORDER BY c.name")->fetchAll(); }
    public function abilities(string $classId, int $level=999): array { $s=$this->pdo->prepare('SELECT id,name,unlock_level,type,costs,effect,attack_roll,calculation,damage_type,action_cost FROM abilities WHERE class_id=? AND unlock_level<=? ORDER BY unlock_level,number');$s->execute([$classId,$level]);return $s->fetchAll(); }
    public function spells(array $filters=[]): array
    {
        $sql='SELECT s.id,s.name,s.grade,s.effect_type,s.element_id,e.name element_name,s.mana_cost_raw,s.attack_roll,s.calculation,s.damage_type,s.rule_effect FROM spells s JOIN spell_elements e ON e.id=s.element_id WHERE 1=1';$args=[];
        if(!empty($filters['element'])){$sql.=' AND s.element_id=?';$args[]=$filters['element'];} if(!empty($filters['grade'])){$sql.=' AND s.grade=?';$args[]=$filters['grade'];} if(!empty($filters['effect_type'])){$sql.=' AND s.effect_type=?';$args[]=$filters['effect_type'];} if(!empty($filters['q'])){$sql.=' AND s.name LIKE ?';$args[]='%'.$filters['q'].'%';}
        $sql.=' ORDER BY e.name,s.grade,s.number LIMIT 1000';$s=$this->pdo->prepare($sql);$s->execute($args);return $s->fetchAll();
    }
    public function creatures(): array { return $this->pdo->query('SELECT c.id,c.name,c.challenge_rating,GROUP_CONCAT(cl.level ORDER BY cl.level) levels FROM creatures c LEFT JOIN creature_levels cl ON cl.creature_id=c.id GROUP BY c.id,c.name,c.challenge_rating ORDER BY c.name')->fetchAll(); }
    public function bosses(): array { return $this->pdo->query('SELECT b.id,b.name,b.challenge_rating,b.phase_count,GROUP_CONCAT(bl.level ORDER BY bl.level) levels FROM bosses b LEFT JOIN boss_levels bl ON bl.boss_id=b.id GROUP BY b.id,b.name,b.challenge_rating,b.phase_count ORDER BY b.name')->fetchAll(); }
}

