<?php

declare(strict_types=1);

namespace Chicken\Game;

use Chicken\Physics\Launch;

/** Un match entre deux joueurs : score, tour de service et état. Les transitions passent toutes par des méthodes. */
final class GameMatch
{
    /** Pause après un but, le temps de l'animation (secondes). */
    final public const float GOAL_ANIMATION = 2.5;

    public private(set) MatchState $state = MatchState::Serve;
    public private(set) int $turn;
    public private(set) float $since;                  // début de l'état courant (fin de l'animation après un but)
    public private(set) ?int $thrower = null;
    public private(set) int $rally = 0;
    public private(set) ?Launch $launch = null;
    public private(set) bool $recorded = false;

    /** @var array<int, int> */
    public private(set) array $score;
    /** @var array<int, true> */
    private array $rematch = [];

    public int $totalPoints {
        get => array_sum($this->score);
    }

    public function __construct(
        public readonly int $id,
        public readonly int $a,
        public readonly int $b,
        int $first,
        public readonly bool $ranked,
        public readonly float $startedAt,
    ) {
        $this->turn = $first;
        $this->since = $startedAt;
        $this->score = [$a => 0, $b => 0];
    }

    public function other(int $playerId): int
    {
        return $this->a === $playerId ? $this->b : $this->a;
    }

    /** @return array{int, int} */
    public function players(): array
    {
        return [$this->a, $this->b];
    }

    public function beginFlight(int $thrower, Launch $launch, float $now): void
    {
        $this->state = MatchState::Flight;
        $this->thrower = $thrower;
        $this->launch = $launch;
        $this->since = $now;
        $this->rally++;
    }

    /** Le défenseur rattrape la poule : il devient lanceur. */
    public function completeCatch(int $defender, float $now): void
    {
        $this->turn = $defender;
        $this->state = MatchState::Serve;
        $this->since = $now;
    }

    /** Un but : le marqueur récupère la poule et relance. Retourne vrai si le match est terminé. */
    public function scoreGoal(int $scorer, float $now): bool
    {
        $this->score[$scorer]++;
        $this->turn = $scorer;
        $over = MatchRules::isOver($this->score[$this->a], $this->score[$this->b]);
        $this->state = $over ? MatchState::Over : MatchState::Serve;
        $this->since = $now + self::GOAL_ANIMATION;

        return $over;
    }

    /** Un joueur demande la revanche ; vrai quand les deux la veulent. */
    public function requestRematch(int $playerId): bool
    {
        $this->rematch[$playerId] = true;

        return count($this->rematch) === 2;
    }

    public function markRecorded(): void
    {
        $this->recorded = true;
    }

    public function winner(): int
    {
        return $this->score[$this->a] > $this->score[$this->b] ? $this->a : $this->b;
    }

    /** Qui sert la revanche : celui qui n'a pas marqué en dernier. */
    public function rematchServer(): int
    {
        return $this->turn === $this->a ? $this->b : $this->a;
    }

    /**
     * Message « result » vu par le joueur $playerId.
     *
     * @return array<string, mixed>
     */
    public function resultFor(int $playerId, string $kind, int $by, bool $over): array
    {
        return [
            'kind' => $kind, 'rally' => $this->rally,
            'role' => $this->thrower === $playerId ? 'thrower' : 'defender',
            'by' => $by === $playerId ? 'you' : 'opp',
            'you' => $this->score[$playerId], 'opp' => $this->score[$this->other($playerId)],
            'next' => $this->turn === $playerId ? 'you' : 'opp', 'over' => $over,
        ];
    }
}
