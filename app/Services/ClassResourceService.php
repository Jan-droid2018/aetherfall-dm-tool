<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use Aetherfall\Formula\FormulaEngine;
use PDO;
use RuntimeException;

/** Keeps resource definitions (class data) separate from character/combat state. */
final class ClassResourceService
{
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    public function definitionsForCharacter(int $characterId): array
    {
        $sql = 'SELECT cc.id character_class_id,cc.role,cc.class_id,cc.class_level,c.name class_name,cr.* FROM character_classes cc JOIN classes c ON c.id=cc.class_id JOIN class_resources cr ON cr.class_id=cc.class_id WHERE cc.character_id=? ORDER BY CASE cc.role WHEN \'primary\' THEN 1 WHEN \'secondary_1\' THEN 2 ELSE 3 END';
        $s=$this->pdo->prepare($sql);$s->execute([$characterId]);$out=[];
        foreach($s->fetchAll() as $row){$row['maximum']=$this->resolveMaximum($row,(int)$row['class_level']);$row['current']=$this->currentForClass((int)$row['character_class_id'],(string)$row['resource_id'],$row['maximum']);$out[]=$row;}
        return $out;
    }

    public function ensureCharacterStates(int $characterId): void
    {
        $rows=$this->definitionsForCharacter($characterId);
        $insert=$this->pdo->prepare('INSERT INTO character_class_resources (character_class_id,class_resource_id,current_value) VALUES (?,?,?)');
        foreach($rows as $row){
            $exists=$this->pdo->prepare('SELECT current_value FROM character_class_resources WHERE character_class_id=? AND class_resource_id=?');$exists->execute([(int)$row['character_class_id'],$row['resource_id']]);$value=$exists->fetchColumn();
            if($value===false){$start=$this->resolveStart($row,$row['maximum']);$insert->execute([(int)$row['character_class_id'],$row['resource_id'],$start]);}
            elseif($row['maximum']!==null && (float)$value>(float)$row['maximum']){$this->pdo->prepare('UPDATE character_class_resources SET current_value=? WHERE character_class_id=? AND class_resource_id=?')->execute([(float)$row['maximum'],(int)$row['character_class_id'],$row['resource_id']]);}
        }
    }

    public function setCharacterCurrent(int $characterId,string $resourceId,float $value): array
    {
        $this->ensureCharacterStates($characterId);$s=$this->pdo->prepare('SELECT cc.id,cr.*,cc.class_level FROM character_classes cc JOIN class_resources cr ON cr.class_id=cc.class_id WHERE cc.character_id=? AND cr.resource_id=?');$s->execute([$characterId,$resourceId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Klassenressource ist diesem Charakter nicht zugeordnet.');$max=$this->resolveMaximum($row,(int)$row['class_level']);$value=$this->clamp($value,$max);$this->pdo->prepare('UPDATE character_class_resources SET current_value=? WHERE character_class_id=? AND class_resource_id=?')->execute([$value,(int)$row['id'],$resourceId]);return['resource_id'=>$resourceId,'current'=>$value,'maximum'=>$max];
    }

    public function createCombatSnapshot(int $participantId,int $characterId): void
    {
        $this->ensureCharacterStates($characterId);$rows=$this->definitionsForCharacter($characterId);$insert=$this->pdo->prepare('INSERT INTO combat_participant_resources (combat_participant_id,character_class_id,class_resource_id,current_value,max_value_snapshot) VALUES (?,?,?,?,?)');
        foreach($rows as $row){$current=(float)$row['current'];$exists=$this->pdo->prepare('SELECT id FROM combat_participant_resources WHERE combat_participant_id=? AND class_resource_id=?');$exists->execute([$participantId,$row['resource_id']]);if($exists->fetchColumn()!==false)continue;$insert->execute([$participantId,(int)$row['character_class_id'],$row['resource_id'],$current,$row['maximum']]);}
    }

    public function statesForParticipant(int $participantId): array
    {
        try{$s=$this->pdo->prepare('SELECT cpr.*,cr.name,cr.class_id,cc.role,c.name class_name FROM combat_participant_resources cpr JOIN class_resources cr ON cr.resource_id=cpr.class_resource_id LEFT JOIN character_classes cc ON cc.id=cpr.character_class_id LEFT JOIN classes c ON c.id=cc.class_id WHERE cpr.combat_participant_id=? ORDER BY cpr.id');$s->execute([$participantId]);return $s->fetchAll();}catch(\Throwable){return[];}
    }

    public function setCombatCurrent(int $participantId,string $resourceId,float $value): array
    {
        $s=$this->pdo->prepare('SELECT * FROM combat_participant_resources WHERE combat_participant_id=? AND class_resource_id=?');$s->execute([$participantId,$resourceId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Kampfressource nicht gefunden.');$value=$this->clamp($value,$row['max_value_snapshot']);$this->pdo->prepare('UPDATE combat_participant_resources SET current_value=? WHERE id=?')->execute([$value,(int)$row['id']]);return['resource_id'=>$resourceId,'current'=>$value,'maximum'=>$row['max_value_snapshot']];
    }

    /** Spend exactly once for an execution id. Returns false when it was already spent. */
    public function spend(int $participantId,string $executionId,string $resourceId,float $amount): bool
    {
        if($amount<=0)return false;$this->pdo->beginTransaction();try{$check=$this->pdo->prepare('SELECT id FROM combat_resource_transactions WHERE combat_participant_id=? AND execution_id=? AND class_resource_id=?');$check->execute([$participantId,$executionId,$resourceId]);if($check->fetchColumn()!==false){$this->pdo->commit();return false;}$lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$this->pdo->prepare('SELECT current_value FROM combat_participant_resources WHERE combat_participant_id=? AND class_resource_id=?'.$lock);$s->execute([$participantId,$resourceId]);$current=$s->fetchColumn();if($current===false||((float)$current<$amount))throw new RuntimeException('Nicht genug Klassenressource vorhanden.');$this->pdo->prepare('UPDATE combat_participant_resources SET current_value=current_value-? WHERE combat_participant_id=? AND class_resource_id=?')->execute([$amount,$participantId,$resourceId]);$this->pdo->prepare('INSERT INTO combat_resource_transactions (combat_participant_id,execution_id,class_resource_id,delta) VALUES (?,?,?,?)')->execute([$participantId,$executionId,$resourceId,-$amount]);$this->pdo->commit();return true;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function parseCosts(?string $costs,?string $resourceText): array
    {
        $text=trim((string)$costs);if($text==='')$text=trim((string)$resourceText);$out=[];if($text==='')return$out;foreach($this->definitionsByResource() as $def){$name=preg_quote((string)$def['name'],'/');if(preg_match('/(\d+(?:[.,]\d+)?)\s+'.$name.'/iu',$text,$m))$out[]=['resource_id'=>$def['resource_id'],'name'=>$def['name'],'amount'=>(float)str_replace(',','.',$m[1])];}return$out;
    }

    public function resolveMaximum(array $definition,int $level): ?float
    {
        $formula=(string)($definition['maximum_formula']??'');if($formula===''){$raw=json_decode((string)($definition['raw_json']??''),true)?:[];$formula=(string)($raw['maximum']??'');}
        if($formula==='')return null;if(preg_match('/`([^`]+)`/u',$formula,$m))$formula=$m[1];$formula=str_replace(['×','−','–','—'],['*','-','-','-'],$formula);$formula=preg_replace('/[A-Za-zÄÖÜäöüß_-]+-Stufe/u','Stufe',$formula)??$formula;$result=(new FormulaEngine())->evaluate($formula,['variables'=>['Stufe'=>$level]]);return $result['supported']&&$result['value']!==null?(float)$result['value']:$this->tableMaximum($definition,$level);
    }

    private function tableMaximum(array $definition,int $level): ?float
    { $raw=json_decode((string)($definition['raw_json']??''),true)?:[];foreach(($raw['tables']??[]) as $table)foreach(($table['rows']??[]) as $row){if(count($row)<2)continue;$range=(string)$row[0];if(preg_match('/^(\d+)\s*[–-]\s*(\d+)$/u',$range,$m)&&$level>=(int)$m[1]&&$level<=(int)$m[2])return(float)str_replace(',','.',$row[1]);if((string)$level===trim($range)&&is_numeric($row[1]))return(float)$row[1];}return null; }
    private function resolveStart(array $definition,?float $max): float { $text=(string)($definition['start_value']??'');if($max!==null&&preg_match('/maximum|höchstwert|vollständig/iu',$text))return$max;if(preg_match('/\b(\d+(?:[.,]\d+)?)\b/u',$text,$m))return$this->clamp((float)str_replace(',','.',$m[1]),$max);return$max??0.0; }
    private function clamp(float $value,mixed $max): float { $value=max(0,$value);return $max!==null&&$max!==''?min($value,(float)$max):$value; }
    private function currentForClass(int $classId,string $resourceId,?float $max): float { $s=$this->pdo->prepare('SELECT current_value FROM character_class_resources WHERE character_class_id=? AND class_resource_id=?');$s->execute([$classId,$resourceId]);$v=$s->fetchColumn();return$v===false?$this->clamp(0,$max):$this->clamp((float)$v,$max); }
    private function definitionsByResource(): array { try{return$this->pdo->query('SELECT resource_id,name FROM class_resources')->fetchAll();}catch(\Throwable){return[];} }
    private function ensureSchema(): void
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        try{$this->pdo->exec("CREATE TABLE IF NOT EXISTS class_resources (class_id VARCHAR(120) PRIMARY KEY,resource_id VARCHAR(120) NOT NULL,name VARCHAR(190) NOT NULL,form TEXT NULL,maximum_formula TEXT NULL,start_value TEXT NULL,base_generation TEXT NULL,generation TEXT NULL,consumption TEXT NULL,relief TEXT NULL,persistence TEXT NULL,recovery TEXT NULL,stacking TEXT NULL,transfer TEXT NULL,loss_decay TEXT NULL,visibility TEXT NULL,multiclass_boundary TEXT NULL,base_function TEXT NULL,rounding TEXT NULL,source_file VARCHAR(255) NULL,source_hash VARCHAR(64) NULL,raw_json TEXT NOT NULL)");}catch(\Throwable){}
        try{$this->pdo->exec("CREATE TABLE IF NOT EXISTS character_class_resources (id {$id},character_class_id INTEGER NOT NULL,class_resource_id VARCHAR(120) NOT NULL,current_value DECIMAL(12,2) NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(character_class_id,class_resource_id))");}catch(\Throwable){}
        try{$this->pdo->exec("CREATE TABLE IF NOT EXISTS combat_participant_resources (id {$id},combat_participant_id INTEGER NOT NULL,character_class_id INTEGER NULL,class_resource_id VARCHAR(120) NOT NULL,current_value DECIMAL(12,2) NOT NULL DEFAULT 0,max_value_snapshot DECIMAL(12,2) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(combat_participant_id,class_resource_id))");}catch(\Throwable){}
        try{$this->pdo->exec("CREATE TABLE IF NOT EXISTS combat_resource_transactions (id {$id},combat_participant_id INTEGER NOT NULL,execution_id VARCHAR(190) NOT NULL,class_resource_id VARCHAR(120) NOT NULL,delta DECIMAL(12,2) NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(combat_participant_id,execution_id,class_resource_id))");}catch(\Throwable){}
        try{$this->pdo->query('SELECT maximum_formula FROM class_resources LIMIT 1');}catch(\Throwable){foreach(['form','maximum_formula','start_value','base_generation','generation','consumption','relief','persistence','recovery','stacking','transfer','loss_decay','visibility','multiclass_boundary','base_function','rounding','source_file','source_hash'] as $column){try{$this->pdo->exec("ALTER TABLE class_resources ADD COLUMN {$column} TEXT NULL");}catch(\Throwable){}}}
    }
}
