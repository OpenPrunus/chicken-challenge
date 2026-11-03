<?php

declare(strict_types=1);

namespace Chicken\Api;

use RuntimeException;

/** Erreur à renvoyer telle quelle au client (statut HTTP + corps JSON). */
final class ApiError extends RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(public readonly int $status, public readonly array $body)
    {
        parent::__construct((string) ($body['error'] ?? 'error'));
    }

    public static function storage(): self
    {
        return new self(500, ['error' => 'storage']);
    }

    public function toResponse(): Response
    {
        return new Response($this->status, $this->body);
    }
}
