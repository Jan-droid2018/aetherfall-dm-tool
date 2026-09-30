<?php
declare(strict_types=1);

namespace Aetherfall\Formula;

final class FormulaEngine
{
    public function evaluate(string $formula, array $context = []): array
    {
        try {
            $tokens = (new Tokenizer())->tokenize($formula);
            $result = (new Parser($tokens, $context['variables'] ?? [], $context['dice'] ?? [], new VariableResolver()))->evaluate();
            return array_merge(['supported' => !$result['missing'], 'formula' => $formula], $result);
        } catch (FormulaException $e) {
            return ['supported' => false, 'formula' => $formula, 'value' => null, 'missing' => [], 'breakdown' => [], 'error' => $e->getMessage()];
        }
    }

    public function extractMath(string $text): ?string
    {
        $text = preg_replace('/\b(ST|GE|BW|IN|WA|KR|CH|EM|WI|IT|AU)-Mod\.?\b/iu', '$1-Modifikator', $text) ?? $text;
        $text = preg_replace('/\b(ST|GE|BW|IN|WA|KR|CH|EM|WI|IT|AU)-Bonus\.?\b/iu', '$1-Bonus', $text) ?? $text;
        if (preg_match_all('/`([^`]+)`/u', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                if (preg_match('/(?:\d*W\d+|[+*\/×÷⌊⌈])/iu', $candidate)) { return trim($candidate); }
            }
        }
        if (!preg_match('/(?:normaler\s+Waffenschaden|\d*\s*[WwDd]\s*\d+|\d+\s*\+)/iu', $text, $start, PREG_OFFSET_CAPTURE)) {
            return preg_match('/normal(?:er|en)?\s+(?:Waffen[- ]?)?angriff|Waffenschaden/iu', $text) ? 'Waffenschaden' : null;
        }
        $candidate = substr($text, $start[0][1]);
        $candidate = preg_replace('/normaler\s+Waffenschaden/iu', 'Waffenschaden', $candidate) ?? $candidate;
        $cutPatterns = [
            '/\s+gegen\s+/iu',
            '/\s+[\p{L}-]*schaden\b/iu',
            '/\s+(?:LP|Heilung|Barrierepunkte)\b/iu',
            '/[.;](?:\s|$)/u',
        ];
        $cut = strlen($candidate);
        foreach ($cutPatterns as $pattern) {
            if (preg_match($pattern, $candidate, $match, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, $match[0][1]);
            }
        }
        $candidate = trim(substr($candidate, 0, $cut), " \t\n\r\0\x0B:");
        if ($candidate !== '' && (preg_match('/[+\-*\/×÷x⌊⌈]/u', $candidate) || preg_match('/\d*\s*[WwDd]\s*\d+/u', $candidate))) { return $candidate; }
        return null;
    }
}

