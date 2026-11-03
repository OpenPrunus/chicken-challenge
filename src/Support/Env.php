<?php

declare(strict_types=1);

namespace Chicken\Support;

/** Lecture typée des variables d'environnement (réglages, tests). */
final class Env
{
    /**
     * Nombre positif lu dans l'environnement, sinon la valeur par défaut.
     * Avec $allowZero, 0 est aussi une valeur valide (désactive un contrôle) ; un négatif ne l'est jamais.
     */
    public static function number(string $name, float $default, bool $allowZero = false): float
    {
        $raw = getenv($name);
        if ($raw === false || !is_numeric($raw)) {
            return $default;
        }
        $value = (float) $raw;

        return ($value > 0 || ($allowZero && $value === 0.0)) ? $value : $default;
    }

    public static function string(string $name, string $default = ''): string
    {
        $raw = getenv($name);

        return ($raw === false || $raw === '') ? $default : $raw;
    }

    public static function mode(string $name, CheckMode $default = CheckMode::Enforce): CheckMode
    {
        return CheckMode::tryFrom(self::string($name)) ?? $default;
    }
}
