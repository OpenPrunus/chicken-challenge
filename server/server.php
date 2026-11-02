<?php
/**
 * Serveur multijoueur de Chicken Challenge : WebSocket (RFC 6455) en PHP pur, sans dépendance.
 *
 * - Salon d'attente : liste des joueurs connectés, défis par clic sur un pseudo, matchmaking automatique.
 * - Match : le serveur tient les scores et le tour de rôle ; les trajectoires sont déterministes, donc
 *   le lanceur n'envoie que les paramètres du lancer. Le défenseur (détection des collisions en local,
 *   comme dans l'original) annonce "catch" ou "miss". Les coordonnées sont inversées côté client.
 *
 * Variables d'environnement : WS_PORT (8081), WS_ALLOWED_ORIGINS (liste séparée par des virgules, vide = tout).
 */
declare(strict_types=1);
set_time_limit(0);

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const MAX_CLIENTS = 500;
const INVITE_TTL = 20;       // s avant expiration d'un défi
const FLIGHT_TIMEOUT = 10;   // s : sans verdict du défenseur, le lanceur marque
const SERVE_TIMEOUT = 30;    // s : un joueur qui ne lance pas perd le point
const IDLE_TIMEOUT = 90;     // s sans aucun message : déconnexion

$port = (int)(getenv('WS_PORT') ?: 8081);
$allowed = array_values(array_filter(array_map('trim', explode(',', (string)getenv('WS_ALLOWED_ORIGINS')))));

$srv = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "Impossible d'écouter sur le port $port : $errstr\n"); exit(1); }
stream_set_blocking($srv, false);
fwrite(STDOUT, "Chicken WS en écoute sur :$port\n");

$clients = [];   // id => état client
$byRes = [];     // (int)resource => id
$matches = [];   // id de match => état
$pending = [];   // cible => [challenger => timestamp]
$nextId = 1;
$nextMatch = 1;

// ------------------------------------------------------------------ utilitaires
function logm(string $s): void { fwrite(STDOUT, '[' . date('H:i:s') . "] $s\n"); }

function encodeFrame(string $payload, int $opcode = 0x1): string {
    $len = strlen($payload);
    $h = chr(0x80 | $opcode);
    if ($len < 126) $h .= chr($len);
    elseif ($len < 65536) $h .= chr(126) . pack('n', $len);
    else $h .= chr(127) . pack('J', $len);
    return $h . $payload;
}

function send(int $id, array $msg): void {
    global $clients;
    if (!isset($clients[$id]) || !$clients[$id]['ws']) return;
    $clients[$id]['out'] .= encodeFrame(json_encode($msg, JSON_UNESCAPED_UNICODE));
    flushOut($id);
}

function flushOut(int $id): void {
    global $clients;
    $c = &$clients[$id];
    if ($c['out'] === '') return;
    $n = @fwrite($c['sock'], $c['out']);
    if ($n === false) { $c['dead'] = true; return; }
    $c['out'] = (string)substr($c['out'], $n);
}

function dropClient(int $id, bool $sendClose = false): void {
    global $clients, $byRes, $pending;
    if (!isset($clients[$id])) return;
    $c = $clients[$id];
    if ($sendClose && $c['ws']) @fwrite($c['sock'], encodeFrame('', 0x8));
    // sortie d'un éventuel match
    if ($c['match'] !== null) leaveMatch($id);
    unset($pending[$id]);
    foreach ($pending as $to => $list) {
        if (isset($list[$id])) { unset($pending[$to][$id]); send($to, ['t' => 'invite_cancel', 'from' => $id]); }
    }
    unset($byRes[(int)$c['sock']]);
    @fclose($c['sock']);
    unset($clients[$id]);
    if ($c['name'] !== null) { logm("- {$c['name']} (#$id) parti"); broadcastLobby(); }
}

function cleanName(string $n): string {
    $n = preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $n) ?? '';
    return trim(mb_substr($n, 0, 15));
}

function broadcastLobby(): void {
    global $clients;
    $list = [];
    foreach ($clients as $c) {
        if ($c['name'] !== null) $list[] = ['id' => $c['id'], 'name' => $c['name'], 'kind' => $c['kind'], 'status' => $c['status']];
    }
    foreach ($clients as $c) {
        if ($c['name'] !== null && in_array($c['status'], ['lobby', 'queue'], true)) send($c['id'], ['t' => 'lobby', 'players' => $list]);
    }
}

// ------------------------------------------------------------------ matchs
function matchOver(int $a, int $b): bool {
    return $a === 12 || $b === 12 || (max($a, $b) >= 4 && abs($a - $b) >= 2);
}

function startMatch(int $a, int $b, ?int $first = null): void {
    global $clients, $matches, $pending, $nextMatch;
    $mid = $nextMatch++;
    $first ??= random_int(0, 1) ? $a : $b;
    $matches[$mid] = [
        'a' => $a, 'b' => $b, 'score' => [$a => 0, $b => 0],
        'turn' => $first, 'state' => 'serve', 't' => microtime(true),
        'thrower' => null, 'rally' => 0, 'rematch' => [],
    ];
    foreach ([$a, $b] as $id) {
        $clients[$id]['match'] = $mid;
        $clients[$id]['status'] = 'match';
        unset($pending[$id]);
    }
    foreach ($pending as $to => $list) { unset($pending[$to][$a], $pending[$to][$b]); }
    foreach ([[$a, $b], [$b, $a]] as [$me, $opp]) {
        send($me, ['t' => 'match', 'opp' => ['name' => $clients[$opp]['name'], 'kind' => $clients[$opp]['kind']], 'serve' => $first === $me]);
    }
    logm("Match #$mid : {$clients[$a]['name']} vs {$clients[$b]['name']}");
    broadcastLobby();
}

function otherOf(array $m, int $id): int { return $m['a'] === $id ? $m['b'] : $m['a']; }

function leaveMatch(int $id): void {
    global $clients, $matches;
    $mid = $clients[$id]['match'] ?? null;
    if ($mid === null) return;
    $m = $matches[$mid] ?? null;
    $clients[$id]['match'] = null;
    if (isset($clients[$id]) && $clients[$id]['status'] === 'match') $clients[$id]['status'] = 'lobby';
    if ($m) {
        $o = otherOf($m, $id);
        if (isset($clients[$o])) { $clients[$o]['match'] = null; $clients[$o]['status'] = 'lobby'; send($o, ['t' => 'oppleft']); }
        unset($matches[$mid]);
    }
    broadcastLobby();
}

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

function doGoal(int $mid, int $scorer): void {
    global $matches;
    $m = &$matches[$mid];
    $m['score'][$scorer]++;
    $m['turn'] = $scorer;                       // le marqueur récupère la poule et relance
    $over = matchOver($m['score'][$m['a']], $m['score'][$m['b']]);
    $m['state'] = $over ? 'over' : 'serve';
    $m['t'] = microtime(true) + 2.5;            // délai pour l'animation du but
    foreach ([$m['a'], $m['b']] as $id) send($id, resultFor($m, $id, 'goal', $scorer, $over));
    if ($over) logm("Match #$mid terminé : {$m['score'][$m['a']]}-{$m['score'][$m['b']]}");
}

function num(mixed $v, float $min, float $max): ?float {
    if (!is_int($v) && !is_float($v)) return null;
    $f = (float)$v;
    return is_finite($f) ? max($min, min($max, $f)) : null;
}

function handleMatchMsg(int $id, string $t, array $msg): void {
    global $clients, $matches;
    $mid = $clients[$id]['match'] ?? null;
    if ($mid === null || !isset($matches[$mid])) return;
    $m = &$matches[$mid];
    $o = otherOf($m, $id);
    $now = microtime(true);

    switch ($t) {
        case 'throw':
            if ($m['state'] !== 'serve' || $m['turn'] !== $id) return;
            $h = $msg['hen'] ?? null;
            if (!is_array($h)) return;
            $hen = [
                'x' => num($h['x'] ?? null, -1, 1), 'y' => num($h['y'] ?? null, -1, 1),
                'vx' => num($h['vx'] ?? null, -60, 60), 'vy' => num($h['vy'] ?? null, -60, 60),
                'spin' => num($h['spin'] ?? null, -2.6, 2.6), 'speed' => num($h['speed'] ?? null, 0.3, 1.9),
            ];
            if (in_array(null, $hen, true)) return;
            $m['state'] = 'flight'; $m['thrower'] = $id; $m['t'] = $now; $m['rally']++;
            send($o, ['t' => 'throw', 'hen' => $hen, 'rally' => $m['rally']]);
            break;

        case 'catch':
            if ($m['state'] !== 'flight' || $m['thrower'] === $id || $now - $m['t'] < 0.15) return;
            $m['turn'] = $id; $m['state'] = 'serve'; $m['t'] = $now;
            foreach ([$m['a'], $m['b']] as $p) send($p, resultFor($m, $p, 'catch', $id, false));
            break;

        case 'miss':
            if ($m['state'] !== 'flight' || $m['thrower'] === $id) return;
            doGoal($mid, $m['thrower']);
            break;

        case 'pos':
            $x = num($msg['x'] ?? null, -1, 1);
            if ($x !== null) send($o, ['t' => 'pos', 'x' => $x]);
            break;

        case 'rematch':
            if ($m['state'] !== 'over') return;
            $m['rematch'][$id] = true;
            if (count($m['rematch']) === 2) {
                $a = $m['a']; $b = $m['b']; $first = $m['turn'] === $a ? $b : $a;   // celui qui n'a pas marqué en dernier sert
                unset($matches[$mid]);
                startMatch($a, $b, $first);
            } else {
                send($o, ['t' => 'rematch_ask']);
            }
            break;
    }
}

function handleMsg(int $id, string $raw): void {
    global $clients, $pending;
    $c = &$clients[$id];
    $now = time();
    if ($c['rateT'] !== $now) { $c['rateT'] = $now; $c['rate'] = 0; }
    if (++$c['rate'] > 80) return;                    // anti-flood
    $c['last'] = $now;

    $msg = json_decode($raw, true);
    if (!is_array($msg)) return;
    $t = (string)($msg['t'] ?? '');

    if ($t === 'ping') { send($id, ['t' => 'pong']); return; }

    if ($t === 'hello') {
        if ($c['name'] !== null) return;
        $name = cleanName((string)($msg['name'] ?? ''));
        if ($name === '') { send($id, ['t' => 'error', 'msg' => 'Pseudo invalide.']); return; }
        $base = $name; $i = 2;
        $taken = fn(string $n) => count(array_filter($GLOBALS['clients'], fn($x) => $x['name'] !== null && mb_strtolower($x['name']) === mb_strtolower($n))) > 0;
        while ($taken($name)) { $name = mb_substr($base, 0, 13) . $i++; }
        $c['name'] = $name;
        $c['kind'] = max(0, min(3, (int)($msg['kind'] ?? 0)));
        $c['status'] = 'lobby';
        send($id, ['t' => 'welcome', 'id' => $id, 'name' => $name]);
        logm("+ $name (#$id)");
        broadcastLobby();
        return;
    }
    if ($c['name'] === null) return;

    switch ($t) {
        case 'challenge': {
            $to = (int)($msg['to'] ?? 0);
            if ($to === $id || !isset($clients[$to]) || $clients[$to]['status'] !== 'lobby' || $c['status'] !== 'lobby') {
                send($id, ['t' => 'declined', 'reason' => 'Ce joueur n\'est plus disponible.']); return;
            }
            $pending[$to][$id] = $now;
            send($to, ['t' => 'invite', 'from' => ['id' => $id, 'name' => $c['name'], 'kind' => $c['kind']]]);
            send($id, ['t' => 'waiting', 'name' => $clients[$to]['name']]);
            break;
        }
        case 'accept': {
            $from = (int)($msg['from'] ?? 0);
            if (!isset($pending[$id][$from]) || !isset($clients[$from]) || $clients[$from]['status'] !== 'lobby' || $c['status'] !== 'lobby') {
                send($id, ['t' => 'error', 'msg' => 'Ce défi n\'est plus valable.']); return;
            }
            unset($pending[$id][$from]);
            startMatch($from, $id);
            break;
        }
        case 'decline': {
            $from = (int)($msg['from'] ?? 0);
            if (isset($pending[$id][$from])) { unset($pending[$id][$from]); send($from, ['t' => 'declined', 'reason' => $c['name'] . ' a refusé le défi.']); }
            break;
        }
        case 'auto': {
            if ($c['status'] !== 'lobby') return;
            foreach ($clients as $o) {
                if ($o['id'] !== $id && $o['status'] === 'queue') { startMatch($o['id'], $id); return; }
            }
            $c['status'] = 'queue';
            send($id, ['t' => 'queued']);
            broadcastLobby();
            break;
        }
        case 'cancel':
            if ($c['status'] === 'queue') { $c['status'] = 'lobby'; send($id, ['t' => 'unqueued']); broadcastLobby(); }
            break;
        case 'leave':
            if ($c['match'] !== null) leaveMatch($id);
            send($id, ['t' => 'left']);
            break;
        default:
            if ($c['match'] !== null) handleMatchMsg($id, $t, $msg);
    }
}

function tick(): void {
    global $clients, $matches, $pending;
    $now = microtime(true);
    foreach ($matches as $mid => $m) {
        if ($m['state'] === 'flight' && $now - $m['t'] > FLIGHT_TIMEOUT) doGoal($mid, $m['thrower']);
        elseif ($m['state'] === 'serve' && $now - $m['t'] > SERVE_TIMEOUT) doGoal($mid, otherOf($m, $m['turn']));
    }
    foreach ($pending as $to => $list) {
        foreach ($list as $from => $ts) {
            if ($now - $ts > INVITE_TTL) {
                unset($pending[$to][$from]);
                send($from, ['t' => 'declined', 'reason' => 'Pas de réponse.']);
                send($to, ['t' => 'invite_cancel', 'from' => $from]);
            }
        }
    }
    foreach ($clients as $id => $c) {
        if (!empty($c['dead']) || time() - $c['last'] > IDLE_TIMEOUT) dropClient($id, true);
    }
}

// ------------------------------------------------------------------ boucle principale
while (true) {
    $read = [$srv]; $write = []; $except = null;
    foreach ($clients as $c) { $read[] = $c['sock']; if ($c['out'] !== '') $write[] = $c['sock']; }
    if (@stream_select($read, $write, $except, 0, 200000) === false) continue;

    foreach ($read as $s) {
        if ($s === $srv) {
            $conn = @stream_socket_accept($srv, 0);
            if (!$conn) continue;
            if (count($clients) >= MAX_CLIENTS) { fclose($conn); continue; }
            stream_set_blocking($conn, false);
            $id = $nextId++;
            $clients[$id] = [
                'id' => $id, 'sock' => $conn, 'buf' => '', 'out' => '', 'ws' => false, 'frag' => null,
                'name' => null, 'kind' => 0, 'status' => 'new', 'match' => null,
                'last' => time(), 'rate' => 0, 'rateT' => 0,
            ];
            $byRes[(int)$conn] = $id;
            continue;
        }
        $id = $byRes[(int)$s] ?? null;
        if ($id === null || !isset($clients[$id])) continue;
        $data = @fread($s, 8192);
        if ($data === false || ($data === '' && feof($s))) { dropClient($id); continue; }
        $c = &$clients[$id];
        $c['buf'] .= $data;
        if (strlen($c['buf']) > 20000) { dropClient($id); unset($c); continue; }

        // --- poignée de main HTTP -> WebSocket
        if (!$c['ws']) {
            $end = strpos($c['buf'], "\r\n\r\n");
            if ($end === false) { unset($c); continue; }
            $head = substr($c['buf'], 0, $end);
            $c['buf'] = (string)substr($c['buf'], $end + 4);
            $hdr = [];
            foreach (explode("\r\n", $head) as $line) {
                if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))] = trim($v); }
            }
            $ok = isset($hdr['sec-websocket-key']) && stripos($hdr['upgrade'] ?? '', 'websocket') !== false;
            if ($ok && $allowed && !in_array($hdr['origin'] ?? '', $allowed, true)) $ok = false;
            if (!$ok) {
                @fwrite($s, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
                dropClient($id); unset($c); continue;
            }
            $accept = base64_encode(sha1($hdr['sec-websocket-key'] . WS_GUID, true));
            @fwrite($s, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
            $c['ws'] = true;
        }

        // --- trames
        while (true) {
            $b = $c['buf']; $n = strlen($b);
            if ($n < 2) break;
            $b0 = ord($b[0]); $b1 = ord($b[1]);
            $fin = ($b0 & 0x80) !== 0; $op = $b0 & 0x0F; $masked = ($b1 & 0x80) !== 0; $len = $b1 & 0x7F; $pos = 2;
            if ($len === 126) { if ($n < 4) break; $len = unpack('n', substr($b, 2, 2))[1]; $pos = 4; }
            elseif ($len === 127) { if ($n < 10) break; $len = unpack('J', substr($b, 2, 8))[1]; $pos = 10; }
            if (!$masked || $len > 8192 || $len < 0) { dropClient($id, true); break; }       // client non conforme
            if ($n < $pos + 4 + $len) break;
            $mask = substr($b, $pos, 4);
            $payload = substr($b, $pos + 4, $len);
            if ($len > 0) $payload = $payload ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
            $c['buf'] = (string)substr($b, $pos + 4 + $len);

            if ($op === 0x8) { dropClient($id, true); break; }
            if ($op === 0x9) { $c['out'] .= encodeFrame($payload, 0xA); flushOut($id); continue; }
            if ($op === 0xA) continue;
            if ($op === 0x1 || $op === 0x0) {
                if ($op === 0x1) $c['frag'] = $payload; else $c['frag'] = ($c['frag'] ?? '') . $payload;
                if (strlen((string)$c['frag']) > 8192) { dropClient($id, true); break; }
                if ($fin) { $full = (string)$c['frag']; $c['frag'] = null; handleMsg($id, $full); if (!isset($clients[$id])) break; }
            }
        }
        unset($c);
    }

    foreach ($write as $s) { $id = $byRes[(int)$s] ?? null; if ($id !== null && isset($clients[$id])) flushOut($id); }
    tick();
}
