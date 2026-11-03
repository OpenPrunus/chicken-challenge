<?php

/**
 * Serveur multijoueur de Chicken Challenge : WebSocket (RFC 6455) en PHP pur.
 *
 * - Salon d'attente : liste des joueurs connectés, défis par clic sur un pseudo, matchmaking automatique.
 * - Match : le serveur tient les scores et le tour de rôle, arbitre les captures et les lancers.
 *
 * Variables d'environnement : WS_PORT (8081), WS_ALLOWED_ORIGINS (liste séparée par des virgules, vide = tout),
 * CHICKEN_DATA_DIR (dossier du classement). Le code est dans src/ (namespace Chicken\).
 */

declare(strict_types=1);

use Arcade\Network\TrustedProxies;
use Arcade\Server\SocketServer;
use Arcade\Support\Env;
use Arcade\Support\StdoutLogger;
use Arcade\WebSocket\Handshake;
use Chicken\Game\GameHub;
use Chicken\Game\HubConfig;
use Chicken\Ranking\RankingStore;

set_time_limit(0);
require __DIR__ . '/bootstrap.php';

$port = (int) (Env::string('WS_PORT', '8081'));
$origins = array_values(array_filter(array_map('trim', explode(',', Env::string('WS_ALLOWED_ORIGINS')))));
$dataDir = rtrim(Env::string('CHICKEN_DATA_DIR', dirname(__DIR__) . '/web/data'), '/');
$store = new RankingStore($dataDir . '/multi_scores.json');

$hub = GameHub::create(HubConfig::fromEnvironment(), $store, new StdoutLogger());
try {
    $proxies = TrustedProxies::fromString(Env::string('TRUSTED_PROXIES'));
    $perIp = (int) Env::number('CHICKEN_MAX_CONN_PER_IP', 10, true);
    $server = SocketServer::listen($port, $hub, new Handshake($origins), 0.2, 500, $proxies, $perIp);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'Ranking: ' . count($store->load()->ranking) . " player(s) loaded from {$store->file}"
    . (is_writable($dataDir) ? '' : ' (WARNING: directory not writable, results will not be saved)') . "\n");
fwrite(STDOUT, "Chicken WS listening on :$port\n");            // dernier message de démarrage : le serveur est prêt

$server->run();
