<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\ClientStatus;
use Chicken\Game\Event;
use Chicken\Game\Invitations;
use Chicken\Game\Lobby;
use Chicken\Game\MatchService;

/** Défi lancé en cliquant sur un pseudo du salon. */
final readonly class ChallengeHandler implements MessageHandler
{
    public function __construct(private Lobby $lobby, private Invitations $invitations, private MatchService $matches)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        $target = $this->lobby->get((int) ($msg['to'] ?? 0));
        if (
            $target === null
            || $target->id === $client->id
            || $target->status !== ClientStatus::Lobby
            || $client->status !== ClientStatus::Lobby
        ) {
            $client->send(Event::Declined, ['code' => 'unavailable', 'reason' => 'This player is no longer available.']);

            return;
        }
        $this->invitations->invite($client->id, $target->id, $this->matches->now());
        $target->send(Event::Invite, ['from' => ['id' => $client->id, 'name' => $client->name, 'kind' => $client->kind]]);
        $client->send(Event::Waiting, ['name' => $target->name]);
    }
}
