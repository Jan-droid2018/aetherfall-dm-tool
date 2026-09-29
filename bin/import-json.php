<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Services\ImportService;
use Aetherfall\Support\Database;
use Aetherfall\Support\Json;

$pdo = Database::connection();
$minimumPacket = 16 * 1024 * 1024;
$currentPacket = (int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
if ($currentPacket < $minimumPacket) {
    try {
        $pdo->exec('SET GLOBAL max_allowed_packet = ' . $minimumPacket);
        Database::setConnection(null);
        $pdo = Database::connection();
        echo "max_allowed_packet wurde für den Import auf 16 MiB erhöht.\n";
    } catch (Throwable $e) {
        throw new RuntimeException('Die größten JSON-Dateien überschreiten max_allowed_packet. In XAMPP my.ini unter [mysqld] max_allowed_packet=16M setzen und MySQL neu starten.', 0, $e);
    }
}
$run = $pdo->prepare("INSERT INTO import_runs (started_at,status) VALUES (NOW(),'running')");
$run->execute();
$runId = (int) $pdo->lastInsertId();
try {
    $result = (new ImportService($pdo))->importAll();
    $finish = $pdo->prepare("UPDATE import_runs SET finished_at=NOW(),status='success',counts_json=?,warnings_json=? WHERE id=?");
    $finish->execute([Json::encode($result['counts']), Json::encode($result['warnings']), $runId]);
    foreach ($result['counts'] as $label => $count) { echo sprintf("%-22s %d\n", $label . ':', $count); }
    echo 'Warnungen:             ' . count($result['warnings']) . "\n";
    foreach ($result['warnings'] as $warning) { echo "- {$warning}\n"; }
} catch (Throwable $e) {
    $finish = $pdo->prepare("UPDATE import_runs SET finished_at=NOW(),status='failed',warnings_json=? WHERE id=?");
    $finish->execute([Json::encode([$e->getMessage()]), $runId]);
    throw $e;
}
