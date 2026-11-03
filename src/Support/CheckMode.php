<?php

declare(strict_types=1);

namespace Chicken\Support;

/**
 * Niveau de sévérité d'un contrôle anti-triche côté serveur.
 *
 * - Enforce : l'action suspecte est refusée (et journalisée) ;
 * - Log     : elle est acceptée mais journalisée comme « suspect » ;
 * - Off     : on fait confiance au client.
 */
enum CheckMode: string
{
    case Enforce = 'enforce';
    case Log = 'log';
    case Off = 'off';

    /** Niveau demandé par une variable d'environnement ; valeur absente ou inconnue : `$default`. */
    public static function fromEnv(string $name, self $default = self::Enforce): self
    {
        $raw = getenv($name);

        return ($raw === false ? null : self::tryFrom($raw)) ?? $default;
    }

    public function isActive(): bool
    {
        return $this !== self::Off;
    }

    public function blocks(): bool
    {
        return $this === self::Enforce;
    }

    /** Préfixe des lignes de journal : un refus ferme ou une simple alerte. */
    public function verdict(): string
    {
        return $this->blocks() ? 'REFUSED' : 'suspect';
    }
}
