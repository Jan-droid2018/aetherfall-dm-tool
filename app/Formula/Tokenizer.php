<?php
declare(strict_types=1);

namespace Aetherfall\Formula;

final class Tokenizer
{
    public function tokenize(string $formula): array
    {
        $formula = str_replace(['`', '×', '÷', '–', '—', "\u{2212}"], ['', '*', '/', '-', '-', '-'], trim($formula));
        $formula = preg_replace('/\s+[xX]\s+/u', ' * ', $formula) ?? $formula;
        $tokens = [];
        $length = mb_strlen($formula);
        for ($i = 0; $i < $length;) {
            $char = mb_substr($formula, $i, 1);
            if (preg_match('/\s/u', $char)) { $i++; continue; }
            if (str_contains('+-*/()', $char)) {
                $tokens[] = ['type' => $char, 'value' => $char]; $i++; continue;
            }
            if ($char === '⌊') { $tokens[] = ['type' => 'floor_open', 'value' => $char]; $i++; continue; }
            if ($char === '⌋') { $tokens[] = ['type' => 'floor_close', 'value' => $char]; $i++; continue; }
            if ($char === '⌈') { $tokens[] = ['type' => 'ceil_open', 'value' => $char]; $i++; continue; }
            if ($char === '⌉') { $tokens[] = ['type' => 'ceil_close', 'value' => $char]; $i++; continue; }
            $rest = mb_substr($formula, $i);
            if (preg_match('/^(\d*\s*[WwDd]\s*\d+)/u', $rest, $m)) {
                $raw = preg_replace('/\s+/', '', $m[1]);
                $raw = preg_replace('/[dD]/', 'W', (string) $raw);
                $raw = preg_replace('/w/', 'W', (string) $raw);
                if (str_starts_with((string) $raw, 'W')) { $raw = '1' . $raw; }
                $tokens[] = ['type' => 'dice', 'value' => $raw];
                $i += mb_strlen($m[1]); continue;
            }
            if (preg_match('/^(\d+(?:[.,]\d+)?)/u', $rest, $m)) {
                $tokens[] = ['type' => 'number', 'value' => (float) str_replace(',', '.', $m[1])];
                $i += mb_strlen($m[1]); continue;
            }
            $start = $i;
            while ($i < $length) {
                $current = mb_substr($formula, $i, 1);
                if (str_contains('+*/()⌊⌋⌈⌉', $current)) { break; }
                if ($current === '-') {
                    $before = $i > $start ? mb_substr($formula, $i - 1, 1) : '';
                    $after = $i + 1 < $length ? mb_substr($formula, $i + 1, 1) : '';
                    if (preg_match('/\s/u', $before) || preg_match('/\s/u', $after) || $before === '' || $after === '') { break; }
                }
                $i++;
            }
            $identifier = trim(mb_substr($formula, $start, $i - $start));
            if ($identifier === '') {
                throw new FormulaException("Unbekanntes Zeichen an Position {$i}: {$char}");
            }
            $lower = mb_strtolower($identifier);
            if (in_array($lower, ['floor', 'abrunden'], true)) { $tokens[] = ['type' => 'floor_fn', 'value' => $identifier]; }
            elseif (in_array($lower, ['ceil', 'aufrunden'], true)) { $tokens[] = ['type' => 'ceil_fn', 'value' => $identifier]; }
            else { $tokens[] = ['type' => 'identifier', 'value' => $identifier]; }
        }
        $tokens[] = ['type' => 'eof', 'value' => null];
        return $tokens;
    }
}

