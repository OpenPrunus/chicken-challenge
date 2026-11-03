<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;

/** Une commande du protocole du salon (pattern Command) : un handler par type de message client. */
interface MessageHandler
{
    /** @param array<string, mixed> $msg */
    public function handle(Client $client, array $msg): void;
}
