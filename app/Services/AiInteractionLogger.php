<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AiInteractionRepositoryInterface;
use App\DTOs\AiRequest;
use App\DTOs\AiResponse;
use App\Enums\AiInteractionStatus;
use App\Exceptions\AiProviderException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Audit trail for AI calls: one ai_interactions row per provider call, plus
 * a line on the `ai` log channel. Neither ever contains prompt or output
 * text (ENHANCEMENTS.md §3.2 rules 4–5) — only the feature, model, token
 * counts, latency, outcome and a hash of the prompt.
 */
class AiInteractionLogger
{
    public function __construct(private readonly AiInteractionRepositoryInterface $interactions) {}

    public function completed(AiRequest $request, AiResponse $response, int $latencyMs, ?User $user, ?int $ticketId): void
    {
        $status = $response->refused() ? AiInteractionStatus::Refused : AiInteractionStatus::Succeeded;

        $this->interactions->create(
            feature: $request->feature,
            model: $response->model,
            status: $status,
            latencyMs: $latencyMs,
            promptHash: $request->hash(),
            userId: $user?->id,
            ticketId: $ticketId,
            tokensIn: $response->inputTokens,
            tokensOut: $response->outputTokens,
        );

        Log::channel('ai')->info('ai.call', [
            'feature' => $request->feature->value,
            'model' => $response->model,
            'status' => $status->value,
            'tokens_in' => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
            'latency_ms' => $latencyMs,
            'ticket_id' => $ticketId,
        ]);
    }

    public function failed(AiRequest $request, AiProviderException $e, int $latencyMs, ?User $user, ?int $ticketId): void
    {
        $this->interactions->create(
            feature: $request->feature,
            model: $request->feature->model(),
            status: AiInteractionStatus::Failed,
            latencyMs: $latencyMs,
            promptHash: $request->hash(),
            userId: $user?->id,
            ticketId: $ticketId,
            errorType: $e->errorType,
        );

        Log::channel('ai')->warning('ai.call_failed', [
            'feature' => $request->feature->value,
            'model' => $request->feature->model(),
            'error_type' => $e->errorType,
            'latency_ms' => $latencyMs,
            'ticket_id' => $ticketId,
        ]);
    }

    /** AiGuard refused before calling the provider — no row, nothing was spent. */
    public function blocked(AiRequest $request, string $reason): void
    {
        Log::channel('ai')->info('ai.blocked', [
            'feature' => $request->feature->value,
            'reason' => $reason,
        ]);
    }
}
