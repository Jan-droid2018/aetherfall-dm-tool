<?php
declare(strict_types=1);

namespace Aetherfall\Support;

use JsonException;

final class Json
{
    public static function decodeFile(string $file): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException("Ungültiges JSON in {$file}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException("JSON-Wurzel ist kein Objekt: {$file}");
        }
        return $data;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

