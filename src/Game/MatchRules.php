<?php

declare(strict_types=1);

namespace Chicken\Game;

final class MatchRules
{
    final public const int MAX_POINTS = 12;
    final public const int TARGET = 4;
    final public const int LEAD = 2;

    /** Fin de partie : 4 points avec 2 d'écart, ou 12 points au plus. */
    public static function isOver(int $a, int $b): bool
    {
        return $a === self::MAX_POINTS || $b === self::MAX_POINTS
            || (max($a, $b) >= self::TARGET && abs($a - $b) >= self::LEAD);
    }

    /** Score final plausible : chaque côté entre 0 et 12, et la partie est bien terminée. */
    public static function isFinalScore(int $me, int $opp): bool
    {
        return $me >= 0 && $opp >= 0 && $me <= self::MAX_POINTS && $opp <= self::MAX_POINTS && self::isOver($me, $opp);
    }
}
