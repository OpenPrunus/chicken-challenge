<?php

declare(strict_types=1);

namespace Chicken\WebSocket;

/** Codes d'opération des trames WebSocket (RFC 6455, §5.2) que le serveur traite. */
enum Opcode: int
{
    case Continuation = 0x0;
    case Text = 0x1;
    case Close = 0x8;
    case Ping = 0x9;
    case Pong = 0xA;
}
