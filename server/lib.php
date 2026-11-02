<?php
/**
 * Fonctions pures du serveur multijoueur (sans état global ni E/S) : testées par PHPUnit (tests/php/).
 */
declare(strict_types=1);

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

/** Lit un nombre positif dans l'environnement, sinon retourne la valeur par défaut. */
function envNum(string $name, float $default): float {
    $v = getenv($name);
    return ($v !== false && is_numeric($v) && (float)$v > 0) ? (float)$v : $default;
}

/** Valeur du Sec-WebSocket-Accept pour une clé client (RFC 6455, §4.2.2). */
function wsAcceptKey(string $clientKey): string {
    return base64_encode(sha1($clientKey . WS_GUID, true));
}

function encodeFrame(string $payload, int $opcode = 0x1): string {
    $len = strlen($payload);
    $h = chr(0x80 | $opcode);
    if ($len < 126) $h .= chr($len);
    elseif ($len < 65536) $h .= chr(126) . pack('n', $len);
    else $h .= chr(127) . pack('J', $len);
    return $h . $payload;
}

function cleanName(string $n): string {
    $n = preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $n) ?? '';
    return trim(mb_substr($n, 0, 15));
}

// ------------------------------------------------------------------ matchs
function matchOver(int $a, int $b): bool {
    return $a === 12 || $b === 12 || (max($a, $b) >= 4 && abs($a - $b) >= 2);
}

function otherOf(array $m, int $id): int { return $m['a'] === $id ? $m['b'] : $m['a']; }

function resultFor(array $m, int $id, string $kind, int $scorerOrCatcher, bool $over): array {
    $o = otherOf($m, $id);
    return [
        't' => 'result', 'kind' => $kind, 'rally' => $m['rally'],
        'role' => $m['thrower'] === $id ? 'thrower' : 'defender',
        'by' => $scorerOrCatcher === $id ? 'you' : 'opp',
        'you' => $m['score'][$id], 'opp' => $m['score'][$o],
        'next' => $m['turn'] === $id ? 'you' : 'opp', 'over' => $over,
    ];
}

function num(mixed $v, float $min, float $max): ?float {
    if (!is_int($v) && !is_float($v)) return null;
    $f = (float)$v;
    return is_finite($f) ? max($min, min($max, $f)) : null;
}

/**
 * Décode une trame WebSocket client (RFC 6455) au début de $buf.
 * Retourne ['fin', 'op', 'payload', 'consumed'], null si la trame est incomplète,
 * ou false si elle est non conforme (non masquée, trop grande).
 *
 * @return array{fin: bool, op: int, payload: string, consumed: int}|null|false
 */
function decodeFrame(string $buf, int $maxPayload = 8192): array|null|false {
    $n = strlen($buf);
    if ($n < 2) return null;
    $b0 = ord($buf[0]); $b1 = ord($buf[1]);
    $fin = ($b0 & 0x80) !== 0;
    $op = $b0 & 0x0F;
    $masked = ($b1 & 0x80) !== 0;
    $len = $b1 & 0x7F;
    $pos = 2;
    if ($len === 126) {
        if ($n < 4) return null;
        $len = unpack('n', substr($buf, 2, 2))[1]; $pos = 4;
    } elseif ($len === 127) {
        if ($n < 10) return null;
        $len = unpack('J', substr($buf, 2, 8))[1]; $pos = 10;
    }
    if (!$masked || $len > $maxPayload || $len < 0) return false;
    if ($n < $pos + 4 + $len) return null;
    $mask = substr($buf, $pos, 4);
    $payload = substr($buf, $pos + 4, $len);
    if ($len > 0) $payload = $payload ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
    return ['fin' => $fin, 'op' => $op, 'payload' => $payload, 'consumed' => $pos + 4 + $len];
}

// ------------------------------------------------------------------------------------------------------------
// Classement multijoueur (Elo). Fonctions pures : le serveur de jeu les appelle puis persiste le résultat.
// Un joueur « classé » présente un jeton secret (généré par son navigateur) : seul son hash SHA-256 est
// conservé. Le premier joueur à terminer un match classé sous un pseudo le réserve ; les autres jetons ne
// peuvent plus l'utiliser, ce qui empêche d'usurper un pseudo du classement.
// ------------------------------------------------------------------------------------------------------------

const RATING_START = 1000;
const RATING_FLOOR = 100;

/** Clé d'un pseudo dans le classement (insensible à la casse). */
function nameKey(string $name): string { return mb_strtolower($name); }

/** Un jeton valide : 32 à 64 caractères hexadécimaux. */
function tokenValid(mixed $token): bool {
    return is_string($token) && preg_match('/^[a-f0-9]{32,64}$/', $token) === 1;
}

function tokenHash(string $token): string { return hash('sha256', $token); }

/** Probabilité que A batte B (formule d'Elo). */
function ratingExpected(float $ra, float $rb): float {
    return 1.0 / (1.0 + 10 ** (($rb - $ra) / 400.0));
}

/** Facteur K : les nouveaux joueurs bougent plus vite pour trouver leur niveau. */
function ratingK(int $played): int { return $played < 10 ? 40 : 24; }

function newPlayerEntry(string $name, string $hash): array {
    return ['name' => $name, 'th' => $hash, 'rating' => RATING_START, 'played' => 0, 'wins' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0];
}

/** Ce pseudo est-il réservé par un autre jeton que $hash (chaîne vide = invité sans jeton) ? */
function nameReservedByOther(array $players, string $name, string $hash): bool {
    $e = $players[nameKey($name)] ?? null;
    return $e !== null && ($e['th'] ?? '') !== '' && ($e['th'] ?? '') !== $hash;
}

/**
 * Enregistre un match classé. $winner / $loser : [pseudo, hash du jeton]. $gfW / $gfL : points marqués.
 * Retourne ['players' => nouvelle table, 'winner' => [rating, delta, rank], 'loser' => [...]],
 * ou null si le match ne peut pas être classé (jeton manquant, même jeton des deux côtés, pseudo réservé par un autre).
 *
 * @param array<string, array<string, mixed>> $players
 */
function recordRankedMatch(array $players, array $winner, array $loser, int $gfW, int $gfL): ?array {
    [$nw, $hw] = $winner; [$nl, $hl] = $loser;
    if ($hw === '' || $hl === '' || $hw === $hl) return null;
    if (nameReservedByOther($players, $nw, $hw) || nameReservedByOther($players, $nl, $hl)) return null;

    $kw = nameKey($nw); $kl = nameKey($nl);
    $w = $players[$kw] ?? newPlayerEntry($nw, $hw);
    $l = $players[$kl] ?? newPlayerEntry($nl, $hl);
    $w['th'] = $hw; $l['th'] = $hl;                               // réserve les pseudos au premier match classé

    $expectedW = ratingExpected((float)$w['rating'], (float)$l['rating']);
    $newW = max(RATING_FLOOR, (int)round($w['rating'] + ratingK((int)$w['played']) * (1 - $expectedW)));
    $newL = max(RATING_FLOOR, (int)round($l['rating'] - ratingK((int)$l['played']) * (1 - $expectedW)));
    $deltaW = $newW - (int)$w['rating']; $deltaL = $newL - (int)$l['rating'];

    $w['rating'] = $newW; $w['played']++; $w['wins']++; $w['gf'] += $gfW; $w['ga'] += $gfL; $w['name'] = $nw;
    $l['rating'] = $newL; $l['played']++; $l['losses']++; $l['gf'] += $gfL; $l['ga'] += $gfW; $l['name'] = $nl;
    $players[$kw] = $w; $players[$kl] = $l;

    return [
        'players' => $players,
        'winner' => ['rating' => $newW, 'delta' => $deltaW, 'rank' => rankOf($players, $kw)],
        'loser'  => ['rating' => $newL, 'delta' => $deltaL, 'rank' => rankOf($players, $kl)],
    ];
}

/** Rang d'un joueur (1 = premier) : 1 + nombre de joueurs strictement mieux classés. Les ex æquo partagent le rang. */
function rankOf(array $players, string $key): int {
    $mine = $players[$key] ?? null;
    if ($mine === null) return count($players) + 1;
    $mr = (int)($mine['rating'] ?? 0); $mw = (int)($mine['wins'] ?? 0);
    $rank = 1;
    foreach ($players as $p) {                                     // tolère un fichier abîmé : une ligne sans note vaut 0
        $pr = (int)($p['rating'] ?? 0);
        if ($pr > $mr || ($pr === $mr && (int)($p['wins'] ?? 0) > $mw)) $rank++;
    }
    return $rank;
}

/** Charge la table des joueurs ; vide si le fichier est absent ou illisible. */
function loadPlayers(string $file): array {
    $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return (is_array($d) && is_array($d['players'] ?? null)) ? $d['players'] : [];
}

/** Écrit la table de façon atomique (fichier temporaire puis rename) : un lecteur ne voit jamais un fichier tronqué. */
function savePlayers(string $file, array $players): bool {
    $tmp = $file . '.tmp';
    $json = json_encode(['version' => 1, 'players' => (object)$players], JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}
