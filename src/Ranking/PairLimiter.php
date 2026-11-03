<?php

declare(strict_types=1);

namespace Chicken\Ranking;

/**
 * Anti-collusion : deux joueurs (ou un même joueur avec deux navigateurs) qui se renvoient la victoire pour gonfler leur
 * note. Les gains diminuent à chaque rencontre du même couple dans la fenêtre : 1, 1, 0.5, 0.25, puis plus rien.
 */
final readonly class PairLimiter
{
    /** Facteur selon le nombre de rencontres déjà jouées dans la fenêtre ; au-delà, 0. */
    final public const array FACTORS = [1.0, 1.0, 0.5, 0.25];
    final public const int DEFAULT_WINDOW = 86400;

    /** @param array<array-key, list<int>> $meetings horodatages des matchs classés, par couple */
    public function __construct(private array $meetings = [])
    {
    }

    public static function fromArray(mixed $rows): self
    {
        $meetings = [];
        foreach (is_array($rows) ? $rows : [] as $key => $times) {
            if (is_array($times)) {
                $meetings[$key] = array_values(array_map(intval(...), $times));
            }
        }

        return new self($meetings);
    }

    /** Clé d'un couple (ordre indifférent) ; ne garde que le début des hashes. */
    public static function key(string $hashA, string $hashB): string
    {
        $pair = [$hashA, $hashB];
        sort($pair);

        return substr($pair[0], 0, 16) . '|' . substr($pair[1], 0, 16);
    }

    /** Enregistre une rencontre : facteur pour ce match et nouvel état (entrées expirées purgées). */
    #[\NoDiscard('le limiteur est immuable : le résultat porte le nouvel état')]
    public function meet(string $key, int $now, int $window = self::DEFAULT_WINDOW): Meeting
    {
        $meetings = [];
        foreach ($this->meetings as $k => $times) {
            $recent = array_values(array_filter($times, static fn (int $t): bool => $t > $now - $window));
            if ($recent !== []) {
                $meetings[$k] = $recent;
            }
        }
        $previous = count($meetings[$key] ?? []);
        $meetings[$key][] = $now;

        return new Meeting(self::FACTORS[$previous] ?? 0.0, new self($meetings));
    }

    /** @return array<array-key, list<int>> */
    public function toArray(): array
    {
        return $this->meetings;
    }
}
