<?php

declare(strict_types=1);

namespace Chicken\Physics;

/**
 * La poule vue par le défenseur (perspective inversée : z' = 1 - z, x' = -x). Porte la règle de capture, copie fidèle
 * de web/assets/engine.js (chaos, zone de capture, canCatch).
 */
final readonly class DefenderView
{
    /** Rayon de la poule au plan proche / demi-largeur du tunnel (identique en paysage et en portrait). */
    final public const float VISUAL_UNITS = 100 / 360;

    public function __construct(
        public float $z,
        public float $x,
        public float $y,
        public int $bounces,
        public float $spin0,
        public float $speed0,
    ) {
    }

    /** « Chaos » de la poule, de 0 à 1 : rebonds, effet et vitesse de départ. */
    public function chaos(): float
    {
        $c = 0.25 * $this->bounces
           + 0.30 * min(1.0, abs($this->spin0) / 2)
           + 0.30 * max(0.0, ($this->speed0 - 0.9) / 0.7);

        return max(0.0, min(1.0, $c));
    }

    /** Plus la poule est folle, plus la zone de capture est tolérante. */
    public function reach(): Reach
    {
        $c = $this->chaos();

        return new Reach(0.42 + 0.22 * $c, 0.25 + 0.08 * $c);
    }

    /**
     * La main (mx, my) rattrape-t-elle la poule ? Près de la position d'arrivée (x, y), OU sur la poule telle qu'elle
     * est dessinée (x·s, y·s, à sa taille visible, qui dépend de l'agrandissement $boost de l'écran du joueur).
     * $slack élargit tolérance et fenêtre (latence, pas de temps du client).
     */
    public function canCatch(float $mx, float $my, float $slack = 1.0, float $boost = 1.0): bool
    {
        $reach = $this->reach();
        if ($this->z >= $reach->zMax * ($slack > 1.0 ? 1.15 : 1.0)) {
            return false;
        }
        $s = 1 / (1 + 3 * $this->z);
        if (hypot($this->x - $mx, $this->y - $my) < $reach->radius * $slack) {
            return true;
        }

        return hypot($this->x * $s - $mx, $this->y * $s - $my) < self::VISUAL_UNITS * $boost * $s * 1.15 * $slack;
    }
}
