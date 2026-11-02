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
