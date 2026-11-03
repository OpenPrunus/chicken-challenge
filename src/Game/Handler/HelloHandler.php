<?php

declare(strict_types=1);

namespace Chicken\Game\Handler;

use Chicken\Game\Client;
use Chicken\Game\ClientStatus;
use Chicken\Game\Event;
use Chicken\Game\Lobby;
use Chicken\Game\RankingBook;
use Chicken\Ranking\Token;
use Arcade\Support\Nickname;
use Psr\Log\LoggerInterface;

/** Identification : pseudo (rendu unique) et jeton secret du navigateur, qui rend les parties « classées ». */
final readonly class HelloHandler implements MessageHandler
{
    public function __construct(private Lobby $lobby, private RankingBook $book, private LoggerInterface $logger)
    {
    }

    #[\Override]
    public function handle(Client $client, array $msg): void
    {
        if ($client->isNamed) {
            return;
        }
        $name = Nickname::clean((string) ($msg['name'] ?? ''));
        if ($name === '') {
            $client->send(Event::Error, ['code' => 'invalid_name', 'msg' => 'Invalid nickname.']);

            return;
        }
        // sans jeton valide, le joueur est « invité » (parties non classées)
        $hash = Token::isValid($msg['token'] ?? null) ? Token::hash($msg['token']) : '';
        // un pseudo est refusé s'il est déjà en ligne, ou réservé dans le classement par un autre jeton
        $base = $name;
        for ($i = 2; $this->lobby->isNameOnline($name) || $this->book->ranking->isReservedByOther($name, $hash); $i++) {
            $name = mb_substr($base, 0, 13) . $i;
        }
        $client->name = $name;
        $client->tokenHash = $hash;
        $client->kind = max(0, min(3, (int) ($msg['kind'] ?? 0)));
        $client->status = ClientStatus::Lobby;
        $client->send(Event::Welcome, ['id' => $client->id, 'name' => $name, 'ranked' => $hash !== '']);
        $this->logger->info('+ {name} (#{id})', ['name' => $name, 'id' => $client->id]);
        $this->lobby->broadcast();
    }
}
