<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/** Un joueur d'un match : pseudo et hash de son jeton (vide pour un invité sans jeton). */
final readonly class Participant
{
    public function __construct(public string $name, public string $tokenHash)
    {
    }
}
