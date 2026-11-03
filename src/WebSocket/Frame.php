<?php

declare(strict_types=1);

namespace Chicken\WebSocket;

/** Une trame client décodée. `consumed` : nombre d'octets de la trame dans le tampon. */
final readonly class Frame
{
    public function __construct(public bool $fin, public int $opcode, public string $payload, public int $consumed)
    {
    }

    /** Code d'opération connu, ou null (binaire, réservé...) : le serveur l'ignore. */
    public function type(): ?Opcode
    {
        return Opcode::tryFrom($this->opcode);
    }
}
