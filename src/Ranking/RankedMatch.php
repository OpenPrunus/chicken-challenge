<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/** Résultat de l'enregistrement d'un match classé : le nouveau classement et l'effet sur chaque joueur. */
final readonly class RankedMatch
{
    public function __construct(public Ranking $ranking, public MatchOutcome $winner, public MatchOutcome $loser)
    {
    }
}
