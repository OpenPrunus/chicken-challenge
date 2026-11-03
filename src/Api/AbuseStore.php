<?php

declare(strict_types=1);

namespace Chicken\Api;

use Chicken\Support\AtomicFile;

final readonly class AbuseStore
{
    public function __construct(private string $file)
    {
    }

    public function load(): AbuseState
    {
        return AbuseState::fromArray(AtomicFile::readJson($this->file));
    }

    public function save(AbuseState $state): bool
    {
        return AtomicFile::write($this->file, json_encode($state->toArray(), JSON_THROW_ON_ERROR));
    }
}
