<?php

declare(strict_types=1);

namespace Chicken\Ranking;

use Chicken\Support\AtomicFile;

/** Fichier JSON du classement multijoueur (multi_scores.json), écrit par le serveur de jeu et lu par le site. */
final readonly class RankingStore
{
    public function __construct(public string $file)
    {
    }

    /** Charge classement et rencontres ; vides si le fichier est absent ou illisible. */
    public function load(): RankingSnapshot
    {
        $data = AtomicFile::readJson($this->file);
        if (!is_array($data)) {
            return new RankingSnapshot();
        }

        return new RankingSnapshot(Ranking::fromArray($data['players'] ?? null), PairLimiter::fromArray($data['pairs'] ?? null));
    }

    /** Écriture atomique : un lecteur ne voit jamais un fichier tronqué. */
    public function save(RankingSnapshot $snapshot): bool
    {
        $json = json_encode([
            'version' => 1,
            'players' => (object) $snapshot->ranking->toArray(),
            'pairs' => (object) $snapshot->pairs->toArray(),
        ], JSON_UNESCAPED_UNICODE);

        return $json !== false && AtomicFile::write($this->file, $json);
    }
}
