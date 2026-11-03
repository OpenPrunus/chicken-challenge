<?php

declare(strict_types=1);

namespace Chicken\Api;

/** État anti-abus partagé entre les requêtes : compteurs de débit et tickets déjà utilisés (nonce → heure). */
final class AbuseState
{
    /** @param array<string, int> $used */
    public function __construct(public readonly RateLimiter $rate = new RateLimiter(), private array $used = [])
    {
    }

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $used = is_array($data['used'] ?? null) ? array_map(intval(...), $data['used']) : [];

        return new self(RateLimiter::fromArray($data['rate'] ?? null), $used);
    }

    public function isUsed(string $nonce): bool
    {
        return isset($this->used[$nonce]);
    }

    /** Le ticket ne resservira plus ; les nonces plus vieux que la durée de validité d'un ticket sont oubliés. */
    public function markUsed(string $nonce, int $now): void
    {
        $this->used[$nonce] = $now;
        $this->used = array_filter($this->used, static fn (int $t): bool => $t >= $now - TicketSigner::MAX_AGE - 60);
    }

    /** @return array{rate: array<string, list<int>>, used: array<string, int>} */
    public function toArray(): array
    {
        return ['rate' => $this->rate->toArray(), 'used' => $this->used];
    }
}
