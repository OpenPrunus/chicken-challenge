<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/** Une rencontre enregistrée : facteur d'évolution de la note à appliquer et nouvel état du limiteur. */
final readonly class Meeting
{
    public function __construct(public float $factor, public PairLimiter $limiter)
    {
    }
}
