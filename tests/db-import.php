<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Services\ImportService;
use Aetherfall\Support\Database;

// Bewusster Integrationstest: nur nach Migration und nur explizit ausführen.
$pdo=Database::connection();$tables=['classes','abilities','creatures','creature_levels','bosses','boss_levels','spells','weapons','armors','magic_foci'];
(new ImportService($pdo))->importAll();$first=[];foreach($tables as$t)$first[$t]=(int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
(new ImportService($pdo))->importAll();$second=[];foreach($tables as$t)$second[$t]=(int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
if($first!==$second){fwrite(STDERR,"Idempotenztest fehlgeschlagen.\n");exit(1);}echo "[OK] DB-Importer idempotent: ".json_encode($second,JSON_UNESCAPED_UNICODE)."\n";
$armorCount=(int)$pdo->query('SELECT COUNT(*) FROM armors')->fetchColumn();$shieldCount=(int)$pdo->query("SELECT COUNT(*) FROM armors WHERE item_kind='shield'")->fetchColumn();$focusCount=(int)$pdo->query('SELECT COUNT(*) FROM magic_foci')->fetchColumn();if($armorCount!==96||$shieldCount!==10||$focusCount!==93)throw new RuntimeException('Rüstungs-/Magiefokus-Import unvollständig.');$raw=(string)$pdo->query("SELECT raw_json FROM magic_foci WHERE id='mythisch-is40-erststern-das-axiom-der-offenen-moeglichkeit'")->fetchColumn();if($raw===''||!str_contains($raw,'class_binding'))throw new RuntimeException('Komplexer Magiefokus-Rohdatensatz fehlt.');echo "[OK] Rüstungen, Schilde und Magiefoki vollständig und idempotent importiert.\n";
$sample=$pdo->query("SELECT name,base_weapon_type,quality,item_level,core_die,handling,attack_formula,damage_formula FROM weapons WHERE id='gewoehnlich-is01-muehlklinge'")->fetch();if(!$sample||$sample['name']!=='Mühlklinge'||$sample['base_weapon_type']!=='Messer'||$sample['quality']!=='Gewöhnlich'||(int)$sample['item_level']!==1||$sample['core_die']!=='1W4'||$sample['handling']!=='Einhändig'||trim((string)$sample['attack_formula'])===''||trim((string)$sample['damage_formula'])==='')throw new RuntimeException('Mühlklinge-Kernfelder fehlen.');$element=$pdo->query("SELECT id,elements_json,is_magical,damage_formula FROM weapons WHERE is_elemental=1 LIMIT 1")->fetch();if(!$element||!(int)$element['is_magical']||!json_decode((string)$element['elements_json'],true)||trim((string)$element['damage_formula'])==='')throw new RuntimeException('Elementarwaffe unvollständig.');$special=$pdo->query("SELECT raw_json FROM weapons WHERE class_restriction IS NOT NULL LIMIT 1")->fetchColumn();if(!$special||!str_contains((string)$special,'class_binding'))throw new RuntimeException('Sonderwaffen-Rohdaten fehlen.');echo "[OK] Waffen-Kernfelder, Elementarwaffe und Sonderwaffe geprüft.\n";
