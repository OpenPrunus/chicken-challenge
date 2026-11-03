<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\ClientStatus;
use Chicken\Game\Event;
use Chicken\Game\Lobby;
use Chicken\Game\MatchService;

/** Matchmaking automatique : on rejoint le premier joueur en attente, sinon on se met en file. */
final readonly class AutoHandler implements MessageHandler
{
    public function __construct(private Lobby $lobby, private MatchService $matches)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        if ($client->status !== ClientStatus::Lobby) {
            return;
        }
        $waiting = $this->lobby->firstQueued($client);
        if ($waiting !== null) {
            $this->matches->start($waiting, $client);

            return;
        }
        $client->status = ClientStatus::Queue;
        $client->send(Event::Queued);
        $this->lobby->broadcast();
    }
}
