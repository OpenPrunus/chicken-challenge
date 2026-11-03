<?php

declare(strict_types=1);

namespace Chicken\Api;

final readonly class Response
{
    /** @param array<array-key, mixed> $body */
    public function __construct(public int $status, public array $body)
    {
    }

    public function send(): void
    {
        http_response_code($this->status);
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
    }
}
