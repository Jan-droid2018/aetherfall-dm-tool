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

// Weapon combat validation is intentionally data-driven: every structured or
// raw "Schadensformel - <profil>" field counts as a usable profile.
$weaponSingle = 0; $weaponMulti = 0; $weaponMissing = []; $profileKeys = []; $weaponProfilesTotal = 0; $localVariables = 0;
foreach (glob(BASE_PATH . '/json/weapons/*.json') ?: [] as $file) {
    if (basename($file) === '_manifest.json') continue;
    try {
        $weapon = Json::decodeFile($file); $profiles = [];
        $addProfile = static function (string $key, mixed $formula) use (&$profiles): void {
            if (!is_scalar($formula) || trim((string)$formula, " \t\r\n`.;:") === '' || trim((string)$formula) === '-') return;
            $key = mb_strtolower(trim($key)); $key = strtr($key, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','–'=>'-','—'=>'-']);
            if (str_contains($key, 'einhaend')) $key = 'one_handed'; elseif (str_contains($key, 'zweihand') || str_contains($key, 'zweihaend')) $key = 'two_handed'; elseif (in_array($key, ['standard','schadensformel'], true)) $key = 'default';
            $profiles[$key] = trim((string)$formula, " \t\r\n`.;:");
        };
        $damage = $weapon['formulas']['damage'] ?? null;
        if (is_array($damage)) foreach ($damage as $key => $formula) $addProfile((string)$key, $formula); elseif (is_scalar($damage)) $addProfile('default', $damage);
        foreach (array_merge((array)($weapon['raw_fields'] ?? []), (array)($weapon['extra_fields'] ?? [])) as $field => $formula) if (preg_match('/^Schadensformel\s*[-–—]\s*(.+)$/u', (string)$field, $m)) $addProfile($m[1], $formula);
        foreach (array_keys($profiles) as $key) $profileKeys[$key] = true;
        $weaponProfilesTotal += count($profiles);
        if (!$profiles) $weaponMissing[] = (string)($weapon['name'] ?? basename($file)); elseif (count($profiles) > 1) $weaponMulti++; else $weaponSingle++;
        if (!empty($weapon['formulas']['core_value'])) $localVariables++;
    } catch (Throwable $e) { $errors[] = basename($file) . ': Waffenprofilprüfung: ' . $e->getMessage(); }
}
echo "\nWaffen-Combat-Profile:\n";
echo "  Single-Profile-Waffen: {$weaponSingle}\n  Multi-Profile-Waffen: {$weaponMulti}\n  Profile gesamt: {$weaponProfilesTotal}\n  Profile: " . implode(', ', array_keys($profileKeys)) . "\n  Waffen ohne Schadensformel: " . count($weaponMissing) . "\n  Waffen mit lokalen Formelvariablen: {$localVariables}\n";
if ($weaponMissing) echo "  Fehlende Waffen: " . implode(', ', $weaponMissing) . "\n";
$manifestFile = BASE_PATH . '/json/weapons/_manifest.json';
if (is_file($manifestFile)) { $manifest = Json::decodeFile($manifestFile); $expectedProfiles = (int)($manifest['profile_count'] ?? 0); if ($expectedProfiles > 0 && $expectedProfiles !== $weaponProfilesTotal) $errors[] = "Waffenmanifest profile_count={$expectedProfiles}, tatsächlich {$weaponProfilesTotal}."; }
if ($errors) {
    echo "\nFehler:\n- " . implode("\n- ", $errors) . "\n";
    exit(1);
}
echo "\nAlle Regeldateien sind gültig; Manifeste wurden korrekt übersprungen.\n";

