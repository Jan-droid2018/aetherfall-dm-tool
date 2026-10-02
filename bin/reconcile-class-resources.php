<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Services\ClassResourceService;
use Aetherfall\Support\Database;

$pdo=Database::connection();$service=new ClassResourceService($pdo);$combat=in_array('--combat',$argv,true);
$report=['characters'=>['checked'=>0,'created'=>0,'removed'=>0,'clamped'=>0],'participants'=>['checked'=>0,'created'=>0,'removed'=>0,'updated'=>0],'warnings'=>[]];
$characters=$pdo->query('SELECT id FROM characters ORDER BY id')->fetchAll();
foreach($characters as $character){$result=$service->reconcileCharacterClassResources((int)$character['id']);$report['characters']['checked']++;$report['characters']['created']+=(int)$result['created'];$report['characters']['removed']+=(int)$result['removed'];$report['characters']['clamped']+=(int)$result['clamped'];$report['warnings']=array_merge($report['warnings'],$result['warnings']??[]);}
if($combat){$participants=$pdo->query("SELECT id FROM combat_participants WHERE participant_type='character' ORDER BY id")->fetchAll();foreach($participants as $participant){$result=$service->reconcileCombatParticipantResources((int)$participant['id']);$report['participants']['checked']++;$report['participants']['created']+=(int)$result['created'];$report['participants']['removed']+=(int)$result['removed'];$report['participants']['updated']+=(int)$result['updated'];$report['warnings']=array_merge($report['warnings'],$result['warnings']??[]);}}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
