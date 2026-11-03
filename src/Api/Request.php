<?php

declare(strict_types=1);

namespace Chicken\Api;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     */
    public function __construct(
        public string $method,
        public array $query = [],
        public string $body = '',
        public array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, (string) file_get_contents('php://input'), $_SERVER);
    }
}
