<?php

declare(strict_types=1);

namespace Chicken\Ranking;

final class Elo
{
    final public const int START = 1000;
    final public const int FLOOR = 100;

    /** Probabilité que A batte B (formule d'Elo). */
    public static function expected(float $ratingA, float $ratingB): float
    {
        return 1.0 / (1.0 + 10 ** (($ratingB - $ratingA) / 400.0));
    }

    /** Facteur K : les nouveaux joueurs bougent plus vite pour trouver leur niveau. */
    public static function kFactor(int $played): int
    {
        return $played < 10 ? 40 : 24;
    }
}
