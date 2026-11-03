<?php

declare(strict_types=1);

namespace Chicken\Api\Action;

use Chicken\Api\ApiError;
use Chicken\Api\RequestContext;
use Chicken\Api\Response;

/** Une action de l'API en écriture (pattern Command). */
interface ApiAction
{
    /**
     * @param array<string, mixed> $input corps JSON de la requête
     * @throws ApiError
     */
    public function execute(array $input, RequestContext $context): Response;
}
