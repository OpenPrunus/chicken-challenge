<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/**
 * Jeton secret généré par le navigateur du joueur. Seul son hash SHA-256 est conservé : il réserve un pseudo
 * (premier arrivé) sans que le serveur puisse rejouer le jeton.
 */
final class Token
{
    /** Un jeton valide : 32 à 64 caractères hexadécimaux. */
    public static function isValid(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32,64}$/', $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
