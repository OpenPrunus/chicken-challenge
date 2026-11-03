<?php

declare(strict_types=1);

namespace Chicken\Game\Phase;

use Chicken\Game\Client;
use Chicken\Game\Event;
use Chicken\Game\GameMatch;
use Chicken\Game\MatchService;
use Chicken\Physics\Launch;

/** Un joueur doit lancer. Le serveur n'accepte que les lancers que l'interface du jeu peut produire. */
final class ServePhase extends MatchPhase
{
    #[\Override]
    public function onThrow(MatchService $service, GameMatch $match, Client $client, array $msg): void
    {
        if ($match->turn !== $client->id) {
            return;
        }
        $launch = Launch::fromWire($msg['hen'] ?? null);
        if ($launch === null) {
            return;
        }
        $mode = $service->config->throwMode;
        if ($mode->isActive() && !$service->throwValidator->isLegit($launch)) {      // plus rapide, plus fort ou plus tordu que ce que le jeu permet
            $service->logger->warning('{verdict} lancer {name} : {hen}', [
                'verdict' => $mode->verdict(), 'name' => $client->name, 'hen' => json_encode($launch->toArray()),
            ]);
            if ($mode->blocks()) {
                return;
            }
        }
        $match->beginFlight($client->id, $launch, $service->now());
        $service->send($match->other($client->id), Event::Throw, ['hen' => $launch->toArray(), 'rally' => $match->rally]);
    }

    #[\Override]
    public function onTick(MatchService $service, GameMatch $match, float $now): void
    {
        if ($now - $match->since > $service->config->serveTimeout) {
            $service->goal($match, $match->other($match->turn));            // qui ne lance pas perd le point
        }
    }
}
