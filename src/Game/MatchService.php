<?php

declare(strict_types=1);

namespace Chicken\Game;

use Chicken\Physics\CatchArbiter;
use Chicken\Physics\ThrowValidator;
use Chicken\Ranking\Participant;
use Chicken\Support\Num;
use Chicken\Support\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/** Cycle de vie des matchs : création, buts, abandons, classement. Le comportement propre à chaque état est dans Phase\. */
final class MatchService
{
    /** @var array<int, GameMatch> */
    private array $matches = [];
    private int $lastMatchId = 0;

    public function __construct(
        public readonly HubConfig $config,
        public readonly CatchArbiter $catchArbiter,
        public readonly ThrowValidator $throwValidator,
        public readonly LoggerInterface $logger,
        private readonly Lobby $lobby,
        private readonly Invitations $invitations,
        private readonly RankingBook $book,
        private readonly ClockInterface $clock,
        private readonly Randomizer $randomizer,
    ) {
    }

    public function now(): float
    {
        return SystemClock::seconds($this->clock);
    }

    /** @param array<string, mixed> $data */
    public function send(int $clientId, Event $event, array $data = []): void
    {
        $this->lobby->get($clientId)?->send($event, $data);
    }

    public function start(Client $a, Client $b, ?int $first = null): GameMatch
    {
        $first ??= $this->randomizer->getInt(0, 1) === 1 ? $a->id : $b->id;
        $ranking = $this->book->ranking;
        // classé si les deux joueurs ont un jeton, différent, et que leurs pseudos ne sont pas réservés par un autre
        $ranked = $a->tokenHash !== '' && $b->tokenHash !== '' && $a->tokenHash !== $b->tokenHash
            && !$ranking->isReservedByOther((string) $a->name, $a->tokenHash)
            && !$ranking->isReservedByOther((string) $b->name, $b->tokenHash);

        $match = new GameMatch(++$this->lastMatchId, $a->id, $b->id, $first, $ranked, $this->now());
        $this->matches[$match->id] = $match;
        foreach ([$a, $b] as $client) {
            $client->matchId = $match->id;
            $client->status = ClientStatus::InMatch;
            $this->invitations->forget($client->id);
        }
        foreach ([[$a, $b], [$b, $a]] as [$me, $opponent]) {
            $me->send(Event::Match, [
                'opp' => ['name' => $opponent->name, 'kind' => $opponent->kind], 'serve' => $first === $me->id, 'ranked' => $ranked,
            ]);
        }
        $this->logger->info('Match #{id} : {a} vs {b}', ['id' => $match->id, 'a' => $a->name, 'b' => $b->name]);
        $this->lobby->broadcast();

        return $match;
    }

    /** Revanche : même couple, le service passe à celui qui n'a pas marqué en dernier. */
    public function restart(GameMatch $finished): void
    {
        unset($this->matches[$finished->id]);
        $a = $this->lobby->get($finished->a);
        $b = $this->lobby->get($finished->b);
        if ($a !== null && $b !== null) {
            $this->start($a, $b, $finished->rematchServer());
        }
    }

    /** Un joueur quitte son match (ou se déconnecte) : partir en cours de partie vaut défaite. */
    public function leave(Client $client): void
    {
        if ($client->matchId === null) {
            return;
        }
        $match = $this->matches[$client->matchId] ?? null;
        $client->matchId = null;
        if ($client->status === ClientStatus::InMatch) {
            $client->status = ClientStatus::Lobby;
        }
        if ($match !== null) {
            $opponent = $this->lobby->get($match->other($client->id));
            // partir en cours de match = défaite (sinon on pourrait fuir une partie perdue) ; à 0-0, rien n'est enregistré
            if ($match->state !== MatchState::Over && $match->totalPoints >= 1 && $opponent !== null) {
                $this->recordResult($match, $opponent->id, $client->id);
            }
            if ($opponent !== null) {
                $opponent->matchId = null;
                $opponent->status = ClientStatus::Lobby;
                $opponent->send(Event::OpponentLeft);
            }
            unset($this->matches[$match->id]);
        }
        $this->lobby->broadcast();
    }

    /** Un but ; $corrected : le serveur a refusé une capture annoncée par le défenseur. */
    public function goal(GameMatch $match, int $scorer, bool $corrected = false): void
    {
        $over = $match->scoreGoal($scorer, $this->now());
        foreach ($match->players() as $id) {
            $this->send($id, Event::Result, $match->resultFor($id, 'goal', $scorer, $over) + ($corrected ? ['corrected' => true] : []));
        }
        if ($over) {
            $this->logger->info('Match #{id} finished: {a}-{b}', [
                'id' => $match->id, 'a' => $match->score[$match->a], 'b' => $match->score[$match->b],
            ]);
            $winner = $match->winner();
            $this->recordResult($match, $winner, $match->other($winner));
        }
    }

    /**
     * Message d'un joueur en match : la phase courante décide de ce qu'elle accepte.
     *
     * @param array<string, mixed> $msg
     */
    public function handle(Client $client, ClientMessage $type, array $msg): void
    {
        $match = $client->matchId === null ? null : ($this->matches[$client->matchId] ?? null);
        if ($match === null) {
            return;
        }
        $phase = $match->state->phase();
        match ($type) {
            ClientMessage::Throw => $phase->onThrow($this, $match, $client, $msg),
            ClientMessage::Catch => $phase->onCatch($this, $match, $client, $msg),
            ClientMessage::Miss => $phase->onMiss($this, $match, $client),
            ClientMessage::Rematch => $phase->onRematch($this, $match, $client),
            ClientMessage::Pos => $this->relayPosition($match, $client, $msg),
            default => null,
        };
    }

    public function tick(float $now): void
    {
        foreach ($this->matches as $match) {
            $match->state->phase()->onTick($this, $match, $now);
        }
    }

    /** @param array<string, mixed> $msg */
    private function relayPosition(GameMatch $match, Client $client, array $msg): void
    {
        $x = Num::clamped($msg['x'] ?? null, -1, 1);
        if ($x !== null) {
            $this->send($match->other($client->id), Event::Pos, ['x' => $x]);
        }
    }

    /** Enregistre le résultat d'un match classé (une seule fois) et prévient les joueurs encore connectés. */
    private function recordResult(GameMatch $match, int $winnerId, int $loserId): void
    {
        if ($match->recorded || !$match->ranked) {
            return;
        }
        $match->markRecorded();
        // trop rapide pour être crédible (parties « truquées » pour gonfler une note) : on ne classe pas
        $duration = $this->now() - $match->startedAt;
        if ($duration < $this->config->rankMinPointSeconds * $match->totalPoints) {
            $this->logger->warning('Unranked match #{id}: {duration} s for {points} points', [
                'id' => $match->id, 'duration' => sprintf('%.1f', $duration), 'points' => $match->totalPoints,
            ]);
            foreach ([$winnerId, $loserId] as $id) {
                $this->send($id, Event::Unrated, ['reason' => 'too_fast']);
            }

            return;
        }
        $winner = $this->lobby->get($winnerId);
        $loser = $this->lobby->get($loserId);
        if ($winner === null || $loser === null) {
            return;
        }
        $result = $this->book->settle(
            new Participant((string) $winner->name, $winner->tokenHash),
            new Participant((string) $loser->name, $loser->tokenHash),
            $match->score[$winnerId],
            $match->score[$loserId],
            (int) $this->now(),
            $this->config->pairWindow,
        );
        if ($result === null) {
            return;
        }
        $total = count($result->ranking);
        $winner->send(Event::Rated, ['result' => 'win'] + $result->winner->toArray() + ['total' => $total]);
        $loser->send(Event::Rated, ['result' => 'loss'] + $result->loser->toArray() + ['total' => $total]);
        $this->logger->info('Ranked: {w} {wd} ({wr}) / {l} {ld} ({lr})', [
            'w' => $winner->name, 'wd' => $result->winner->delta, 'wr' => $result->winner->rating,
            'l' => $loser->name, 'ld' => $result->loser->delta, 'lr' => $result->loser->rating,
        ]);
    }
}
