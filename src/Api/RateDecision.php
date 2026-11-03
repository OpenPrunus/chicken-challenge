<?php

declare(strict_types=1);

namespace Chicken\Api;

final readonly class RateDecision
{
    public function __construct(public bool $allowed, public int $retryAfter = 0)
    {
    }
}
