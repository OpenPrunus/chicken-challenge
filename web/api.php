<?php
// API du classement.
//   GET                      -> top 20 contre l'IA
//   GET  ?board=multi        -> top 20 multijoueur (écrit par le serveur de jeu)
//   POST {action:"start", token, level}      -> ticket de partie signé (à renvoyer avec le score)
//   POST {ticket, name, me, opp}             -> enregistre une partie contre l'IA
// Défenses contre les faux scores : voir web/lib/scores.php (ticket signé à usage unique, durée minimale,
// limite de débit par IP et par jeton, pseudos réservés).
require __DIR__ . '/lib/scores.php';

ini_set('display_errors', '0');          // jamais d'avertissement PHP (ni de chemin interne) dans la réponse
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__ . '/data';
$file = "$dir/scores.json";

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['board'] ?? '') === 'multi') {          // classement multijoueur, écrit par le serveur de jeu
        echo json_encode(chicken_rank_multi(chicken_load_multi("$dir/multi_scores.json")), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(chicken_rank(chicken_load($file)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'method']);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) reply(400, ['error' => 'bad json']);

// ---- réglages (surchargeables par l'environnement : tests, ajustements) ----------------------------------
$now = time();
$window = (int)chicken_env_num('CHICKEN_RATE_WINDOW', 3600);
$limitTickets = (int)chicken_env_num('CHICKEN_RATE_TICKETS', 40);      // tickets par heure, par IP et par jeton
$limitRecords = (int)chicken_env_num('CHICKEN_RATE_RECORDS', 8);       // parties enregistrées par heure, par IP et par jeton
$perPoint = chicken_env_num('CHICKEN_MIN_POINT_SECONDS', 2.0);         // durée minimale par point marqué
$ip = chicken_client_ip($_SERVER);

// Tout ce qui touche à l'état anti-abus (secret, compteurs, tickets utilisés) est sérialisé par un verrou unique.
$lock = @fopen("$dir/api.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX)) reply(500, ['error' => 'storage']);

$secretEnv = (string)getenv('CHICKEN_SECRET');
if (strlen($secretEnv) >= 16) $secret = $secretEnv;
else {
    $secretFile = "$dir/secret.key";
    $secret = is_file($secretFile) ? trim((string)file_get_contents($secretFile)) : '';
    if (strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        if (@file_put_contents($secretFile, $secret, LOCK_EX) === false) reply(500, ['error' => 'storage']);
        @chmod($secretFile, 0600);
    }
}

$abuseFile = "$dir/abuse.json";
$abuse = is_file($abuseFile) ? json_decode((string)file_get_contents($abuseFile), true) : null;
$abuse = is_array($abuse) ? $abuse : [];
$abuse += ['rate' => [], 'used' => []];
$saveAbuse = function () use (&$abuse, $abuseFile): void {            // écriture atomique
    $tmp = $abuseFile . '.tmp';
    if (@file_put_contents($tmp, json_encode($abuse), LOCK_EX) === false || !@rename($tmp, $abuseFile)) reply(500, ['error' => 'storage']);
};
$rate = function (string $kind, string $tokenHash, int $limit) use (&$abuse, $now, $window): void {
    foreach (["$kind:ip:" . chicken_client_ip($_SERVER), "$kind:tok:$tokenHash"] as $key) {
        [$ok, $state, $retry] = chicken_rate_check($abuse['rate'], $key, $now, $limit, $window);
        $abuse['rate'] = $state;
        if (!$ok) reply(429, ['error' => 'rate_limited', 'retry_after' => $retry]);
    }
};

// ---- 1) début de partie : on délivre un ticket ----------------------------------------------------------------
if (($in['action'] ?? '') === 'start') {
    if (!chicken_token_valid($in['token'] ?? null)) reply(400, ['error' => 'token']);
    $level = (int)($in['level'] ?? 1);
    if ($level < 0 || $level > 2) reply(400, ['error' => 'level']);
    $th = chicken_token_hash($in['token']);
    $rate('tickets', $th, $limitTickets);
    $saveAbuse();
    reply(200, ['ticket' => chicken_ticket_make($secret, $th, $level, $now)]);
}

// ---- 2) fin de partie : enregistrement du score ---------------------------------------------------------------
$payload = chicken_ticket_verify((string)($in['ticket'] ?? ''), $secret, $now);
if ($payload === null) reply(403, ['error' => 'ticket']);
$th = $payload['h'];
if (isset($abuse['used'][$payload['n']])) reply(403, ['error' => 'ticket']);            // déjà utilisé

$name = chicken_clean_name((string)($in['name'] ?? ''));
$me   = (int)($in['me'] ?? -1);
$opp  = (int)($in['opp'] ?? -1);
if (!chicken_valid_score($name, $me, $opp)) reply(422, ['error' => 'invalid']);
if ($now - $payload['t'] < chicken_min_seconds($me, $opp, $perPoint)) reply(422, ['error' => 'too_fast']);

$rate('records', $th, $limitRecords);

$fp = @fopen($file, 'c+');
if ($fp === false || !flock($fp, LOCK_EX)) { $saveAbuse(); reply(500, ['error' => 'storage']); }
$rows = json_decode((string)stream_get_contents($fp), true);
$rows = is_array($rows) ? $rows : [];
if (chicken_name_taken($rows, $name, $th)) { flock($fp, LOCK_UN); fclose($fp); $saveAbuse(); reply(403, ['error' => 'name_taken']); }
$rows = chicken_apply_score($rows, $name, $me, $opp, $th);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($rows, JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

$abuse['used'][$payload['n']] = $now;                                                   // le ticket ne resservira plus
foreach ($abuse['used'] as $n => $t) if ($t < $now - CHICKEN_TICKET_MAX_AGE - 60) unset($abuse['used'][$n]);
$saveAbuse();
reply(200, ['ok' => true]);
