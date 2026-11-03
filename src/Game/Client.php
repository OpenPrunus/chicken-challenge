<?php

declare(strict_types=1);

namespace Chicken\Game;

use Arcade\Server\IpAware;
use Arcade\Server\Session;
use Arcade\Server\Transport;

/** Un joueur connecté : identité, état dans le salon / le match et canal d'envoi. */
final class Client implements Session, IpAware
{
    final public const int MAX_MESSAGES_PER_SECOND = 80;

    public ?string $name = null;
    public string $tokenHash = '';
    public string $ip = '';
    public int $kind = 0;
    public ClientStatus $status = ClientStatus::New;
    public ?int $matchId = null;
    public private(set) int $lastSeen;

    private int $rateSecond = 0;
    private int $rateCount = 0;

    public bool $isNamed {
        get => $this->name !== null;
    }

    public function __construct(public readonly int $id, private readonly Transport $transport, int $now)
    {
        $this->lastSeen = $now;
    }

    /** @param array<string, mixed> $data */
    public function send(Event $event, array $data = []): void
    {
        $this->transport->send(json_encode(
            ['t' => $event->value] + $data,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));
    }

    /** Anti-flood : plus de 80 messages dans la même seconde sont ignorés. Enregistre aussi la dernière activité. */
    public function acceptMessage(int $now): bool
    {
        if ($this->rateSecond !== $now) {
            $this->rateSecond = $now;
            $this->rateCount = 0;
        }
        if (++$this->rateCount > self::MAX_MESSAGES_PER_SECOND) {
            return false;
        }
        $this->lastSeen = $now;

        return true;
    }

    #[\Override]
    public function setAddress(string $ip): void
    {
        $this->ip = $ip;
    }

    #[\Override]
    public function idleFor(int $now): int
    {
        return $now - $this->lastSeen;
    }

    /** @return array{id: int, name: string, kind: int, status: string} */
    public function lobbyEntry(): array
    {
        return ['id' => $this->id, 'name' => (string) $this->name, 'kind' => $this->kind, 'status' => $this->status->value];
    }
}
