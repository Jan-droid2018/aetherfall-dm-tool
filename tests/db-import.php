<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Services\ImportService;
use Aetherfall\Support\Database;

// Bewusster Integrationstest: nur nach Migration und nur explizit ausführen.
$pdo=Database::connection();$tables=['classes','abilities','creatures','creature_levels','bosses','boss_levels','spells'];
(new ImportService($pdo))->importAll();$first=[];foreach($tables as$t)$first[$t]=(int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
(new ImportService($pdo))->importAll();$second=[];foreach($tables as$t)$second[$t]=(int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
if($first!==$second){fwrite(STDERR,"Idempotenztest fehlgeschlagen.\n");exit(1);}echo "[OK] DB-Importer idempotent: ".json_encode($second,JSON_UNESCAPED_UNICODE)."\n";
