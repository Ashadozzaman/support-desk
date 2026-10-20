<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\DTOs\AiRequest;
use App\DTOs\AiResponse;
use App\Exceptions\AiProviderException;

/**
 * One model call. Implementations only talk to a provider — no rate limits,
 * budgets or logging; those live in AiGuard, which is the only caller.
 * Swapping provider = a new implementation bound in RepositoryServiceProvider.
 */
interface AiClient
{
    /**
     * @throws AiProviderException
     */
    public function complete(AiRequest $request): AiResponse;
}
