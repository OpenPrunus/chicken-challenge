<?php

declare(strict_types=1);

namespace Chicken\Api;

use Chicken\Game\MatchRules;
use Chicken\Support\Nickname;

/** Classement des parties contre l'IA. Immuable : withMatch() retourne un nouveau tableau. */
final readonly class AiScoreboard
{
    final public const int MAX_PLAYERS = 1000;

    /** @param list<ScoreRow> $rows */
    public function __construct(private array $rows = [])
    {
    }

    public static function fromArray(mixed $rows): self
    {
        $list = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $parsed = ScoreRow::tryFromArray($row);
            if ($parsed !== null) {
                $list[] = $parsed;
            }
        }

        return new self($list);
    }

    /** Un score de partie valide : pseudo non vide, 4 points avec 2 d'écart, ou 12 points au plus. */
    public static function isValidScore(string $name, int $me, int $opp): bool
    {
        return $name !== '' && MatchRules::isFinalScore($me, $opp);
    }

    /** Ce pseudo est-il réservé par un autre jeton ? */
    public function isNameTaken(string $name, string $tokenHash): bool
    {
        $key = Nickname::key($name);
        $row = array_find($this->rows, static fn (ScoreRow $r): bool => $r->key() === $key);

        return $row?->isReservedAgainst($tokenHash) ?? false;
    }

    /** Ajoute une partie au cumul du joueur (pseudo insensible à la casse) ; 1000 joueurs au plus. */
    #[\NoDiscard('le classement est immuable : le résultat porte le nouveau cumul')]
    public function withMatch(string $name, int $me, int $opp, string $tokenHash = ''): self
    {
        $key = Nickname::key($name);
        $rows = $this->rows;
        $index = array_find_key($rows, static fn (ScoreRow $r): bool => $r->key() === $key);
        if ($index !== null) {
            $rows[$index] = $rows[$index]->withMatch($me, $opp, $tokenHash);
        } else {
            $rows[] = (new ScoreRow($name, 0, 0, 0, 0, $tokenHash))->withMatch($me, $opp, $tokenHash);
        }

        return new self(array_slice($rows, 0, self::MAX_PLAYERS));
    }

    /**
     * Haut du classement : victoires d'abord, puis différence de buts.
     *
     * @return list<ScoreRow>
     */
    public function top(int $limit = 20): array
    {
        $rows = $this->rows;
        usort($rows, static fn (ScoreRow $a, ScoreRow $b): int => [$b->wins, $b->goalDifference()] <=> [$a->wins, $a->goalDifference()]);

        return array_slice($rows, 0, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (ScoreRow $r): array => $r->toArray(), $this->rows);
    }
}
