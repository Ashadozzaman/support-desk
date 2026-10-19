<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\AiFeature;
use App\Enums\AiInteractionStatus;
use App\Models\AiInteraction;

interface AiInteractionRepositoryInterface
{
    public function create(
        AiFeature $feature,
        string $model,
        AiInteractionStatus $status,
        int $latencyMs,
        string $promptHash,
        ?int $userId,
        ?int $ticketId,
        int $tokensIn = 0,
        int $tokensOut = 0,
        ?string $errorType = null,
    ): AiInteraction;
}
