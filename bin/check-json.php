<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Support\Json;

$expected = [
    'classes' => 'aetherfall.class_abilities.v1',
    'creatures' => 'aetherfall.creatures.v1',
    'bosses' => 'aetherfall.bosses.v1',
    'spells' => 'aetherfall.spells.v1',
    'weapons' => 'aetherfall.weapons.v1',
    'armors' => 'aetherfall.armors.v1',
    'magic-foci' => 'aetherfall.magic_foci.v1',
];
$errors = [];
$counts = [];
foreach ($expected as $area => $schema) {
    $counts[$area] = 0;
    foreach (glob(BASE_PATH . "/json/{$area}/*.json") ?: [] as $file) {
        if (basename($file) === '_manifest.json') {
            continue;
        }
        try {
            $data = Json::decodeFile($file);
            foreach (['schema_version', 'id'] as $key) {
                if (!isset($data[$key])) {
                    throw new RuntimeException("Pflichtfeld {$key} fehlt");
                }
            }
            if ($data['schema_version'] !== $schema) {
                throw new RuntimeException("Schema {$data['schema_version']} statt {$schema}");
            }
            $counts[$area]++;
        } catch (Throwable $e) {
            $errors[] = basename($file) . ': ' . $e->getMessage();
        }
    }
}
foreach ($counts as $area => $count) {
    echo sprintf("%-12s %d gültige Dateien\n", ucfirst($area) . ':', $count);
}
if ($errors) {
    echo "\nFehler:\n- " . implode("\n- ", $errors) . "\n";
    exit(1);
}
echo "\nAlle Regeldateien sind gültig; Manifeste wurden korrekt übersprungen.\n";

