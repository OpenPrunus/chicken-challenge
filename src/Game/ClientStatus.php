<?php

declare(strict_types=1);

namespace Chicken\Game;

/** Où en est un client ; la valeur est envoyée telle quelle aux autres joueurs dans la liste du salon. */
enum ClientStatus: string
{
    case New = 'new';
    case Lobby = 'lobby';
    case Queue = 'queue';
    case InMatch = 'match';

    /** Visible dans le salon et prévenu de ses mises à jour. */
    public function isInLobby(): bool
    {
        return $this === self::Lobby || $this === self::Queue;
    }
}
