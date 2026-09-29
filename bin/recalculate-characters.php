<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Services\CharacterAttributeCalculator;
use Aetherfall\Services\CharacterHpCalculator;
use Aetherfall\Support\Database;
use Aetherfall\Support\Logger;

$pdo = Database::connection();
$attributeCalculator = new CharacterAttributeCalculator();
$hpCalculator = new CharacterHpCalculator();
$report = ['checked'=>0, 'modifiers'=>0, 'max_hp'=>0, 'warnings'=>[], 'errors'=>[]];

$characters = $pdo->query('SELECT id,name,class_id,level,max_hp,current_hp FROM characters ORDER BY id')->fetchAll();
$attributeQuery = $pdo->prepare('SELECT attribute_code,value,modifier,bonus FROM character_attributes WHERE character_id=?');
$roleQuery = $pdo->prepare("SELECT role,attribute_code FROM class_attribute_adjustments WHERE class_id=? AND role IN ('primary','secondary')");
$updateAttribute = $pdo->prepare('UPDATE character_attributes SET modifier=? WHERE character_id=? AND attribute_code=?');
$updateCharacter = $pdo->prepare('UPDATE characters SET max_hp=?,current_hp=? WHERE id=?');

foreach ($characters as $character) {
    $report['checked']++;
    try {
        $attributeQuery->execute([$character['id']]);
        $attributes = [];
        foreach ($attributeQuery->fetchAll() as $row) {
            $attributes[$row['attribute_code']] = $row;
        }
        if (count($attributes) !== 11) {
            $report['warnings'][] = "{$character['name']} (#{$character['id']}): nicht alle 11 Attribute vorhanden";
            continue;
        }

        $roleQuery->execute([$character['class_id']]);
        $roles = [];
        foreach ($roleQuery->fetchAll() as $role) $roles[$role['role']] = $role['attribute_code'];
        if (!isset($roles['primary'], $roles['secondary'], $attributes[$roles['primary']], $attributes[$roles['secondary']])) {
            $report['warnings'][] = "{$character['name']} (#{$character['id']}): Haupt-/Sekundärattribut fehlt";
            continue;
        }

        $newModifiers = [];
        foreach ($attributes as $code => $row) {
            $newModifiers[$code] = $attributeCalculator->calculateModifier((int)$row['value']);
        }
        $primary = $attributes[$roles['primary']];
        $secondary = $attributes[$roles['secondary']];
        $newMaxHp = $hpCalculator->calculateMaxHp(
            (int)$character['level'],
            $newModifiers[$roles['primary']],
            (float)$primary['bonus'],
            $newModifiers[$roles['secondary']],
            (float)$secondary['bonus']
        );
        $newCurrentHp = min((int)$character['current_hp'], $newMaxHp);

        $pdo->beginTransaction();
        foreach ($attributes as $code => $row) {
            if ((int)$row['modifier'] !== $newModifiers[$code]) {
                $updateAttribute->execute([$newModifiers[$code], $character['id'], $code]);
                $report['modifiers']++;
            }
        }
        if ((int)$character['max_hp'] !== $newMaxHp) $report['max_hp']++;
        $updateCharacter->execute([$newMaxHp, $newCurrentHp, $character['id']]);
        $pdo->commit();
    } catch (\InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $report['warnings'][] = "{$character['name']} (#{$character['id']}): {$e->getMessage()} – unverändert übersprungen";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        Logger::error($e);
        $report['errors'][] = "{$character['name']} (#{$character['id']}): {$e->getMessage()}";
    }
}

echo "Charaktere geprüft:     {$report['checked']}\n";
echo "Modifier aktualisiert:  {$report['modifiers']}\n";
echo "Max LP aktualisiert:     {$report['max_hp']}\n";
echo 'Warnungen:               ' . count($report['warnings']) . "\n";
echo 'Fehler:                  ' . count($report['errors']) . "\n";
foreach ($report['warnings'] as $warning) echo "WARNUNG: {$warning}\n";
foreach ($report['errors'] as $error) echo "FEHLER: {$error}\n";
exit($report['errors'] ? 1 : 0);
