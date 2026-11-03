<?php

declare(strict_types=1);

namespace Chicken\Api\Action;

use Chicken\Api\ApiError;
use Chicken\Api\RequestContext;
use Chicken\Api\Response;
use Chicken\Ranking\Token;

/** Début de partie : délivre un ticket signé à renvoyer avec le score. */
final readonly class StartGame implements ApiAction
{
    #[\Override]
    public function execute(array $input, RequestContext $context): Response
    {
        $token = $input['token'] ?? null;
        if (!Token::isValid($token)) {
            throw new ApiError(400, ['error' => 'token']);
        }
        $level = (int) ($input['level'] ?? 1);
        if ($level < 0 || $level > 2) {
            throw new ApiError(400, ['error' => 'level']);
        }
        $hash = Token::hash($token);
        $context->enforceRate('tickets', $hash, $context->config->ticketsPerWindow);
        $context->persistAbuse();

        return new Response(200, ['ticket' => $context->signer->issue($hash, $level, $context->now)]);
    }
}
