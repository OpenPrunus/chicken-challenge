<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\ClientStatus;
use Chicken\Game\Event;
use Chicken\Game\Invitations;
use Chicken\Game\Lobby;
use Chicken\Game\MatchService;

final readonly class AcceptHandler implements MessageHandler
{
    public function __construct(private Lobby $lobby, private Invitations $invitations, private MatchService $matches)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        $from = (int) ($msg['from'] ?? 0);
        $challenger = $this->lobby->get($from);
        if (
            !$this->invitations->has($client->id, $from)
            || $challenger === null
            || $challenger->status !== ClientStatus::Lobby
            || $client->status !== ClientStatus::Lobby
        ) {
            $client->send(Event::Error, ['code' => 'stale_invite', 'msg' => 'This challenge is no longer valid.']);

            return;
        }
        $this->invitations->remove($client->id, $from);
        $this->matches->start($challenger, $client);
    }
}
