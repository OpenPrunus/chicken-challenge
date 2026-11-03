<?php

declare(strict_types=1);

namespace Chicken\Api;

use Chicken\Support\Nickname;

/** Cumul d'un joueur dans le classement contre l'IA. Immuable. */
final readonly class ScoreRow
{
    public function __construct(
        public string $name,
        public int $played,
        public int $wins,
        public int $goalsFor,
        public int $goalsAgainst,
        public string $tokenHash = '',
    ) {
    }

    public static function tryFromArray(mixed $row): ?self
    {
        if (!is_array($row) || !isset($row['name'])) {
            return null;
        }

        return new self(
            name: (string) $row['name'],
            played: (int) ($row['played'] ?? 0),
            wins: (int) ($row['wins'] ?? 0),
            goalsFor: (int) ($row['gf'] ?? 0),
            goalsAgainst: (int) ($row['ga'] ?? 0),
            tokenHash: (string) ($row['th'] ?? ''),
        );
    }

    public function key(): string
    {
        return Nickname::key($this->name);
    }

    /** Pseudo réservé par un autre jeton ? (une ligne ancienne, sans jeton, n'est réservée par personne) */
    public function isReservedAgainst(string $tokenHash): bool
    {
        return $this->tokenHash !== '' && $this->tokenHash !== $tokenHash;
    }

    /** Le cumul après une partie ; le premier jeton à jouer réserve le pseudo. */
    public function withMatch(int $me, int $opp, string $tokenHash): self
    {
        return clone($this, [
            'played' => $this->played + 1,
            'wins' => $this->wins + ($me > $opp ? 1 : 0),
            'goalsFor' => $this->goalsFor + $me,
            'goalsAgainst' => $this->goalsAgainst + $opp,
            'tokenHash' => $this->tokenHash === '' ? $tokenHash : $this->tokenHash,
        ]);
    }

    public function goalDifference(): int
    {
        return $this->goalsFor - $this->goalsAgainst;
    }

    /**
     * Forme persistée (avec le hash du jeton s'il existe).
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $row = $this->toPublicArray();
        if ($this->tokenHash !== '') {
            $row['th'] = $this->tokenHash;
        }

        return $row;
    }

    /**
     * Forme publique : jamais le hash du jeton.
     *
     * @return array{name: string, played: int, wins: int, gf: int, ga: int}
     */
    public function toPublicArray(): array
    {
        return ['name' => $this->name, 'played' => $this->played, 'wins' => $this->wins, 'gf' => $this->goalsFor, 'ga' => $this->goalsAgainst];
    }
}
