<?php

declare(strict_types=1);

namespace Chicken\Game\Phase;

use Chicken\Game\Client;
use Chicken\Game\Event;
use Chicken\Game\GameMatch;
use Chicken\Game\MatchService;

/** Match terminé : seule la revanche est possible, et il faut que les deux joueurs la demandent. */
final class OverPhase extends MatchPhase
{
    #[\Override]
    public function onRematch(MatchService $service, GameMatch $match, Client $client): void
    {
        if ($match->requestRematch($client->id)) {
            $service->restart($match);
        } else {
            $service->send($match->other($client->id), Event::RematchAsk);
        }
    }
}
