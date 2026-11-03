<?php

declare(strict_types=1);

namespace Chicken\Api;

final class ClientIp
{
    /**
     * Adresse du client : X-Forwarded-For n'est cru que si la connexion vient d'un proxy privé (la passerelle nginx).
     *
     * @param array<string, mixed> $server
     */
    public static function resolve(array $server): string
    {
        $peer = (string) ($server['REMOTE_ADDR'] ?? '');
        $isPrivate = filter_var($peer, FILTER_VALIDATE_IP) !== false
            && filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if ($isPrivate && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return $peer !== '' ? $peer : 'unknown';
    }
}
