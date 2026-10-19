<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Contracts\Ai\AiClient;
use App\DTOs\AiRequest;
use App\DTOs\AiResponse;

/**
 * Deterministic stand-in, bound whenever AI is disabled or the provider is
 * not "anthropic" — so local dev, CI and reviewers never need an API key.
 * Same request in, same answer out; token counts are a rough length/4.
 */
class FakeAiClient implements AiClient
{
    /** @var list<AiRequest> */
    public array $requests = [];

    private ?string $nextText = null;

    /** Tests: make the next call return this text. */
    public function respondWith(string $text): self
    {
        $this->nextText = $text;

        return $this;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        $text = $this->nextText ?? "Fake {$request->feature->value} answer.";
        $this->nextText = null;

        $prompt = $request->system.implode('', array_column($request->messages, 'content'));

        return new AiResponse(
            text: $text,
            model: 'fake',
            inputTokens: intdiv(strlen($prompt), 4) + 1,
            outputTokens: intdiv(strlen($text), 4) + 1,
            stopReason: 'end_turn',
        );
    }
}
