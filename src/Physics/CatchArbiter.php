<?php

declare(strict_types=1);

namespace Chicken\Physics;

use Chicken\Support\Num;

/**
 * Arbitre des captures : le défenseur annonce « attrapée » avec la position de sa main ; le serveur rejoue la trajectoire
 * au lieu de le croire sur parole.
 */
final readonly class CatchArbiter
{
    final public const float STEP = 1 / 120;
    final public const int MAX_STEPS = 6000;                  // 50 s au plus : un vol dure moins de 3 s
    final public const float MAX_BOOST = 2.0;                 // agrandissement maximal de la poule sur un petit écran

    public function __construct(
        private float $timeSlack = 1.0,
        private float $reachSlack = 1.35,
        private float $forward = 0.15,
    ) {
    }

    /**
     * Une capture déclarée est-elle plausible ? On cherche, dans la fenêtre [écoulé - tolérance, écoulé + marge], un
     * instant où la main annoncée rattrape la poule. Le temps écoulé est mesuré par le serveur depuis le lancer : il
     * dépasse le temps vu par le défenseur de la latence aller-retour (d'où la fenêtre).
     * Rejette : pas de coordonnées, main loin de la poule, capture trop tôt (poule encore loin) ou trop tard.
     */
    public function isPlausible(Launch $launch, float $elapsed, mixed $mx, mixed $my): bool
    {
        $mx = Num::finite($mx);
        $my = Num::finite($my);
        if ($mx === null || $my === null || abs($mx) > 1.5 || abs($my) > 1.5) {
            return false;
        }
        $hen = Hen::thrown($launch);
        $t = 0.0;
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            // marge vers l'avant : sur une trajectoire très chaotique, la simulation du serveur peut être en retard sur celle du client
            if ($t > $elapsed + $this->forward) {
                break;
            }
            if ($t >= $elapsed - $this->timeSlack && $hen->seenByDefender()->canCatch($mx, $my, $this->reachSlack, self::MAX_BOOST)) {
                return true;
            }
            if ($hen->hasArrived) {
                break;
            }
            $hen->step(self::STEP);
            $t += self::STEP;
        }

        return false;
    }
}
