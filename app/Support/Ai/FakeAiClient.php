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

    /** @var list<array{name: string, input: array<string, mixed>}> */
    private array $toolCallQueue = [];

    /** Tests: make the next call return this text. */
    public function respondWith(string $text): self
    {
        $this->nextText = $text;

        return $this;
    }

    /**
     * Tests: make the next call ask for this lookup instead of answering
     * (queue several for several rounds of a tool loop).
     *
     * @param  array<string, mixed>  $input
     */
    public function callTool(string $name, array $input = []): self
    {
        $this->toolCallQueue[] = ['name' => $name, 'input' => $input];

        return $this;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        $toolCall = array_shift($this->toolCallQueue);

        if ($toolCall !== null) {
            $id = 'toolu_fake_'.count($this->requests);
            $block = ['type' => 'tool_use', 'id' => $id, 'name' => $toolCall['name'], 'input' => $toolCall['input']];

            return new AiResponse('', 'fake', 10, 10, 'tool_use', [['id' => $id, ...$toolCall]], [$block]);
        }

        $text = $this->nextText ?? "Fake {$request->feature->value} answer.";
        $this->nextText = null;

        $prompt = $request->system.json_encode($request->messages);

        return new AiResponse(
            text: $text,
            model: 'fake',
            inputTokens: intdiv(strlen($prompt), 4) + 1,
            outputTokens: intdiv(strlen($text), 4) + 1,
            stopReason: 'end_turn',
            content: [['type' => 'text', 'text' => $text]],
        );
    }
}
