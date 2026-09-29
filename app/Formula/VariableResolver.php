<?php
declare(strict_types=1);

namespace Aetherfall\Formula;

final class VariableResolver
{
    private const ATTRIBUTES = [
        'Stärke'=>'ST','Geschicklichkeit'=>'GE','Beweglichkeit'=>'BW','Intelligenz'=>'IN',
        'Wahrnehmung'=>'WA','Kreativität'=>'KR','Charisma'=>'CH','Empathie'=>'EM',
        'Willenskraft'=>'WI','Intuition'=>'IT','Ausweichen'=>'AU',
    ];

    public function canonical(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        foreach (self::ATTRIBUTES as $long => $short) {
            $name = preg_replace('/^' . preg_quote($long, '/') . '(?=-)/iu', $short, $name) ?? $name;
            $name = preg_replace('/^' . preg_quote($long . 's', '/') . '(?=-)/iu', $short, $name) ?? $name;
        }
        $name = preg_replace('/^[\p{L}]+-Stufe$/u', 'Stufe', $name) ?? $name;
        return mb_strtolower($name);
    }

    public function resolve(string $name, array $variables): ?float
    {
        $wanted = $this->canonical($name);
        foreach ($variables as $key => $value) {
            if ($this->canonical((string) $key) === $wanted && is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }
}

