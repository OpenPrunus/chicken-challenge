<?php

declare(strict_types=1);

namespace Chicken\Api;

use Chicken\Support\Base64Url;

/** Délivre et vérifie les tickets : charge utile (jeton haché, niveau, heure, nonce) + signature HMAC-SHA256. */
final readonly class TicketSigner
{
    final public const int MAX_AGE = 10800;           // 3 h : une partie ne dure pas plus longtemps

    public function __construct(private string $secret)
    {
    }

    public function issue(string $tokenHash, int $level, int $now, ?string $nonce = null): string
    {
        $payload = Base64Url::encode(json_encode(['n' => $nonce ?? bin2hex(random_bytes(8)), 't' => $now, 'h' => $tokenHash, 'l' => $level], JSON_THROW_ON_ERROR));

        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    /** Le ticket si la signature est bonne et qu'il n'est ni trop vieux ni daté du futur ; sinon null. */
    public function verify(string $ticket, int $now, int $maxAge = self::MAX_AGE): ?Ticket
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $parts[0], $this->secret), $parts[1])) {
            return null;
        }
        $json = Base64Url::decode($parts[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p) || !isset($p['n'], $p['t'], $p['h'], $p['l']) || !is_int($p['t']) || !is_string($p['n']) || !is_string($p['h'])) {
            return null;
        }
        if ($p['t'] > $now + 5 || $now - $p['t'] > $maxAge) {
            return null;
        }

        return new Ticket($p['n'], $p['t'], $p['h'], (int) $p['l']);
    }
}
