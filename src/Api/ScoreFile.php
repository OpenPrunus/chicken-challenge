<?php

declare(strict_types=1);

namespace Chicken\Api;

use Arcade\Support\AtomicFile;
use Closure;

/** scores.json : lecture simple pour l'affichage, mise à jour exclusive (verrou) pour l'enregistrement. */
final readonly class ScoreFile
{
    public function __construct(private string $file)
    {
    }

    public function load(): AiScoreboard
    {
        return AiScoreboard::fromArray(AtomicFile::readJson($this->file));
    }

    /**
     * Lit, modifie et réécrit le fichier sous verrou exclusif. $change reçoit le classement et retourne le nouveau
     * (ou null pour refuser la modification).
     *
     * @param Closure(AiScoreboard): ?AiScoreboard $change
     */
    public function update(Closure $change): UpdateResult
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return UpdateResult::Failed;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return UpdateResult::Failed;
            }
            $raw = (string) stream_get_contents($handle);
            $next = $change(AiScoreboard::fromArray(json_validate($raw) ? json_decode($raw, true) : null));
            if ($next === null) {
                return UpdateResult::Rejected;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($next->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);

            return UpdateResult::Saved;
        } finally {
            fclose($handle);
        }
    }
}
