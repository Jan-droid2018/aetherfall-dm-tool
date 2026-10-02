<?php
declare(strict_types=1);

namespace Aetherfall\Services;

/**
 * Centralizes the mapping from the imported damage type text to the defense
 * category used by the combat resolver.  Sources stay free to describe their
 * own damage; the resolver never relies on weapon/spell source type.
 */
final class DamageTypeClassifier
{
    public const PHYSICAL = 'physical';
    public const MAGICAL = 'magical';
    public const UNKNOWN = 'unknown';

    public static function category(?string $damageType): string
    {
        $text = mb_strtolower(trim((string)$damageType));
        if ($text === '') return self::UNKNOWN;

        $physical = (bool)preg_match('/physisch|hieb|stich|schlag|schnitt|wucht|druck|waffenschaden/u', $text);
        $elemental = (bool)preg_match('/feuer|wasser|erde|luft|wind|licht|dunkel(?:heit)?/u', $text);
        $explicitMagical = (bool)preg_match('/fremdmagie|quantum|imaginary|glitch|elation|energie|gegen\s+magie(?:verteidigung)?|magisch(?:er|e|es|en)?\s+schaden/u', $text);
        // A phrase such as "Hieb, magisch geleitet" still describes a
        // physical damage instance. Elemental or explicitly magical damage
        // remains magical, matching the imported damage-type semantics.
        if ($physical && !$elemental && !$explicitMagical) return self::PHYSICAL;
        if ($elemental || $explicitMagical) return self::MAGICAL;
        if ($physical) return self::PHYSICAL;
        return self::UNKNOWN;
    }

    public static function element(?string $damageType): ?string
    {
        $text = mb_strtolower((string)$damageType);
        foreach ([['feuer','feuer'],['wasser','wasser'],['erde','erde'],['luft','luft'],['wind','luft'],['licht','licht'],['dunkelheit','dunkelheit'],['dunkel','dunkelheit']] as [$needle,$canonical]) {
            if (mb_stripos($text, $needle) !== false) return $canonical;
        }
        return null;
    }
}
