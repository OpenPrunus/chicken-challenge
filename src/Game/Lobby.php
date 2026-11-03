<?php

declare(strict_types=1);

namespace Chicken\Game;

use Arcade\Support\Nickname;

/** Registre des clients connectés et diffusion de la liste du salon (les joueurs du salon en sont les observateurs). */
final class Lobby
{
    /** @var array<int, Client> */
    private array $clients = [];
    private int $nextId = 1;

    public function nextId(): int
    {
        return $this->nextId++;
    }

    public function add(Client $client): void
    {
        $this->clients[$client->id] = $client;
    }

    public function remove(Client $client): void
    {
        unset($this->clients[$client->id]);
    }

    public function get(int $id): ?Client
    {
        return $this->clients[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->clients);
    }

    /** @return array<int, Client> */
    public function clients(): array
    {
        return $this->clients;
    }

    public function isNameOnline(string $name): bool
    {
        $key = Nickname::key($name);

        return array_any($this->clients, static fn (Client $c): bool => $c->name !== null && Nickname::key($c->name) === $key);
    }

    /** Premier joueur en file d'attente du matchmaking automatique, autre que $except. */
    public function firstQueued(Client $except): ?Client
    {
        return array_find($this->clients, static fn (Client $c): bool => $c->id !== $except->id && $c->status === ClientStatus::Queue);
    }

    /** Envoie la liste des joueurs nommés à tous ceux qui sont dans le salon ou la file d'attente. */
    public function broadcast(): void
    {
        $named = array_filter($this->clients, static fn (Client $c): bool => $c->isNamed);
        $list = array_values(array_map(static fn (Client $c): array => $c->lobbyEntry(), $named));
        foreach ($named as $client) {
            if ($client->status->isInLobby()) {
                $client->send(Event::Lobby, ['players' => $list]);
            }
        }
    }
}
