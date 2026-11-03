<?php

declare(strict_types=1);

namespace Chicken\WebSocket;

use RuntimeException;

/** Trame non conforme (non masquée, trop grande) : le client est déconnecté. */
final class ProtocolException extends RuntimeException
{
}
