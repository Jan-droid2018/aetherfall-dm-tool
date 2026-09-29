<?php
declare(strict_types=1);

namespace Aetherfall\Services;

final class CharacterValidator
{
    public const ATTRIBUTE_CODES = ['ST','GE','BW','IN','WA','KR','CH','EM','WI','IT','AU'];

    public function validate(array $data): array
    {
        $errors = [];
        if (trim((string)($data['name'] ?? '')) === '') $errors['name'] = 'Name ist erforderlich.';
        if (trim((string)($data['player_name'] ?? '')) === '') $errors['player_name'] = 'Spieler ist erforderlich.';
        if (trim((string)($data['class_id'] ?? '')) === '') $errors['class_id'] = 'Klasse ist erforderlich.';
        $level = filter_var($data['level'] ?? null, FILTER_VALIDATE_INT);
        if ($level === false || $level < 1 || $level > 999) $errors['level'] = 'Stufe muss zwischen 1 und 999 liegen.';
        if (array_key_exists('current_hp', $data) && $data['current_hp'] !== '' && $data['current_hp'] !== null) {
            $currentHp = filter_var($data['current_hp'], FILTER_VALIDATE_INT);
            if ($currentHp === false || $currentHp < 0) $errors['current_hp'] = 'Aktuelle LP müssen mindestens 0 sein.';
        }
        if (!is_numeric($data['critical_damage_percent'] ?? null) || (float)$data['critical_damage_percent'] < 0) $errors['critical_damage_percent'] = 'Kritischer Schaden muss mindestens 0 sein.';
        $attributes = $data['attributes'] ?? [];
        $codes = array_map(static fn(array $row): string => strtoupper((string)($row['code'] ?? '')), is_array($attributes) ? $attributes : []);
        sort($codes); $expected = self::ATTRIBUTE_CODES; sort($expected);
        if ($codes !== $expected || count($codes) !== count(array_unique($codes))) {
            $errors['attributes'] = 'Alle 11 Attribute müssen genau einmal vorhanden sein.';
        } else {
            foreach ($attributes as $attribute) {
                $value = filter_var($attribute['value'] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < CharacterAttributeCalculator::MIN_VALUE || $value > CharacterAttributeCalculator::MAX_VALUE) {
                    $errors['attributes'] = 'Attributwerte müssen ganze Zahlen zwischen 1 und 100 sein.';
                }
                if (filter_var($attribute['bonus'] ?? null, FILTER_VALIDATE_INT) === false) {
                    $errors['attributes'] = 'Attribut-Boni müssen ganze Zahlen sein.';
                }
            }
        }
        return $errors;
    }
}

