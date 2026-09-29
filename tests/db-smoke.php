<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Repositories\CharacterRepository;
use Aetherfall\Services\CharacterValidator;
use Aetherfall\Services\EncounterService;
use Aetherfall\Support\Database;

$pdo=Database::connection();$characters=new CharacterRepository($pdo);$encounters=new EncounterService($pdo);$characterId=null;$encounterId=null;
try{
    $ability=(string)$pdo->query("SELECT id FROM abilities WHERE class_id='taktbrecher' AND unlock_level=1 ORDER BY number LIMIT 1")->fetchColumn();
    $spell=(string)$pdo->query("SELECT id FROM spells ORDER BY id LIMIT 1")->fetchColumn();
    $data=['name'=>'__Codex Smoke Held__','player_name'=>'Test','class_id'=>'taktbrecher','level'=>1,'max_hp'=>999999,'current_hp'=>100,'critical_damage_percent'=>50,'attributes'=>array_map(static fn($c)=>['code'=>$c,'value'=>10,'modifier'=>99,'bonus'=>0],CharacterValidator::ATTRIBUTE_CODES),'ability_ids'=>[$ability],'spell_ids'=>[$spell]];
    $characterId=$characters->save($data);$loaded=$characters->find($characterId);if(count($loaded['attributes'])!==11||count($loaded['abilities'])!==1||count($loaded['spells'])!==1)throw new RuntimeException('Character-Relationen unvollständig.');if((int)$loaded['max_hp']!==110)throw new RuntimeException('Serverseitige Max-LP-Berechnung wurde nicht verwendet.');foreach($loaded['attributes']as$attribute)if((int)$attribute['modifier']!==0)throw new RuntimeException('Client-Modifier wurde nicht ignoriert.');
    $encounterId=$encounters->create('__Codex Smoke Encounter__');
    $encounters->addParticipant($encounterId,['participant_type'=>'character','reference_id'=>$characterId,'selected_level'=>99,'initiative'=>18]);
    $encounters->addParticipant($encounterId,['participant_type'=>'creature','reference_id'=>'grabhund','selected_level'=>1,'initiative'=>16]);
    $encounters->addParticipant($encounterId,['participant_type'=>'boss','reference_id'=>'omega-omega-aionios','selected_level'=>1,'initiative'=>12]);
    $fight=$encounters->find($encounterId);if(count($fight['participants'])!==3)throw new RuntimeException('Teilnehmer wurden nicht vollständig geladen.');$characterParticipant=array_values(array_filter($fight['participants'],static fn($participant)=>$participant['participant_type']==='character'))[0]??null;if(!$characterParticipant||(int)$characterParticipant['selected_level']!==1||(int)($characterParticipant['details']['level']??0)!==1)throw new RuntimeException('Charakterstufe wurde nicht autoritativ vom Charakterbogen übernommen.');if((float)($characterParticipant['details']['critical_damage_percent']??-1)!==50.0)throw new RuntimeException('Kritischer Schaden fehlt im Charakter-Kontext.');if(count($characterParticipant['content'])!==2)throw new RuntimeException('Charakterfähigkeiten und -zauber wurden im Combat Tracker nicht geladen.');$creatureParticipant=array_values(array_filter($fight['participants'],static fn($participant)=>$participant['participant_type']==='creature'))[0]??null;if(!$creatureParticipant||!isset($creatureParticipant['details']['critical_damage_percent']))throw new RuntimeException('Kritischer Schaden fehlt im Kreaturen-Kontext.');$pdo->prepare('UPDATE characters SET level=2 WHERE id=?')->execute([$characterId]);$refreshed=$encounters->find($encounterId);$refreshedCharacter=array_values(array_filter($refreshed['participants'],static fn($participant)=>$participant['participant_type']==='character'))[0]??null;if((int)($refreshedCharacter['selected_level']??0)!==2)throw new RuntimeException('Eine geänderte Charakterbogen-Stufe wurde nicht in den Combat Tracker übernommen.');
    $target=$fight['participants'][1];$criticalCalculation=['damage'=>['normal_damage'=>5,'critical_applied'=>true,'critical_damage_percent'=>40,'critical_bonus'=>2,'damage_after_critical'=>7,'value'=>7]];$result=$encounters->applyEffect($encounterId,['target_participant_id'=>$target['id'],'participant_id'=>$fight['participants'][0]['id'],'amount'=>7,'mode'=>'damage','calculation'=>$criticalCalculation]);if($result['after']!==$result['before']-7)throw new RuntimeException('HP-Schaden falsch.');if(!str_contains($result['message'],'kritisch')||!str_contains($result['message'],'Krit-Bonus +2'))throw new RuntimeException('Kritischer Treffer fehlt im Combat Log.');
    $encounters->moveTurn($encounterId,1);$encounters->moveTurn($encounterId,-1);
    echo "[OK] MariaDB-Smoke: Character CRUD, Relationen, Charakter/Kreatur/Boss, Initiative, HP und Log.\n";
}finally{
    if($encounterId)$pdo->prepare('DELETE FROM combat_encounters WHERE id=?')->execute([$encounterId]);
    if($characterId)$characters->delete($characterId);
}
