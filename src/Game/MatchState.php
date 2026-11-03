<?php

declare(strict_types=1);

namespace Chicken\Game;

use Chicken\Game\Phase\FlightPhase;
use Chicken\Game\Phase\MatchPhase;
use Chicken\Game\Phase\OverPhase;
use Chicken\Game\Phase\ServePhase;

/** États d'un match. Chacun délègue son comportement à une phase (pattern State). */
enum MatchState
{
    case Serve;     // un joueur doit lancer
    case Flight;    // la poule vole, le défenseur doit répondre
    case Over;      // match terminé (revanche possible)

    public function phase(): MatchPhase
    {
        return match ($this) {
            self::Serve => new ServePhase(),
            self::Flight => new FlightPhase(),
            self::Over => new OverPhase(),
        };
    }
}
