<?php

declare(strict_types=1);

namespace Chicken\Support;

final class Num
{
    /** Nombre fini ramené dans [min, max] ; null si la valeur n'est pas un nombre (chaîne, null, NAN, INF...). */
    public static function clamped(mixed $value, float $min, float $max): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $f = (float) $value;

        return is_finite($f) ? max($min, min($max, $f)) : null;
    }

    /** Nombre fini quelconque (int ou float), sinon null. */
    public static function finite(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $f = (float) $value;

        return is_finite($f) ? $f : null;
    }
}
