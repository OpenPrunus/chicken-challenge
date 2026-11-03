<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\Event;
use Chicken\Game\Invitations;
use Chicken\Game\Lobby;

final readonly class DeclineHandler implements MessageHandler
{
    public function __construct(private Lobby $lobby, private Invitations $invitations)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        $from = (int) ($msg['from'] ?? 0);
        if (!$this->invitations->has($client->id, $from)) {
            return;
        }
        $this->invitations->remove($client->id, $from);
        $this->lobby->get($from)?->send(Event::Declined, [
            'code' => 'refused', 'name' => $client->name, 'reason' => $client->name . ' declined the challenge.',
        ]);
    }
}
