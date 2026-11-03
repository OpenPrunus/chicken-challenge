<?php

declare(strict_types=1);

namespace Chicken\Api;

/** Tout ce dont une action a besoin pendant qu'elle détient le verrou : heure, adresse, secret, état anti-abus, fichiers. */
final readonly class RequestContext
{
    public function __construct(
        public int $now,
        public string $ip,
        public ApiConfig $config,
        public TicketSigner $signer,
        public AbuseState $abuse,
        public AbuseStore $abuseStore,
        public ScoreFile $scores,
    ) {
    }

    /**
     * Applique la limite de débit par adresse IP puis par jeton.
     *
     * @throws ApiError 429 si l'une des deux est dépassée
     */
    public function enforceRate(string $kind, string $tokenHash, int $limit): void
    {
        foreach (["$kind:ip:{$this->ip}", "$kind:tok:$tokenHash"] as $key) {
            $decision = $this->abuse->rate->attempt($key, $this->now, $limit, $this->config->rateWindow);
            if (!$decision->allowed) {
                throw new ApiError(429, ['error' => 'rate_limited', 'retry_after' => $decision->retryAfter]);
            }
        }
    }

    /** @throws ApiError 500 si l'état ne peut pas être écrit */
    public function persistAbuse(): void
    {
        if (!$this->abuseStore->save($this->abuse)) {
            throw ApiError::storage();
        }
    }
}
