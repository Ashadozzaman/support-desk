<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\AiFeature;
use App\Enums\AiInteractionStatus;
use App\Models\AiInteraction;
use Carbon\CarbonImmutable;

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

    /**
     * Provider calls in [from, to) per status, for the AI report.
     *
     * @return array<string, array{calls: int, tokens: int, avg_latency_ms: int}> status value => totals
     */
    public function usageBetween(CarbonImmutable $from, CarbonImmutable $to): array;
}
