<?php

declare(strict_types=1);

namespace Chicken\Game;

/** Types de messages client → serveur (champ « t »). */
enum ClientMessage: string
{
    case Ping = 'ping';
    case Hello = 'hello';
    case Challenge = 'challenge';
    case Accept = 'accept';
    case Decline = 'decline';
    case Auto = 'auto';
    case Cancel = 'cancel';
    case Leave = 'leave';
    // messages d'un match en cours
    case Throw = 'throw';
    case Catch = 'catch';
    case Miss = 'miss';
    case Pos = 'pos';
    case Rematch = 'rematch';

    public function isMatchMessage(): bool
    {
        return match ($this) {
            self::Throw, self::Catch, self::Miss, self::Pos, self::Rematch => true,
            default => false,
        };
    }
}
