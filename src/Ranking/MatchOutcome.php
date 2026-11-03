<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/** Ce qu'un match classé change pour un joueur : nouvelle note, variation, rang et facteur appliqué. */
final readonly class MatchOutcome
{
    public function __construct(public int $rating, public int $delta, public int $rank, public float $factor)
    {
    }

    /** @return array{rating: int, delta: int, rank: int, factor: float} */
    public function toArray(): array
    {
        return ['rating' => $this->rating, 'delta' => $this->delta, 'rank' => $this->rank, 'factor' => $this->factor];
    }
}
