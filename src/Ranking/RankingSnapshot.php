<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/** Ce que contient le fichier de classement : les joueurs et l'historique des rencontres. */
final readonly class RankingSnapshot
{
    public function __construct(public Ranking $ranking = new Ranking(), public PairLimiter $pairs = new PairLimiter())
    {
    }
}
