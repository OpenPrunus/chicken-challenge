<?php

declare(strict_types=1);

namespace Chicken\Api;

use Chicken\Support\Env;

/** Réglages de l'API (surchargeables par l'environnement : tests, ajustements). */
final readonly class ApiConfig
{
    public function __construct(
        public int $rateWindow = 3600,
        public int $ticketsPerWindow = 40,            // tickets par fenêtre, par IP et par jeton
        public int $recordsPerWindow = 8,             // parties enregistrées par fenêtre, par IP et par jeton
        public float $secondsPerPoint = 2.0,          // durée minimale par point marqué
        public string $secret = '',                   // CHICKEN_SECRET (16 caractères au moins), sinon data/secret.key
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            rateWindow: (int) Env::number('CHICKEN_RATE_WINDOW', 3600, true),
            ticketsPerWindow: (int) Env::number('CHICKEN_RATE_TICKETS', 40, true),
            recordsPerWindow: (int) Env::number('CHICKEN_RATE_RECORDS', 8, true),
            secondsPerPoint: Env::number('CHICKEN_MIN_POINT_SECONDS', 2.0, true),
            secret: Env::string('CHICKEN_SECRET'),
        );
    }

    /** Durée minimale plausible d'une partie : chaque point demande un vol de poule et l'animation du but. */
    public function minimumSeconds(int $me, int $opp): float
    {
        return $this->secondsPerPoint * ($me + $opp);
    }
}
