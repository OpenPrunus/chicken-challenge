<?php

declare(strict_types=1);

namespace Chicken\Support;

/** Pseudo de joueur : même règle pour le site (classement IA) et pour le serveur multijoueur. */
final class Nickname
{
    public const int MAX_LENGTH = 15;

    /** Lettres, chiffres, espace, « _ - . » ; 15 caractères au plus. */
    public static function clean(string $raw): string
    {
        return $raw
            |> (static fn (string $s): string => preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $s) ?? '')
            |> mb_trim(...)
            |> (static fn (string $s): string => mb_substr($s, 0, self::MAX_LENGTH))
            |> mb_trim(...);
    }

    /** Clé de comparaison : insensible à la casse, accents compris. */
    public static function key(string $name): string
    {
        return mb_strtolower($name);
    }
}
