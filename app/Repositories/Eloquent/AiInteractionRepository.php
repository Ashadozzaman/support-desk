<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AiInteractionRepositoryInterface;
use App\Enums\AiFeature;
use App\Enums\AiInteractionStatus;
use App\Models\AiInteraction;
use Carbon\CarbonImmutable;

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

    public function usageBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return AiInteraction::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS calls, SUM(tokens_in + tokens_out) AS tokens, AVG(latency_ms) AS avg_latency_ms')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->status => [
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'avg_latency_ms' => (int) round((float) $row->avg_latency_ms),
            ]])
            ->all();
    }
}
