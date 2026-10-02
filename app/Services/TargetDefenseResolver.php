<?php
declare(strict_types=1);

namespace Aetherfall\Services;

use PDO;

/**
 * Single target-defense entry point for characters, creatures and bosses.
 * The returned defense is already selected for the damage category, while
 * physical/magical totals remain available for UI breakdowns.
 */
final class TargetDefenseResolver
{
    private CharacterDefenseService $characterDefense;

    public function __construct(private PDO $pdo)
    {
        $this->characterDefense = new CharacterDefenseService($pdo);
    }

    public function resolvePhysicalDefense(int $participantId): float
    {
        return (float)$this->resolve($participantId, 'Hieb')['physical_defense'];
    }

    public function resolveMagicalDefense(int $participantId): float
    {
        return (float)$this->resolve($participantId, 'Feuer')['magical_defense'];
    }

    public function resolve(int $participantId, ?string $damageType): array
    {
        $result = [
            'category' => DamageTypeClassifier::category($damageType),
            'defense' => 0.0,
            'physical_defense' => 0.0,
            'magical_defense' => 0.0,
            'resistance' => 0.0,
            'sources' => [],
            'breakdown' => [],
        ];
        if (!$participantId) return $result;

        $stmt = $this->pdo->prepare('SELECT participant_type,reference_id,selected_level,selected_phase FROM combat_participants WHERE id=?');
        $stmt->execute([$participantId]);
        $participant = $stmt->fetch();
        if (!$participant) return $result;

        if ($participant['participant_type'] === 'character') {
            $defense = $this->characterDefense->getDefenseBreakdown((int)$participant['reference_id']);
            $result['physical_defense'] = (float)$defense['physical'];
            $result['magical_defense'] = (float)$defense['magical'];
            $result['breakdown'] = $defense['breakdown'];
            $result['defense'] = $result['category'] === DamageTypeClassifier::PHYSICAL
                ? $result['physical_defense']
                : ($result['category'] === DamageTypeClassifier::MAGICAL ? $result['magical_defense'] : 0.0);
            if ($result['defense'] > 0) {
                $result['sources'][] = $result['category'] === DamageTypeClassifier::PHYSICAL
                    ? 'Physische Verteidigung der ausgerüsteten Rüstung'
                    : 'Magische Verteidigung der ausgerüsteten Rüstung';
            }
            return $result;
        }

        $table = $participant['participant_type'] === 'creature' ? 'creature_levels' : 'boss_levels';
        $foreign = $participant['participant_type'] === 'creature' ? 'creature_id' : 'boss_id';
        $phaseColumn = $participant['participant_type'] === 'boss' ? ',phase_values_json' : '';
        $stmt = $this->pdo->prepare("SELECT physical_defense,magic_defense,elemental_resistances_json{$phaseColumn} FROM {$table} WHERE {$foreign}=? AND level=?");
        $stmt->execute([$participant['reference_id'], $participant['selected_level']]);
        $profile = $stmt->fetch() ?: [];
        if (!$profile) return $result;

        if ($participant['participant_type'] === 'boss' && $participant['selected_phase']) {
            $phaseRows = json_decode((string)($profile['phase_values_json'] ?? '[]'), true)[0]['rows'] ?? [];
            $phaseRow = $phaseRows[(int)$participant['selected_phase'] - 1] ?? [];
            if (isset($phaseRow['Phys-VTD'])) $profile['physical_defense'] = $this->parseNumber($phaseRow['Phys-VTD']);
            if (isset($phaseRow['Mag-VTD'])) $profile['magic_defense'] = $this->parseNumber($phaseRow['Mag-VTD']);
        }

        $result['physical_defense'] = (float)($profile['physical_defense'] ?? 0);
        $result['magical_defense'] = (float)($profile['magic_defense'] ?? 0);
        $result['defense'] = $result['category'] === DamageTypeClassifier::PHYSICAL
            ? $result['physical_defense']
            : ($result['category'] === DamageTypeClassifier::MAGICAL ? $result['magical_defense'] : 0.0);
        if ($result['defense'] > 0) {
            $result['sources'][] = $result['category'] === DamageTypeClassifier::PHYSICAL
                ? 'Physische Verteidigung des Zielprofils'
                : 'Magische Verteidigung des Zielprofils';
        }

        $element = DamageTypeClassifier::element($damageType);
        if ($element !== null && $result['category'] === DamageTypeClassifier::MAGICAL) {
            $resistances = json_decode((string)($profile['elemental_resistances_json'] ?? '[]'), true);
            $entry = $resistances['elements'][$element] ?? null;
            if ($entry) {
                $percent = (float)($entry['percent'] ?? 0);
                if (($entry['type'] ?? '') === 'vulnerability') $percent = -$percent;
                $result['resistance'] = $percent;
                $result['sources'][] = ucfirst($element) . ': ' . ($entry['raw'] ?? 'neutral');
            }
        }
        return $result;
    }

    private function parseNumber(mixed $value): float
    {
        return (float)str_replace(',', '.', preg_replace('/[^0-9,.-]/u', '', (string)$value) ?? '0');
    }
}
