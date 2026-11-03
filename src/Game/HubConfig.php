<?php

declare(strict_types=1);

namespace Chicken\Game;

use Chicken\Support\CheckMode;
use Arcade\Support\Env;

/** Réglages du serveur de jeu (surchargeables par l'environnement : tests d'intégration, ajustements en production). */
final readonly class HubConfig
{
    public function __construct(
        public float $inviteTtl = 20,                 // avant expiration d'un défi
        public float $flightTimeout = 10,             // sans verdict du défenseur, le lanceur marque
        public float $serveTimeout = 30,              // un joueur qui ne lance pas perd le point
        public CheckMode $catchMode = CheckMode::Enforce,
        public float $catchTimeSlack = 1.0,           // tolérance de temps (latence aller-retour), en secondes
        public CheckMode $throwMode = CheckMode::Enforce,
        public float $rankMinPointSeconds = 1.5,      // durée minimale par point d'un match classé ; 0 désactive le contrôle
        public int $pairWindow = 86400,               // fenêtre de l'anti-collusion, en secondes
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            inviteTtl: Env::number('CHICKEN_INVITE_TTL', 20),
            flightTimeout: Env::number('CHICKEN_FLIGHT_TIMEOUT', 10),
            serveTimeout: Env::number('CHICKEN_SERVE_TIMEOUT', 30),
            catchMode: CheckMode::fromEnv('CHICKEN_CATCH_CHECK'),
            catchTimeSlack: Env::number('CHICKEN_CATCH_SLACK', 1.0),
            throwMode: CheckMode::fromEnv('CHICKEN_THROW_CHECK'),
            rankMinPointSeconds: Env::number('CHICKEN_RANK_MIN_POINT_SECONDS', 1.5, true),
            pairWindow: (int) Env::number('CHICKEN_PAIR_WINDOW', 86400),
        );
    }
}
