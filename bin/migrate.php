<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Support\Database;
use Aetherfall\Support\Env;

try {
    $pdo = Database::connection();
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) !== 1049) {
        throw $e;
    }
    $database = (string)Env::get('DB_NAME', 'aetherfall_dm_tool');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException('DB_NAME enthält unzulässige Zeichen.');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'));
    $server = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Database::setConnection(null);
    $pdo = Database::connection();
    echo "Datenbank {$database} wurde angelegt.\n";
}
// Existing installations predate normalized class resources. Prepare their
// table before schema.sql creates the new state tables that reference it.
try {
    $pdo->exec('ALTER TABLE class_resources ADD UNIQUE KEY uq_class_resource_id (resource_id)');
} catch (Throwable) {}
$pdo->exec((string) file_get_contents(BASE_PATH . '/database/schema.sql'));

$attributes = [
    ['ST', 'Stärke'], ['GE', 'Geschicklichkeit'], ['BW', 'Beweglichkeit'],
    ['IN', 'Intelligenz'], ['WA', 'Wahrnehmung'], ['KR', 'Kreativität'],
    ['CH', 'Charisma'], ['EM', 'Empathie'], ['WI', 'Willenskraft'],
    ['IT', 'Intuition'], ['AU', 'Ausweichen'],
];
$stmt = $pdo->prepare('INSERT INTO attribute_definitions (code,name,sort_order) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order)');
foreach ($attributes as $index => [$code, $name]) {
    $stmt->execute([$code, $name, $index + 1]);
}

echo "Migration und Attribut-Seed erfolgreich.\n";

