<?php
declare(strict_types=1);

namespace Aetherfall\Services;

final class CombatProfileValueResolver
{
    public function applyPhaseOverride(array $profile,array $phaseData): array
    {
        $value=null;foreach($phaseData as $raw){if(!is_scalar($raw)||!preg_match('/override[^0-9]*([0-9]+(?:[.,][0-9]+)?)/i',(string)$raw,$match))continue;$value=(float)str_replace(',','.',$match[1]);break;}
        if($value===null)return$profile;
        $attributes=$profile['attributes']??[];foreach($attributes as &$attribute){$attribute['value']=$value;$attribute['modifier']=$value;$attribute['bonus']=$value;}unset($attribute);$profile['attributes']=$attributes;return$profile;
    }
    public function maxHp(array|string|null $combatValues, array|string|null $hpCalculation = null): ?int
    {
        $value = $this->firstPhaseValue($combatValues, 'maximale_lp', ['Maximale LP', 'Max LP']);
        if ($value !== null) {
            return max(0, (int)floor($value));
        }

        $hpCalculation = $this->decode($hpCalculation);
        foreach ($hpCalculation['formulas'] ?? [] as $formula) {
            if (!is_array($formula)) {
                continue;
            }
            $label = $this->normalize((string)($formula['label'] ?? ''));
            if ($label !== '' && !str_contains($label, 'phase1')) {
                continue;
            }
            $value = $this->resultBeforeLp((string)($formula['formula'] ?? $formula['raw'] ?? ''));
            if ($value !== null) {
                return max(0, (int)floor($value));
            }
        }

        foreach ($hpCalculation['formulas'] ?? [] as $formula) {
            if (!is_array($formula)) {
                continue;
            }
            $value = $this->resultBeforeLp((string)($formula['formula'] ?? $formula['raw'] ?? ''));
            if ($value !== null) {
                return max(0, (int)floor($value));
            }
        }

        return null;
    }

    public function physicalDefense(array|string|null $combatValues): ?int
    {
        $value = $this->firstPhaseValue($combatValues, 'physische_verteidigung', ['Physische Verteidigung', 'Phys-VTD']);
        return $value === null ? null : max(0, (int)floor($value));
    }

    public function magicDefense(array|string|null $combatValues): ?int
    {
        $value = $this->firstPhaseValue($combatValues, 'magieverteidigung', ['Magieverteidigung', 'Magische Verteidigung', 'Mag-VTD']);
        return $value === null ? null : max(0, (int)floor($value));
    }

    public function firstPhaseValue(array|string|null $combatValues, string $key, array $labels): ?float
    {
        return $this->phaseValue($combatValues, $key, $labels, 1);
    }

    public function phaseValue(array|string|null $combatValues, string $key, array $labels, int $phase = 1): ?float
    {
        $combatValues = $this->decode($combatValues);
        $direct = $combatValues['values'][$key] ?? $combatValues[$key] ?? null;
        $value = $this->number($direct);
        if ($value !== null) {
            return $value;
        }

        $wanted = array_map(fn(string $label): string => $this->normalize($label), $labels);
        $wanted[] = $this->normalize(str_replace('_', ' ', $key));
        foreach ($this->rows($combatValues) as $row) {
            if (!is_array($row) || !$this->isWantedRow($row, $wanted)) {
                continue;
            }

            foreach ($row as $column => $raw) {
                if ($this->matchesPhaseColumn((string)$column, $phase)) {
                    $value = $this->number($raw);
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
            foreach ($row as $column => $raw) {
                if ($this->isLabelColumn((string)$column)) {
                    continue;
                }
                $value = $this->number($raw);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function matchesPhaseColumn(string $column, int $phase): bool
    {
        $column = $this->normalize($column);
        if ($phase <= 1) {
            return $column === 'phase1';
        }
        if ($phase === 2) {
            return $column === 'phase2kernelexposed';
        }
        return str_contains($column, 'godmode');
    }

    private function rows(array $combatValues): array
    {
        $rows = is_array($combatValues['rows'] ?? null) ? $combatValues['rows'] : [];
        foreach ($combatValues['tables'] ?? [] as $table) {
            if (is_array($table['rows'] ?? null)) {
                $rows = [...$rows, ...$table['rows']];
            }
        }
        return $rows;
    }

    private function isWantedRow(array $row, array $wanted): bool
    {
        foreach ($row as $column => $raw) {
            if (!$this->isLabelColumn((string)$column) && $column !== array_key_first($row)) {
                continue;
            }
            if (in_array($this->normalize((string)$raw), $wanted, true)) {
                return true;
            }
        }
        return false;
    }

    private function isLabelColumn(string $column): bool
    {
        return in_array($this->normalize($column), ['kampfwert', 'wert', 'kennwert', 'bezeichnung'], true);
    }

    private function resultBeforeLp(string $formula): ?float
    {
        if (!preg_match('/=\s*[*_`]*\s*(-?\d+(?:[.,]\d+)?)\s*[*_`]*\s*LP/iu', $formula, $match)) {
            return null;
        }
        return (float)str_replace(',', '.', $match[1]);
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value) || !preg_match('/[-+]?\d+(?:[.,]\d+)?/', str_replace('`', '', $value), $match)) {
            return null;
        }
        return (float)str_replace(',', '.', $match[0]);
    }

    private function decode(array|string|null $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }
}
