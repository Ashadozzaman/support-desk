<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Contracts\Ai\AiClient;
use App\DTOs\AiRequest;
use App\DTOs\AiResponse;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * OpenAI Chat Completions over plain Laravel Http (POST /v1/chat/completions),
 * selected with AI_PROVIDER=openai. Same contract as AnthropicClient, so no
 * feature code knows which provider answers.
 *
 * The app's messages use the provider-neutral shape AiRequest documents
 * (tool calls as `tool_use` blocks in an assistant turn, results as
 * `tool_result` blocks in the next user turn). This class translates that to
 * OpenAI's shape — `tool_calls` on the assistant message, one `role: tool`
 * message per result — and translates the response back the same way, so
 * DataAssistantService's tool loop works unchanged.
 */
class OpenAiClient implements AiClient
{
    /** Rate limited or a server-side error: worth another attempt. */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    /** OpenAI finish_reason → the stop reasons the app understands (AiResponse). */
    private const STOP_REASONS = [
        'stop' => 'end_turn',
        'tool_calls' => 'tool_use',
        'length' => 'max_tokens',
        'content_filter' => 'refusal',
    ];

    public function complete(AiRequest $request): AiResponse
    {
        $apiKey = (string) config('ai.openai.api_key');

        if ($apiKey === '') {
            throw AiProviderException::notConfigured();
        }

        $model = $request->feature->model();

        $body = [
            'model' => $model,
            'max_completion_tokens' => $request->maxTokens ?? (int) config('ai.max_tokens'),
            'messages' => $this->messages($request),
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(fn (array $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ], $request->tools);
        }

        try {
            $response = Http::baseUrl((string) config('ai.openai.base_url'))
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('ai.timeout'))
                ->retry((int) config('ai.retries') + 1, 500, fn (Throwable $e): bool => $this->isRetryable($e), throw: false)
                ->post('/v1/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw AiProviderException::connection($e);
        }

        if ($response->failed()) {
            $code = $response->json('error.code') ?? $response->json('error.type');

            throw AiProviderException::http($response->status(), is_string($code) ? $code : null);
        }

        $message = $response->json('choices.0.message');
        $finishReason = $response->json('choices.0.finish_reason');

        if (! is_array($message) || ! is_string($finishReason)) {
            throw AiProviderException::invalidResponse();
        }

        $text = trim((string) ($message['content'] ?? ''));

        $toolCalls = array_map(fn (array $call): array => [
            'id' => (string) ($call['id'] ?? ''),
            'name' => (string) ($call['function']['name'] ?? ''),
            // Arguments arrive as a JSON string; anything unreadable becomes
            // {} and fails the lookup's own validation instead of crashing.
            'input' => is_array($input = json_decode((string) ($call['function']['arguments'] ?? ''), true)) ? $input : [],
        ], array_values(array_filter((array) ($message['tool_calls'] ?? []), 'is_array')));

        // The same block shape AnthropicClient returns, so the tool loop can
        // send this turn back and messages() can translate it again.
        $content = [
            ...($text !== '' ? [['type' => 'text', 'text' => $text]] : []),
            ...array_map(fn (array $call): array => ['type' => 'tool_use', ...$call], $toolCalls),
        ];

        return new AiResponse(
            text: $text,
            model: (string) ($response->json('model') ?? $model),
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            outputTokens: (int) $response->json('usage.completion_tokens', 0),
            stopReason: ($message['refusal'] ?? null) !== null ? 'refusal' : (self::STOP_REASONS[$finishReason] ?? $finishReason),
            toolCalls: $toolCalls,
            content: $content,
        );
    }

    /**
     * AiRequest messages → OpenAI messages. Our system prompt becomes the
     * first `system` message (OpenAI has no separate system field).
     *
     * @return list<array<string, mixed>>
     */
    private function messages(AiRequest $request): array
    {
        $messages = [['role' => 'system', 'content' => $request->system]];

        foreach ($request->messages as $message) {
            if (is_string($message['content'])) {
                $messages[] = ['role' => $message['role'], 'content' => $message['content']];

                continue;
            }

            $blocks = $message['content'];

            if ($message['role'] === 'assistant') {
                $text = implode("\n", array_column(array_filter($blocks, fn (array $b) => $b['type'] === 'text'), 'text'));
                $calls = array_values(array_filter($blocks, fn (array $b) => $b['type'] === 'tool_use'));

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $text !== '' ? $text : null,
                    ...($calls === [] ? [] : ['tool_calls' => array_map(fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        // An empty input must still encode as an object, not [].
                        'function' => ['name' => $call['name'], 'arguments' => (string) json_encode($call['input'] ?: new stdClass)],
                    ], $calls)]),
                ];

                continue;
            }

            foreach ($blocks as $block) {
                $messages[] = match ($block['type']) {
                    'tool_result' => [
                        'role' => 'tool',
                        'tool_call_id' => $block['tool_use_id'],
                        // OpenAI has no is_error flag on tool messages.
                        'content' => (($block['is_error'] ?? false) ? 'ERROR: ' : '').$block['content'],
                    ],
                    default => ['role' => 'user', 'content' => (string) ($block['text'] ?? '')],
                };
            }
        }

        return $messages;
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! $e instanceof RequestException) {
            return false;
        }

        // A 429 for an empty balance won't fix itself by retrying.
        return in_array($e->response->status(), self::RETRYABLE_STATUSES, true)
            && $e->response->json('error.code') !== 'insufficient_quota';
    }
}
