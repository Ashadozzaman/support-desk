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
use Throwable;

/**
 * Claude Messages API over plain Laravel Http (POST /v1/messages). No SDK:
 * the app needs one endpoint, and the rest of the code only sees AiClient.
 *
 * Current models think before answering (adaptive thinking, on by
 * default); the response then holds `thinking` blocks before the `text`
 * blocks, and only the text is returned.
 */
class AnthropicClient implements AiClient
{
    /** Rate limited, overloaded, or a server-side error: worth another attempt. */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    public function complete(AiRequest $request): AiResponse
    {
        $apiKey = (string) config('ai.anthropic.api_key');

        if ($apiKey === '') {
            throw AiProviderException::notConfigured();
        }

        $model = $request->feature->model();

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('ai.anthropic.version'),
        ];

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens ?? (int) config('ai.max_tokens'),
            'system' => $request->system,
            'messages' => $request->messages,
            'output_config' => ['effort' => (string) config('ai.effort')],
        ];

        if ($this->usesRefusalFallback($model)) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        try {
            $response = Http::baseUrl((string) config('ai.anthropic.base_url'))
                ->withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('ai.timeout'))
                ->retry((int) config('ai.retries') + 1, 500, fn (Throwable $e): bool => $this->isRetryable($e), throw: false)
                ->post('/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw AiProviderException::connection($e);
        }

        if ($response->failed()) {
            $type = $response->json('error.type');

            throw AiProviderException::http($response->status(), is_string($type) ? $type : null);
        }

        $content = $response->json('content');
        $stopReason = $response->json('stop_reason');

        if (! is_array($content) || ! is_string($stopReason)) {
            throw AiProviderException::invalidResponse();
        }

        $text = collect($content)
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text')
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->implode("\n");

        return new AiResponse(
            text: trim($text),
            model: (string) ($response->json('model') ?? $model),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            stopReason: $stopReason,
        );
    }

    /** Claude Haiku models reject the `fallbacks` parameter. */
    private function usesRefusalFallback(string $model): bool
    {
        return (bool) config('ai.anthropic.refusal_fallback') && ! str_starts_with($model, 'claude-haiku');
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && in_array($e->response->status(), self::RETRYABLE_STATUSES, true);
    }
}
