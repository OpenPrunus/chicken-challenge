<?php

declare(strict_types=1);

namespace Chicken\Physics;

use Chicken\Support\Num;

/** Paramètres d'un lancer, tels que le lanceur les envoie : position, vitesses, effet et vitesse d'avance. */
final readonly class Launch
{
    public function __construct(
        public float $x,
        public float $y,
        public float $vx,
        public float $vy,
        public float $spin,
        public float $speed,
    ) {
    }

    /** Lancer sanitisé : chaque valeur est ramenée dans les bornes du protocole ; null si l'une n'est pas un nombre. */
    public static function fromWire(mixed $hen): ?self
    {
        if (!is_array($hen)) {
            return null;
        }
        $values = [
            Num::clamped($hen['x'] ?? null, -1, 1), Num::clamped($hen['y'] ?? null, -1, 1),
            Num::clamped($hen['vx'] ?? null, -60, 60), Num::clamped($hen['vy'] ?? null, -60, 60),
            Num::clamped($hen['spin'] ?? null, -2.6, 2.6), Num::clamped($hen['speed'] ?? null, 0.3, 1.9),
        ];

        return in_array(null, $values, true) ? null : new self(...$values);
    }

    /**
     * Lancer brut : les six valeurs doivent être présentes et finies, sans aucune correction.
     *
     * @param array<string, mixed> $raw
     */
    public static function tryFromArray(array $raw): ?self
    {
        $values = [];
        foreach (['x', 'y', 'vx', 'vy', 'spin', 'speed'] as $key) {
            $value = Num::finite($raw[$key] ?? null);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        return new self(...$values);
    }

    /** @return array{x: float, y: float, vx: float, vy: float, spin: float, speed: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'vx' => $this->vx, 'vy' => $this->vy, 'spin' => $this->spin, 'speed' => $this->speed];
    }
}
