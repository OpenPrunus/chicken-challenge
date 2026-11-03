<?php

declare(strict_types=1);

namespace Chicken\Ranking;

use Arcade\Support\Nickname;
use Countable;

/**
 * Classement multijoueur (Elo). Immuable : record() retourne un nouveau classement, le serveur le persiste.
 *
 * Un joueur « classé » présente un jeton ; le premier à terminer un match classé sous un pseudo le réserve, les
 * autres jetons ne peuvent plus l'utiliser (impossible d'usurper un pseudo du classement).
 */
final readonly class Ranking implements Countable
{
    /** @param array<array-key, Player> $players indexés par la clé du pseudo (insensible à la casse) */
    public function __construct(private array $players = [])
    {
    }

    /** Charge la table lue dans un fichier ; les lignes inexploitables sont ignorées. */
    public static function fromArray(mixed $rows): self
    {
        $players = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $player = Player::tryFromArray($row);
            if ($player !== null) {
                $players[$player->key()] = $player;
            }
        }

        return new self($players);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->players);
    }

    public function find(string $name): ?Player
    {
        return $this->players[Nickname::key($name)] ?? null;
    }

    /** Ce pseudo est-il réservé par un autre jeton que $hash (chaîne vide = invité sans jeton) ? */
    public function isReservedByOther(string $name, string $hash): bool
    {
        return $this->find($name)?->isReservedAgainst($hash) ?? false;
    }

    /** Rang d'un pseudo (1 = premier) : 1 + nombre de joueurs strictement mieux classés. Les ex æquo partagent le rang. */
    public function rankOf(string $name): int
    {
        $mine = $this->find($name);
        if ($mine === null) {
            return count($this->players) + 1;
        }
        $better = array_filter(
            $this->players,
            static fn (Player $p): bool => $p->rating > $mine->rating || ($p->rating === $mine->rating && $p->wins > $mine->wins),
        );

        return 1 + count($better);
    }

    /**
     * Enregistre un match classé. Retourne null s'il ne peut pas l'être (jeton manquant, même jeton des deux côtés,
     * pseudo réservé par un autre). $factor (0 à 1) réduit gains et pertes quand le même couple se rencontre trop souvent.
     */
    #[\NoDiscard('le classement est immuable : le résultat porte les nouvelles notes')]
    public function record(Participant $winner, Participant $loser, int $goalsWinner, int $goalsLoser, float $factor = 1.0): ?RankedMatch
    {
        $hw = $winner->tokenHash;
        $hl = $loser->tokenHash;
        if ($hw === '' || $hl === '' || $hw === $hl) {
            return null;
        }
        if ($this->isReservedByOther($winner->name, $hw) || $this->isReservedByOther($loser->name, $hl)) {
            return null;
        }

        $w = $this->find($winner->name) ?? Player::create($winner->name, $hw);
        $l = $this->find($loser->name) ?? Player::create($loser->name, $hl);

        $expected = Elo::expected($w->rating, $l->rating);
        $newW = max(Elo::FLOOR, (int) round($w->rating + $factor * Elo::kFactor($w->played) * (1 - $expected)));
        $newL = max(Elo::FLOOR, (int) round($l->rating - $factor * Elo::kFactor($l->played) * (1 - $expected)));

        $w2 = $w->afterMatch($winner->name, $hw, $newW, true, $goalsWinner, $goalsLoser);
        $l2 = $l->afterMatch($loser->name, $hl, $newL, false, $goalsLoser, $goalsWinner);
        $next = new self([...$this->players, $w2->key() => $w2, $l2->key() => $l2]);

        return new RankedMatch(
            $next,
            new MatchOutcome($newW, $newW - $w->rating, $next->rankOf($w2->name), $factor),
            new MatchOutcome($newL, $newL - $l->rating, $next->rankOf($l2->name), $factor),
        );
    }

    /**
     * Haut du classement public : note, puis victoires, puis pseudo.
     *
     * @return list<Player>
     */
    public function top(int $limit = 20): array
    {
        $rows = array_values($this->players);
        usort($rows, static fn (Player $a, Player $b): int => [$b->rating, $b->wins, $a->name] <=> [$a->rating, $a->wins, $b->name]);

        return array_slice($rows, 0, $limit);
    }

    /** @return array<array-key, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Player $p): array => $p->toArray(), $this->players);
    }
}
