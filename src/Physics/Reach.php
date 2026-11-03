<?php

declare(strict_types=1);

namespace Chicken\Physics;

/** Zone de capture : rayon de la main (unités du tunnel) et fenêtre de profondeur. */
final readonly class Reach
{
    public function __construct(public float $radius, public float $zMax)
    {
    }
}
