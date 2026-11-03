<?php

declare(strict_types=1);

namespace Chicken\Api;

/** Secret de signature des tickets : variable d'environnement, sinon fichier data/secret.key (créé au premier usage). */
final readonly class SecretStore
{
    public function __construct(private string $file, private string $fromEnvironment = '')
    {
    }

    /** @throws ApiError 500 si le fichier ne peut pas être écrit */
    public function get(): string
    {
        if (strlen($this->fromEnvironment) >= 16) {
            return $this->fromEnvironment;
        }
        $secret = is_file($this->file) ? trim((string) file_get_contents($this->file)) : '';
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            if (@file_put_contents($this->file, $secret, LOCK_EX) === false) {
                throw ApiError::storage();
            }
            @chmod($this->file, 0600);
        }

        return $secret;
    }
}
