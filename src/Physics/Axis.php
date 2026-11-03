<?php

declare(strict_types=1);

namespace Chicken\Physics;

/** Surface contre laquelle la poule a rebondi : un mur (x) ou le sol / le plafond (y). */
enum Axis: string
{
    case X = 'x';
    case Y = 'y';
}
