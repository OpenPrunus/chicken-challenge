<?php

declare(strict_types=1);

namespace Chicken\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** Horloge PSR-20 : le temps est une dépendance injectable (tests déterministes). */
final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    /** Secondes depuis l'epoch, avec les microsecondes. */
    public static function seconds(ClockInterface $clock): float
    {
        return (float) $clock->now()->format('U.u');
    }
}
