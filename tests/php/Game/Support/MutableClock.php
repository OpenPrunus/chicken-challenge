<?php

declare(strict_types=1);

namespace Chicken\Tests\Game\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** Horloge de test qui n'avance que quand on le décide. */
final class MutableClock implements ClockInterface
{
    public function __construct(public float $time = 1_000_000.0)
    {
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $this->time));
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
