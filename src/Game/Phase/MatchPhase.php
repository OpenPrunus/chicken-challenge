<?php

declare(strict_types=1);

namespace Chicken\Game\Phase;

use Chicken\Game\Client;
use Chicken\Game\GameMatch;
use Chicken\Game\MatchService;

/**
 * Comportement d'un match selon son état (pattern State). Par défaut une phase ignore tout message : chaque phase ne
 * redéfinit que ce qu'elle accepte (ex. on ne peut lancer que pendant la phase de service).
 */
abstract class MatchPhase
{
    /** @param array<string, mixed> $msg */
    public function onThrow(MatchService $service, GameMatch $match, Client $client, array $msg): void
    {
    }

    /** @param array<string, mixed> $msg */
    public function onCatch(MatchService $service, GameMatch $match, Client $client, array $msg): void
    {
    }

    public function onMiss(MatchService $service, GameMatch $match, Client $client): void
    {
    }

    public function onRematch(MatchService $service, GameMatch $match, Client $client): void
    {
    }

    /** Expiration des délais (un joueur qui ne répond plus). */
    public function onTick(MatchService $service, GameMatch $match, float $now): void
    {
    }
}
