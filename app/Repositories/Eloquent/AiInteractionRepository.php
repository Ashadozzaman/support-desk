<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AiInteractionRepositoryInterface;
use App\Enums\AiFeature;
use App\Enums\AiInteractionStatus;
use App\Models\AiInteraction;

class AiInteractionRepository implements AiInteractionRepositoryInterface
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
    ): AiInteraction {
        return AiInteraction::create([
            'feature' => $feature,
            'model' => $model,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'prompt_hash' => $promptHash,
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'error_type' => $errorType,
            'created_at' => now(),
        ]);
    }
}
