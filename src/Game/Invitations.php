<?php

declare(strict_types=1);

namespace Chicken\Game;

/** Défis en attente : destinataire → expéditeur → heure d'envoi. */
final class Invitations
{
    /** @var array<int, array<int, float>> */
    private array $pending = [];

    public function invite(int $from, int $to, float $now): void
    {
        $this->pending[$to][$from] = $now;
    }

    public function has(int $to, int $from): bool
    {
        return isset($this->pending[$to][$from]);
    }

    public function remove(int $to, int $from): void
    {
        unset($this->pending[$to][$from]);
    }

    /**
     * Retire tous les défis envoyés ou reçus par $id.
     *
     * @return list<int> destinataires d'un défi émis par $id (à prévenir de son annulation)
     */
    public function forget(int $id): array
    {
        unset($this->pending[$id]);
        $recipients = [];
        foreach ($this->pending as $to => $senders) {
            if (isset($senders[$id])) {
                unset($this->pending[$to][$id]);
                $recipients[] = $to;
            }
        }

        return $recipients;
    }

    /**
     * Retire et retourne les défis expirés.
     *
     * @return list<array{from: int, to: int}>
     */
    public function expire(float $now, float $ttl): array
    {
        $expired = [];
        foreach ($this->pending as $to => $senders) {
            foreach ($senders as $from => $sentAt) {
                if ($now - $sentAt > $ttl) {
                    unset($this->pending[$to][$from]);
                    $expired[] = ['from' => $from, 'to' => $to];
                }
            }
        }

        return $expired;
    }
}
