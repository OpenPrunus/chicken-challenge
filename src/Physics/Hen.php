<?php

declare(strict_types=1);

namespace Chicken\Physics;

/**
 * Poule en vol, dans le repère du lanceur : elle part de z = 0 (le lanceur) vers z = 1 (le défenseur).
 * Copie fidèle de la physique de web/assets/engine.js ; comparée à elle par des vecteurs générés (tests/fixtures).
 */
final class Hen
{
    final public const float GRAVITY = 0.5;

    public int $bounces = 0;

    /** Arrivée chez le défenseur : plus rien à rattraper. */
    public bool $hasArrived {
        get => $this->z >= 1.0;
    }

    private function __construct(
        public float $z,
        public float $x,
        public float $y,
        public float $vx,
        public float $vy,
        public float $spin,
        public float $speed,
        public readonly float $spin0,
        public readonly float $speed0,
    ) {
    }

    /** État de la poule juste après le lancer. */
    public static function thrown(Launch $launch): self
    {
        return new self(0.0, $launch->x, $launch->y, $launch->vx, $launch->vy, $launch->spin, $launch->speed, $launch->spin, $launch->speed);
    }

    /** Un pas de physique ; retourne la surface contre laquelle la poule a rebondi, sinon null. */
    public function step(float $dt): ?Axis
    {
        $this->z += $this->speed * $dt;
        $this->x += $this->vx * $dt;
        $this->y += $this->vy * $dt;
        $this->vx += $this->spin * $dt;
        $this->vy += self::GRAVITY * $dt;

        $hit = null;
        if (abs($this->x) > 1) {
            $this->x = $this->x > 0 ? 1.0 : -1.0;
            $this->vx *= -0.88;
            $this->spin *= 0.7;
            $hit = Axis::X;
        }
        if ($this->y > 1 || $this->y < -1) {
            $this->y = $this->y > 0 ? 1.0 : -1.0;
            $this->vy *= -0.82;
            $hit ??= Axis::Y;
        }
        if ($hit !== null) {
            $this->bounces++;
            $this->speed = max(0.35, $this->speed * 0.93);
        }

        return $hit;
    }

    public function seenByDefender(): DefenderView
    {
        return new DefenderView(1.0 - $this->z, -$this->x, $this->y, $this->bounces, $this->spin0, $this->speed0);
    }
}
