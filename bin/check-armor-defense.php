<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Support\Database;
use Aetherfall\Support\Json;

$errors = [];
$jsonCount = 0;
$slotCount = 0;
$shieldCount = 0;
$physicalCount = 0;
$magicalCount = 0;

$number = static function (mixed $value): bool {
    return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
};
$slot = static function (mixed $value): ?string {
    $value = mb_strtolower(trim((string)$value));
    $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return match ($value) {
        'head', 'kopf', 'helm', 'helmet' => 'head',
        'chest', 'brust', 'torso' => 'chest',
        'hands', 'hand', 'haende', 'hande', 'arme' => 'hands',
        'legs', 'beine' => 'legs',
        'feet', 'fuesse', 'fusse' => 'feet',
        default => null,
    };
};

foreach (glob(BASE_PATH . '/json/armors/*.json') ?: [] as $file) {
    if (basename($file) === '_manifest.json') continue;
    try {
        $data = Json::decodeFile($file);
        $jsonCount++;
        $kind = (string)($data['item_kind'] ?? 'armor');
        $rawSlot = is_array($data['slot'] ?? null) ? (($data['slot']['key'] ?? $data['slot']['name'] ?? null)) : ($data['slot'] ?? null);
        $normalizedSlot = $slot($rawSlot);
        if ($kind === 'shield') { $shieldCount++; continue; }
        if ($normalizedSlot === null) $errors[] = basename($file) . ': unbekannter oder fehlender Rüstungsslot.';
        else $slotCount++;
        $defense = is_array($data['defense'] ?? null) ? $data['defense'] : [];
        $physical = is_array($defense['physical'] ?? null) ? ($defense['physical']['value'] ?? null) : ($defense['physical'] ?? null);
        $magical = is_array($defense['magical'] ?? null) ? ($defense['magical']['general'] ?? $defense['magical']['value'] ?? null) : ($defense['magical'] ?? null);
        if ($physical !== null) { if (!$number($physical)) $errors[] = basename($file) . ': physische Verteidigung ist nicht numerisch.'; else $physicalCount++; }
        if ($magical !== null) { if (!$number($magical)) $errors[] = basename($file) . ': magische Verteidigung ist nicht numerisch.'; else $magicalCount++; }
    } catch (Throwable $e) {
        $errors[] = basename($file) . ': ' . $e->getMessage();
    }
}

$db = ['armor_count' => null, 'invalid_relations' => null, 'shield_relations' => null, 'invalid_slots' => null];
try {
    $pdo = Database::connection();
    $db['armor_count'] = (int)$pdo->query('SELECT COUNT(*) FROM armors')->fetchColumn();
    $db['invalid_relations'] = (int)$pdo->query('SELECT COUNT(*) FROM character_armor_slots s LEFT JOIN armors a ON a.id=s.armor_id WHERE a.id IS NULL')->fetchColumn();
    $db['shield_relations'] = (int)$pdo->query("SELECT COUNT(*) FROM character_armor_slots s JOIN armors a ON a.id=s.armor_id WHERE a.item_kind='shield'")->fetchColumn();
    $db['invalid_slots'] = (int)$pdo->query("SELECT COUNT(*) FROM character_armor_slots WHERE slot NOT IN ('head','chest','hands','legs','feet')")->fetchColumn();
    foreach (['invalid_relations' => 'ungültige Rüstungsreferenzen', 'shield_relations' => 'Schilde in Rüstungsslots', 'invalid_slots' => 'ungültige Rüstungsslots'] as $key => $label) {
        if (($db[$key] ?? 0) > 0) $errors[] = $label . ': ' . $db[$key];
    }
} catch (Throwable $e) {
    $db['error'] = 'Datenbankprüfung übersprungen: ' . $e->getMessage();
}

echo json_encode([
    'json_armors' => $jsonCount,
    'non_shield_armors_with_known_slots' => $slotCount,
    'shield_definitions' => $shieldCount,
    'physical_defense_values' => $physicalCount,
    'magical_defense_values' => $magicalCount,
    'database' => $db,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors ? 1 : 0);
