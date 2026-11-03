<?php

declare(strict_types=1);

namespace Chicken\Api\Action;

use Chicken\Api\AiScoreboard;
use Chicken\Api\ApiError;
use Chicken\Api\RequestContext;
use Chicken\Api\Response;
use Chicken\Api\UpdateResult;
use Arcade\Support\Nickname;

/**
 * Fin de partie : enregistre le score. Une partie se déroule dans le navigateur, le serveur ne peut pas la vérifier ; il
 * rend la fraude LENTE, LIMITÉE et NOMINATIVE : ticket signé à usage unique, durée minimale, débit limité par IP et par
 * jeton, pseudo réservé par le premier jeton qui l'utilise.
 */
final readonly class RecordScore implements ApiAction
{
    #[\Override]
    public function execute(array $input, RequestContext $context): Response
    {
        $ticket = $context->signer->verify((string) ($input['ticket'] ?? ''), $context->now);
        if ($ticket === null || $context->abuse->isUsed($ticket->nonce)) {
            throw new ApiError(403, ['error' => 'ticket']);
        }
        $name = Nickname::clean((string) ($input['name'] ?? ''));
        $me = (int) ($input['me'] ?? -1);
        $opp = (int) ($input['opp'] ?? -1);
        if (!AiScoreboard::isValidScore($name, $me, $opp)) {
            throw new ApiError(422, ['error' => 'invalid']);
        }
        if ($context->now - $ticket->issuedAt < $context->config->minimumSeconds($me, $opp)) {
            throw new ApiError(422, ['error' => 'too_fast']);
        }
        $context->enforceRate('records', $ticket->tokenHash, $context->config->recordsPerWindow);

        $result = $context->scores->update(
            static fn (AiScoreboard $board): ?AiScoreboard => $board->isNameTaken($name, $ticket->tokenHash)
                ? null
                : $board->withMatch($name, $me, $opp, $ticket->tokenHash),
        );
        if ($result !== UpdateResult::Saved) {
            $context->persistAbuse();
            throw $result === UpdateResult::Rejected ? new ApiError(403, ['error' => 'name_taken']) : ApiError::storage();
        }
        $context->abuse->markUsed($ticket->nonce, $context->now);                      // le ticket ne resservira plus
        $context->persistAbuse();

        return new Response(200, ['ok' => true]);
    }
}
