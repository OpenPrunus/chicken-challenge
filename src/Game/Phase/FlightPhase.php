<?php

declare(strict_types=1);

namespace Chicken\Game\Phase;

use Chicken\Game\Client;
use Chicken\Game\Event;
use Chicken\Game\GameMatch;
use Chicken\Game\MatchService;

/** La poule vole : le défenseur annonce « attrapée » (arbitrée par le serveur) ou « ratée ». */
final class FlightPhase extends MatchPhase
{
    /** Délai minimal entre le lancer et une capture : en dessous, c'est physiquement impossible. */
    private const float MIN_CATCH_DELAY = 0.15;

    #[\Override]
    public function onCatch(MatchService $service, GameMatch $match, Client $client, array $msg): void
    {
        $now = $service->now();
        $elapsed = $now - $match->since;
        if ($match->thrower === $client->id || $elapsed < self::MIN_CATCH_DELAY) {
            return;
        }
        $mode = $service->config->catchMode;
        if (
            $mode->isActive()
            && $match->launch !== null
            && !$service->catchArbiter->isPlausible($match->launch, $elapsed, $msg['x'] ?? null, $msg['y'] ?? null)
        ) {
            $service->logger->warning('{verdict} capture {name} : impossible ({elapsed} s après le lancer, main {hand})', [
                'verdict' => $mode->verdict(), 'name' => $client->name, 'elapsed' => sprintf('%.2f', $elapsed),
                'hand' => json_encode([$msg['x'] ?? null, $msg['y'] ?? null]),
            ]);
            if ($mode->blocks()) {
                $service->goal($match, (int) $match->thrower, corrected: true);        // un vrai raté : le lanceur marque
                return;
            }
        }
        $match->completeCatch($client->id, $now);
        foreach ($match->players() as $id) {
            $service->send($id, Event::Result, $match->resultFor($id, 'catch', $client->id, false));
        }
    }

    #[\Override]
    public function onMiss(MatchService $service, GameMatch $match, Client $client): void
    {
        if ($match->thrower !== $client->id) {
            $service->goal($match, (int) $match->thrower);
        }
    }

    #[\Override]
    public function onTick(MatchService $service, GameMatch $match, float $now): void
    {
        if ($now - $match->since > $service->config->flightTimeout) {
            $service->goal($match, (int) $match->thrower);                  // sans verdict du défenseur, le lanceur marque
        }
    }
}
