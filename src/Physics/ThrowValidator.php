<?php

declare(strict_types=1);

namespace Chicken\Physics;

/**
 * Ce lancer peut-il être produit par l'interface du jeu ? Le vrai jeu part de (x0 ∈ ±0.8, y0 = 0.6), avec une vitesse de
 * 0.7 à 1.6 et un effet de ±2.2, en visant un point précis du couloir : mur, plafond, sol ou fond, à une profondeur
 * tz ∈ [0.1, 1] (voir aimPoint / makeHenAim). Les vitesses vx, vy en découlent :
 *     vx = (tx - x0) * speed / tz        vy = (ty - y0) * speed / tz - g * tz / (2 * speed)
 * Un lancer est légitime s'il existe une profondeur tz où la poule touche une surface (|tx| = 1 ou |ty| = 1) ou le fond
 * ou la limite proche (tz = 1 ou 0.1) tout en restant dans le tunnel. On teste les seuls candidats possibles : les racines
 * de |tx| = 1 (linéaire), de |ty| = 1 (second degré) et les deux extrémités.
 */
final readonly class ThrowValidator
{
    private const float EPS = 0.02;

    /** @param Launch|array<string, mixed> $throw un lancer, ou les valeurs brutes reçues (refusées si mal formées) */
    public function isLegit(Launch|array $throw): bool
    {
        $l = $throw instanceof Launch ? $throw : Launch::tryFromArray($throw);
        if ($l === null) {
            return false;
        }
        if (abs($l->x) > 0.801 || abs($l->y - 0.6) > 0.001 || abs($l->spin) > 2.201 || $l->speed < 0.699 || $l->speed > 1.601) {
            return false;
        }

        $a = $l->vy / $l->speed;
        $b = 0.5 * Hen::GRAVITY / ($l->speed * $l->speed);                       // ty(tz) = y0 + a·tz + b·tz²
        $tx = static fn (float $tz): float => $l->x + $l->vx * $tz / $l->speed;
        $ty = static fn (float $tz): float => $l->y + $a * $tz + $b * $tz * $tz;

        foreach ($this->candidates($l, $a, $b) as $tz) {
            if ($tz < 0.1 - 1e-6 || $tz > 1.0 + 1e-6) {
                continue;
            }
            $mx = abs($tx($tz));
            $my = abs($ty($tz));
            if ($mx > 1 + self::EPS || $my > 1 + self::EPS) {
                continue;                                                        // hors du tunnel
            }
            $onSurface = max($mx, $my) >= 1 - self::EPS;
            if ($onSurface || $tz >= 1.0 - 1e-6 || $tz <= 0.1 + 1e-6) {
                return true;
            }
        }

        return false;
    }

    /** @return list<float> profondeurs où le lancer peut toucher une surface, ou les deux extrémités */
    private function candidates(Launch $l, float $a, float $b): array
    {
        $candidates = [0.1, 1.0];
        if (abs($l->vx) > 1e-9) {
            foreach ([1.0, -1.0] as $wall) {                                      // |tx| = 1
                $candidates[] = ($wall - $l->x) * $l->speed / $l->vx;
            }
        }
        foreach ([1.0, -1.0] as $surface) {                                       // |ty| = 1 : b·tz² + a·tz + (y0 ∓ 1) = 0
            $discriminant = $a * $a - 4 * $b * ($l->y - $surface);
            if ($discriminant >= 0) {
                $root = sqrt($discriminant);
                $candidates[] = (-$a + $root) / (2 * $b);
                $candidates[] = (-$a - $root) / (2 * $b);
            }
        }

        return $candidates;
    }
}
