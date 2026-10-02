<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Services\MagicFocusService;
use Aetherfall\Support\Database;
use Aetherfall\Support\Json;

$errors = [];
$jsonCount = 0;
$attributes = [];
foreach (glob(BASE_PATH . '/json/magic-foci/*.json') ?: [] as $file) {
    if (basename($file) === '_manifest.json') continue;
    try {
        $data = Json::decodeFile($file);
        if (empty($data['id'])) continue; // catalog summary
        $jsonCount++;
        $code = MagicFocusService::attributeCode((string)($data['magic_attribute'] ?? ''));
        if ($code === null) $errors[] = basename($file) . ': unbekanntes oder fehlendes magic_attribute.';
        else $attributes[$code] = ($attributes[$code] ?? 0) + 1;
    } catch (Throwable $e) { $errors[] = basename($file) . ': ' . $e->getMessage(); }
}

$database = [];
try {
    $pdo = Database::connection();
    $database['focus_count'] = (int)$pdo->query('SELECT COUNT(*) FROM magic_foci')->fetchColumn();
    $database['invalid_focus_attributes'] = 0;
    foreach ($pdo->query('SELECT id,magic_attribute FROM magic_foci')->fetchAll() as $row) {
        if (MagicFocusService::attributeCode((string)($row['magic_attribute'] ?? '')) === null) {
            $database['invalid_focus_attributes']++;
            $errors[] = 'Datenbankfokus ' . ($row['id'] ?? '?') . ': unbekanntes magic_attribute.';
        }
    }
    $database['invalid_equipped_relations'] = (int)$pdo->query('SELECT COUNT(*) FROM character_magic_focus r LEFT JOIN characters c ON c.id=r.character_id LEFT JOIN magic_foci f ON f.id=r.magic_focus_id WHERE c.id IS NULL OR f.id IS NULL')->fetchColumn();
    $database['spells_using_magic_attribute'] = (int)$pdo->query("SELECT COUNT(*) FROM spells WHERE COALESCE(attack_roll,'') LIKE '%Magieattribut-%' OR COALESCE(calculation,'') LIKE '%Magieattribut-%' OR COALESCE(rule_effect,'') LIKE '%Magieattribut-%'")->fetchColumn();
    foreach (['invalid_focus_attributes' => 'ungültige Magieattributwerte in magic_foci', 'invalid_equipped_relations' => 'ungültige ausgerüstete Magiefokus-Referenzen'] as $key => $label) {
        if (($database[$key] ?? 0) > 0) $errors[] = $label . ': ' . $database[$key];
    }
} catch (Throwable $e) { $database['error'] = $e->getMessage(); }

echo json_encode(['json_foci' => $jsonCount, 'attributes' => $attributes, 'database' => $database, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors ? 1 : 0);
