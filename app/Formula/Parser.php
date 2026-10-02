<?php
declare(strict_types=1);

namespace Aetherfall\Formula;

final class Parser
{
    private int $position = 0;
    private array $missing = [];
    private array $breakdown = [];

    public function __construct(
        private array $tokens,
        private array $variables,
        private array $dice,
        private VariableResolver $resolver,
    ) {}

    public function evaluate(): array
    {
        $value = $this->expression();
        if ($this->current()['type'] !== 'eof') {
            throw new FormulaException('Unerwarteter Rest in der Formel: ' . (string) $this->current()['value']);
        }
        return ['value' => $this->missing ? null : $value, 'missing' => array_values(array_unique($this->missing)), 'breakdown' => $this->breakdown];
    }

    private function expression(): float
    {
        $value = $this->term();
        while (in_array($this->current()['type'], ['+', '-'], true)) {
            $operator = $this->advance()['type']; $right = $this->term();
            $value = $operator === '+' ? $value + $right : $value - $right;
        }
        return $value;
    }

    private function term(): float
    {
        $value = $this->unary();
        while (in_array($this->current()['type'], ['*', '/'], true)) {
            $operator = $this->advance()['type']; $right = $this->unary();
            if ($operator === '/' && abs($right) < 0.0000001) { throw new FormulaException('Division durch null.'); }
            $value = $operator === '*' ? $value * $right : $value / $right;
        }
        return $value;
    }

    private function unary(): float
    {
        if ($this->match('+')) { return $this->unary(); }
        if ($this->match('-')) { return -$this->unary(); }
        return $this->primary();
    }

    private function primary(): float
    {
        $token = $this->advance();
        if ($token['type'] === 'number') { $this->breakdown[] = ['source'=>(string)$token['value'],'value'=>$token['value']]; return $token['value']; }
        if ($token['type'] === 'dice') {
            $value = $this->findDice($token['value']);
            if ($value === null) { $this->missing[] = 'Würfel ' . $token['value']; return 0.0; }
            $this->breakdown[] = ['source'=>$token['value'] . ' (manuell)','value'=>$value]; return $value;
        }
        if ($token['type'] === 'identifier') {
            $value = $this->resolver->resolve($token['value'], $this->variables);
            if ($value === null) { $this->missing[] = $token['value']; return 0.0; }
            $this->breakdown[] = ['source'=>$token['value'],'value'=>$value]; return $value;
        }
        if ($token['type'] === '(') { $value = $this->expression(); $this->expect(')'); return $value; }
        if ($token['type'] === 'floor_open') {
            // Some imported rule tables use the readable form ⌊(expression)
            // without a trailing ⌋. Treat the parenthesized form as the same
            // mathematical floor while still requiring balanced expressions.
            if ($this->match('(')) {
                $value = $this->expression();
                $this->expect(')');
                // Imported formulas commonly write ⌊(numerator) / divisor⌋.
                // Continue parsing operators that are still inside the floor
                // delimiters after the parenthesized numerator.
                while (in_array($this->current()['type'], ['+', '-', '*', '/'], true)) {
                    $operator = $this->advance()['type']; $right = $this->term();
                    if ($operator === '/' && abs($right) < 0.0000001) { throw new FormulaException('Division durch null.'); }
                    $value = $operator === '+' ? $value + $right : ($operator === '-' ? $value - $right : ($operator === '*' ? $value * $right : $value / $right));
                }
                if ($this->match('floor_close')) {}
                $value = floor($value);
            }
            else { $value = floor($this->expression()); $this->expect('floor_close'); }
            $this->breakdown[]=['source'=>'Abrunden','value'=>$value]; return $value;
        }
        if ($token['type'] === 'ceil_open') {
            if ($this->match('(')) {
                $value = $this->expression();
                $this->expect(')');
                while (in_array($this->current()['type'], ['+', '-', '*', '/'], true)) {
                    $operator = $this->advance()['type']; $right = $this->term();
                    if ($operator === '/' && abs($right) < 0.0000001) { throw new FormulaException('Division durch null.'); }
                    $value = $operator === '+' ? $value + $right : ($operator === '-' ? $value - $right : ($operator === '*' ? $value * $right : $value / $right));
                }
                if ($this->match('ceil_close')) {}
                $value = ceil($value);
            }
            else { $value = ceil($this->expression()); $this->expect('ceil_close'); }
            $this->breakdown[]=['source'=>'Aufrunden','value'=>$value]; return $value;
        }
        if (in_array($token['type'], ['floor_fn','ceil_fn'], true)) {
            $this->expect('('); $value = $this->expression(); $this->expect(')');
            $value = $token['type'] === 'floor_fn' ? floor($value) : ceil($value);
            $this->breakdown[]=['source'=>$token['type'] === 'floor_fn' ? 'Abrunden' : 'Aufrunden','value'=>$value]; return $value;
        }
        throw new FormulaException('Unerwartetes Token: ' . (string) $token['value']);
    }

    private function findDice(string $notation): ?float
    {
        foreach ($this->dice as $key => $value) {
            $normalized = strtoupper((string) $key);
            if (str_starts_with($normalized, 'W')) { $normalized = '1' . $normalized; }
            if ($normalized === strtoupper($notation) && is_numeric($value)) { return (float) $value; }
        }
        return null;
    }

    private function current(): array { return $this->tokens[$this->position]; }
    private function advance(): array { return $this->tokens[$this->position++]; }
    private function match(string $type): bool { if ($this->current()['type'] !== $type) return false; $this->position++; return true; }
    private function expect(string $type): void { if (!$this->match($type)) throw new FormulaException("Erwartet: {$type}"); }
}

