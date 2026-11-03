<?php

declare(strict_types=1);

namespace Chicken\Game;

/** Types de messages serveur → client (champ « t »). */
enum Event: string
{
    case Welcome = 'welcome';
    case Lobby = 'lobby';
    case Invite = 'invite';
    case InviteCancel = 'invite_cancel';
    case Waiting = 'waiting';
    case Declined = 'declined';
    case Error = 'error';
    case Queued = 'queued';
    case Unqueued = 'unqueued';
    case Match = 'match';
    case Throw = 'throw';
    case Result = 'result';
    case Rated = 'rated';
    case Unrated = 'unrated';
    case RematchAsk = 'rematch_ask';
    case OpponentLeft = 'oppleft';
    case Left = 'left';
    case Pos = 'pos';
    case Pong = 'pong';
}
