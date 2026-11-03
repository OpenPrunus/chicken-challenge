<?php

declare(strict_types=1);

namespace Chicken\Tests\Game\Support;

use Arcade\Server\Transport;

/** Transport de test : garde les messages envoyés au client. */
final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    #[\Override]
    public function send(string $json): void
    {
        $this->sent[] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> les messages d'un type donné */
    public function of(string $type): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => $m['t'] === $type));
    }

    /** @return array<string, mixed>|null le dernier message d'un type */
    public function last(string $type): ?array
    {
        return array_last($this->of($type));
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_column($this->sent, 't');
    }
}
