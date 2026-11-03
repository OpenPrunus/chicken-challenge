<?php

declare(strict_types=1);

namespace Chicken\Api;

/** Limite de débit à fenêtre glissante : clé → horodatages des requêtes acceptées. */
final class RateLimiter
{
    /** @param array<string, list<int>> $hits */
    public function __construct(private array $hits = [])
    {
    }

    public static function fromArray(mixed $state): self
    {
        $hits = [];
        foreach (is_array($state) ? $state : [] as $key => $times) {
            if (is_array($times)) {
                $hits[(string) $key] = array_values(array_map(intval(...), $times));
            }
        }

        return new self($hits);
    }

    /** Tente une requête ; ne consomme une place que si elle est autorisée. */
    public function attempt(string $key, int $now, int $limit, int $window): RateDecision
    {
        foreach ($this->hits as $k => $times) {                       // purge des entrées expirées
            $recent = array_values(array_filter($times, static fn (int $t): bool => $t > $now - $window));
            if ($recent === []) {
                unset($this->hits[$k]);
            } else {
                $this->hits[$k] = $recent;
            }
        }
        $times = $this->hits[$key] ?? [];
        if (count($times) >= $limit) {
            // limite 0 : aucune entrée à attendre, on attend la fenêtre entière
            return new RateDecision(false, $times === [] ? max(1, $window) : max(1, array_first($times) + $window - $now));
        }
        $times[] = $now;
        $this->hits[$key] = $times;

        return new RateDecision(true);
    }

    /** @return array<string, list<int>> */
    public function toArray(): array
    {
        return $this->hits;
    }
}
