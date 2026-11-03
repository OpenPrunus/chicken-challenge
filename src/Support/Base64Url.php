<?php

declare(strict_types=1);

namespace Chicken\Support;

final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Décodage strict : false si la chaîne contient un caractère hors alphabet. */
    public static function decode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
