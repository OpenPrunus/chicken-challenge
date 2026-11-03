<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\Event;
use Chicken\Game\MatchService;

final readonly class LeaveHandler implements MessageHandler
{
    public function __construct(private MatchService $matches)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        $this->matches->leave($client);
        $client->send(Event::Left);
    }
}
