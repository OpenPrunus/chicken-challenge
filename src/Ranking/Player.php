<?php

declare(strict_types=1);

namespace Chicken\Ranking;

use Chicken\Support\Nickname;

/** Une ligne du classement multijoueur. Immuable : un match produit un nouveau Player. */
final readonly class Player
{
    public function __construct(
        public string $name,
        public string $tokenHash,
        public int $rating = Elo::START,
        public int $played = 0,
        public int $wins = 0,
        public int $losses = 0,
        public int $goalsFor = 0,
        public int $goalsAgainst = 0,
    ) {
    }

    public static function create(string $name, string $tokenHash): self
    {
        return new self($name, $tokenHash);
    }

    /** Ligne lue dans le fichier ; null si elle est inexploitable (pas de pseudo ou de note). */
    public static function tryFromArray(mixed $row): ?self
    {
        if (!is_array($row) || !isset($row['name'], $row['rating'])) {
            return null;
        }

        return new self(
            (string) $row['name'],
            (string) ($row['th'] ?? ''),
            (int) $row['rating'],
            (int) ($row['played'] ?? 0),
            (int) ($row['wins'] ?? 0),
            (int) ($row['losses'] ?? 0),
            (int) ($row['gf'] ?? 0),
            (int) ($row['ga'] ?? 0),
        );
    }

    public function key(): string
    {
        return Nickname::key($this->name);
    }

    /** Ce pseudo est-il réservé par un autre jeton que $hash ? Une ligne sans jeton n'est réservée par personne. */
    public function isReservedAgainst(string $hash): bool
    {
        return $this->tokenHash !== '' && $this->tokenHash !== $hash;
    }

    /** Le joueur après un match : nouvelle note, bilan et jeton (le pseudo est réservé au premier match classé). */
    public function afterMatch(string $name, string $tokenHash, int $rating, bool $won, int $goalsFor, int $goalsAgainst): self
    {
        return clone($this, [
            'name' => $name,
            'tokenHash' => $tokenHash,
            'rating' => $rating,
            'played' => $this->played + 1,
            'wins' => $this->wins + ($won ? 1 : 0),
            'losses' => $this->losses + ($won ? 0 : 1),
            'goalsFor' => $this->goalsFor + $goalsFor,
            'goalsAgainst' => $this->goalsAgainst + $goalsAgainst,
        ]);
    }

    /**
     * Forme persistée (avec le hash du jeton).
     *
     * @return array{name: string, th: string, rating: int, played: int, wins: int, losses: int, gf: int, ga: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'th' => $this->tokenHash, 'rating' => $this->rating, 'played' => $this->played,
            'wins' => $this->wins, 'losses' => $this->losses, 'gf' => $this->goalsFor, 'ga' => $this->goalsAgainst,
        ];
    }

    /**
     * Forme publique (API) : jamais le hash du jeton.
     *
     * @return array{name: string, rating: int, played: int, wins: int, losses: int, gf: int, ga: int}
     */
    public function toPublicArray(): array
    {
        $row = $this->toArray();
        unset($row['th']);

        return $row;
    }
}
