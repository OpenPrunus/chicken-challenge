<?php

declare(strict_types=1);

namespace Chicken\Api;

/** Ticket de partie : délivré au début, renvoyé avec le score, valable une seule fois. */
final readonly class Ticket
{
    public function __construct(public string $nonce, public int $issuedAt, public string $tokenHash, public int $level)
    {
    }
}
