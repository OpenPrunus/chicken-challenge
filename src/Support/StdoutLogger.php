<?php

declare(strict_types=1);

namespace Chicken\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** Logger PSR-3 : une ligne « [HH:MM:SS] message » par entrée, sur la sortie standard. */
final class StdoutLogger extends AbstractLogger
{
    /** @param resource|null $stream sortie (STDOUT par défaut) */
    public function __construct(private $stream = null)
    {
    }

    /** @param array<string, mixed> $context */
    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = is_scalar($value) || $value instanceof Stringable ? (string) $value : json_encode($value);
        }
        fwrite($this->stream ?? STDOUT, '[' . date('H:i:s') . '] ' . strtr((string) $message, $replace) . "\n");
    }
}
