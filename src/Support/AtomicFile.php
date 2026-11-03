<?php

declare(strict_types=1);

namespace Chicken\Support;

final class AtomicFile
{
    /** Écrit via un fichier temporaire puis rename : un lecteur ne voit jamais un fichier tronqué. */
    public static function write(string $file, string $contents): bool
    {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** Contenu JSON décodé d'un fichier ; null s'il est absent ou illisible. */
    public static function readJson(string $file): mixed
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = (string) @file_get_contents($file);

        return json_validate($raw) ? json_decode($raw, true) : null;
    }
}
