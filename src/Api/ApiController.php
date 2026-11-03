<?php

declare(strict_types=1);

namespace Chicken\Api;

use Chicken\Api\Action\ApiAction;
use Chicken\Api\Action\RecordScore;
use Chicken\Api\Action\StartGame;
use Chicken\Ranking\RankingStore;
use Psr\Clock\ClockInterface;

/**
 *   GET                 → top 20 contre l'IA
 *   GET  ?board=multi   → top 20 multijoueur (écrit par le serveur de jeu)
 *   POST {action:"start", token, level}   → ticket de partie signé
 *   POST {ticket, name, me, opp}          → enregistre une partie contre l'IA
 */
final readonly class ApiController
{
    public function __construct(
        private string $dataDir,
        private ApiConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return match ($request->method) {
                'GET' => $this->board($request),
                'POST' => $this->write($request),
                default => throw new ApiError(405, ['error' => 'method']),
            };
        } catch (ApiError $error) {
            return $error->toResponse();
        }
    }

    private function board(Request $request): Response
    {
        if (($request->query['board'] ?? '') === 'multi') {
            $store = new RankingStore("{$this->dataDir}/multi_scores.json");

            return new Response(200, array_map(static fn ($p): array => $p->toPublicArray(), $store->load()->ranking->top()));
        }
        $scores = new ScoreFile("{$this->dataDir}/scores.json");

        return new Response(200, array_map(static fn (ScoreRow $r): array => $r->toPublicArray(), $scores->load()->top()));
    }

    private function write(Request $request): Response
    {
        $input = json_decode($request->body, true);
        if (!is_array($input)) {
            throw new ApiError(400, ['error' => 'bad json']);
        }
        // Tout ce qui touche à l'état anti-abus (secret, compteurs, tickets utilisés) est sérialisé par un verrou unique.
        $lock = @fopen("{$this->dataDir}/api.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw ApiError::storage();
        }
        try {
            $abuseStore = new AbuseStore("{$this->dataDir}/abuse.json");
            $context = new RequestContext(
                (int) $this->clock->now()->format('U'),
                ClientIp::resolve($request->server),
                $this->config,
                new TicketSigner((new SecretStore("{$this->dataDir}/secret.key", $this->config->secret))->get()),
                $abuseStore->load(),
                $abuseStore,
                new ScoreFile("{$this->dataDir}/scores.json"),
            );

            return $this->action($input)->execute($input, $context);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $input */
    private function action(array $input): ApiAction
    {
        return ($input['action'] ?? '') === 'start' ? new StartGame() : new RecordScore();
    }
}
