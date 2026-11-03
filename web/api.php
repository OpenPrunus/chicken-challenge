<?php

// API du classement (le code est dans src/Api, namespace Chicken\Api).
//   GET                      -> top 20 contre l'IA
//   GET  ?board=multi        -> top 20 multijoueur (écrit par le serveur de jeu)
//   POST {action:"start", token, level}      -> ticket de partie signé (à renvoyer avec le score)
//   POST {ticket, name, me, opp}             -> enregistre une partie contre l'IA

declare(strict_types=1);

use Chicken\Api\ApiConfig;
use Chicken\Api\ApiController;
use Chicken\Api\Request;
use Chicken\Support\SystemClock;

ini_set('display_errors', '0');          // jamais d'avertissement PHP (ni de chemin interne) dans la réponse
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

foreach ([(string) getenv('CHICKEN_AUTOLOAD'), __DIR__ . '/../vendor/autoload.php', __DIR__ . '/vendor/autoload.php'] as $autoload) {
    if ($autoload !== '' && is_file($autoload)) {
        require $autoload;
        break;
    }
}

(new ApiController(__DIR__ . '/data', ApiConfig::fromEnvironment(), new SystemClock()))
    ->handle(Request::fromGlobals())
    ->send();
