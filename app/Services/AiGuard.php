<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Ai\AiClient;
use App\DTOs\AiRequest;
use App\DTOs\AiResponse;
use App\Exceptions\AiProviderException;
use App\Exceptions\AiUnavailableException;
use App\Models\User;
use App\Support\Ai\PiiRedactor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The only way the app calls a model (ENHANCEMENTS.md §3.2). Every AI
 * feature goes through complete(), so the safeguards can't be skipped:
 *
 *  1. feature flag (`ai.enabled`)
 *  2. circuit breaker — after N provider failures in a row, stop calling for a while
 *  3. per-user rate limits (per minute and per day, across all features)
 *  4. daily token budget for the whole app
 *  5. PII redaction of the messages (our own system prompt is left as is)
 *  6. audit row for every call that reached the provider
 *
 * Checks 1–4 throw AiUnavailableException before any HTTP request, so the
 * caller can fall back to the non-AI path.
 */
class AiGuard
{
    private const CIRCUIT_OPEN_KEY = 'ai:circuit:open';

    private const FAILURES_KEY = 'ai:circuit:failures';

    public function __construct(
        private readonly AiClient $client,
        private readonly AiInteractionLogger $logger,
        private readonly PiiRedactor $redactor,
        private readonly RateLimiter $limiter,
        private readonly Cache $cache,
    ) {}

    /**
     * @throws AiUnavailableException when a safeguard refuses the call
     * @throws AiProviderException when the provider call fails
     */
    public function complete(AiRequest $request, ?User $user = null, ?int $ticketId = null): AiResponse
    {
        try {
            $this->assertAllowed($user);
        } catch (AiUnavailableException $e) {
            $this->logger->blocked($request, $e->reason);

            throw $e;
        }

        if (config('ai.redact_pii')) {
            $request = $request->withMessages(array_map($this->redactMessage(...), $request->messages));
        }

        $started = hrtime(true);

        try {
            $response = $this->client->complete($request);
        } catch (AiProviderException $e) {
            // A missing key never left the app: not a provider outage, so it
            // doesn't pause calls (which would hide the "key missing" reason).
            if ($e->problem() !== AiProviderException::MISSING_KEY) {
                $this->recordFailure();
            }

            $this->logger->failed($request, $e, $this->elapsedMs($started), $user, $ticketId);

            throw $e;
        }

        // A refusal is still a working provider: it resets the breaker too.
        $this->cache->forget(self::FAILURES_KEY);
        $this->addTokens($response->totalTokens());
        $this->logger->completed($request, $response, $this->elapsedMs($started), $user, $ticketId);

        return $response;
    }

    /**
     * Ticket/chat text is untrusted data (§3.2 rule 3): the system prompt
     * tells the model to treat everything inside <tag>…</tag> as data and
     * never follow instructions in it. Any copy of the tag inside the text
     * is removed so the text can't close the wrapper early and "escape".
     */
    public function wrapUntrusted(string $tag, string $text): string
    {
        $clean = (string) preg_replace('/<\s*\/?\s*'.preg_quote($tag, '/').'\b[^>]*>/i', '', $text);

        return "<{$tag}>\n{$clean}\n</{$tag}>";
    }

    /**
     * Only the user side is redacted: customer text and tool results (data
     * from our DB). Assistant turns are sent back exactly as the model wrote
     * them — their thinking blocks are signed and must not change.
     *
     * @param  array{role: string, content: string|list<array<string, mixed>>}  $message
     * @return array{role: string, content: string|list<array<string, mixed>>}
     */
    private function redactMessage(array $message): array
    {
        if ($message['role'] !== 'user') {
            return $message;
        }

        if (is_string($message['content'])) {
            return [...$message, 'content' => $this->redactor->redact($message['content'])];
        }

        $message['content'] = array_map(function (array $block): array {
            if (($block['type'] ?? null) === 'text') {
                $block['text'] = $this->redactor->redact((string) $block['text']);
            } elseif (($block['type'] ?? null) === 'tool_result' && is_string($block['content'] ?? null)) {
                $block['content'] = $this->redactor->redact($block['content']);
            }

            return $block;
        }, $message['content']);

        return $message;
    }

    public function circuitIsOpen(): bool
    {
        return $this->cache->has(self::CIRCUIT_OPEN_KEY);
    }

    public function tokensUsedToday(): int
    {
        return (int) $this->cache->get($this->budgetKey(), 0);
    }

    private function assertAllowed(?User $user): void
    {
        if (! config('ai.enabled')) {
            throw AiUnavailableException::disabled();
        }

        if ($this->circuitIsOpen()) {
            throw AiUnavailableException::circuitOpen();
        }

        if ($user !== null) {
            $this->hitRateLimits($user);
        }

        if ($this->tokensUsedToday() >= (int) config('ai.daily_token_budget')) {
            throw AiUnavailableException::budgetExceeded();
        }
    }

    private function hitRateLimits(User $user): void
    {
        $limits = [
            "ai:user:{$user->id}:minute" => [(int) config('ai.rate_limits.per_user_per_minute'), 60],
            "ai:user:{$user->id}:day" => [(int) config('ai.rate_limits.per_user_per_day'), 86400],
        ];

        foreach ($limits as $key => [$max]) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw AiUnavailableException::rateLimited();
            }
        }

        foreach ($limits as $key => [, $decaySeconds]) {
            $this->limiter->hit($key, $decaySeconds);
        }
    }

    /**
     * The breaker opens after N failures *in a row* (any success clears the
     * count) and closes by itself when the open-flag expires; the next call
     * after that is a real attempt again.
     */
    private function recordFailure(): void
    {
        $cooldown = (int) config('ai.circuit_breaker.cooldown_seconds');

        $this->cache->add(self::FAILURES_KEY, 0, $cooldown * 2);
        $failures = (int) $this->cache->increment(self::FAILURES_KEY);

        if ($failures >= (int) config('ai.circuit_breaker.failures')) {
            $this->cache->put(self::CIRCUIT_OPEN_KEY, true, $cooldown);
            $this->cache->forget(self::FAILURES_KEY);
        }
    }

    private function addTokens(int $tokens): void
    {
        $key = $this->budgetKey();

        // add() only sets the key if missing, so increment() never starts
        // from a key without an expiry.
        $this->cache->add($key, 0, now()->addDays(2));
        $this->cache->increment($key, $tokens);
    }

    /** The budget resets at midnight in the business timezone, like every other "day" in the app. */
    private function budgetKey(): string
    {
        return 'ai:tokens:'.now()->setTimezone((string) config('support.sla.timezone'))->toDateString();
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
