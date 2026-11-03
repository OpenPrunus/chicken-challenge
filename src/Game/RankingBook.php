<?php

declare(strict_types=1);

namespace Chicken\Game;

use Chicken\Ranking\PairLimiter;
use Chicken\Ranking\Participant;
use Chicken\Ranking\RankedMatch;
use Chicken\Ranking\Ranking;
use Chicken\Ranking\RankingSnapshot;
use Chicken\Ranking\RankingStore;
use Psr\Log\LoggerInterface;

/** Le classement vivant du serveur : état en mémoire, réécrit dans le fichier après chaque match classé. */
final class RankingBook
{
    public private(set) RankingSnapshot $snapshot;

    public Ranking $ranking {
        get => $this->snapshot->ranking;
    }

    public function __construct(private readonly RankingStore $store, private readonly LoggerInterface $logger)
    {
        $this->snapshot = $store->load();
    }

    /**
     * Enregistre un match classé en appliquant l'anti-collusion (gains réduits si ce couple se rencontre trop souvent).
     * Retourne null si le match ne peut pas être classé ; dans ce cas rien n'est modifié.
     */
    public function settle(Participant $winner, Participant $loser, int $goalsWinner, int $goalsLoser, int $now, int $window): ?RankedMatch
    {
        $meeting = $this->snapshot->pairs->meet(PairLimiter::key($winner->tokenHash, $loser->tokenHash), $now, $window);
        $result = $this->ranking->record($winner, $loser, $goalsWinner, $goalsLoser, $meeting->factor);
        if ($result === null) {
            return null;
        }
        $this->snapshot = new RankingSnapshot($result->ranking, $meeting->limiter);
        if (!$this->store->save($this->snapshot)) {
            $this->logger->error('ERROR: cannot write {file}', ['file' => $this->store->file]);
        }

        return $result;
    }
}
