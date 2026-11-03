<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\ClientStatus;
use Chicken\Game\Event;
use Chicken\Game\Lobby;

final readonly class CancelHandler implements MessageHandler
{
    public function __construct(private Lobby $lobby)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        if ($client->status === ClientStatus::Queue) {
            $client->status = ClientStatus::Lobby;
            $client->send(Event::Unqueued);
            $this->lobby->broadcast();
        }
    }
}
