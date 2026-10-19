<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Services\AiGuard;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Is the AI assistant usable? Registered with ->if(ai.enabled), so it is
 * skipped entirely while AI is off. It makes no API call — a health page
 * that is polled every minute would otherwise cost tokens — and reports
 * the app's own view instead: key configured, breaker closed, budget left.
 */
class AiProviderCheck extends Check
{
    public function run(): Result
    {
        $guard = app(AiGuard::class);
        $budget = (int) config('ai.daily_token_budget');
        $used = $guard->tokensUsedToday();

        $result = Result::make()->meta([
            'provider' => config('ai.provider'),
            'model' => config('ai.model'),
            'tokens_used_today' => $used,
            'daily_token_budget' => $budget,
        ]);

        $provider = (string) config('ai.provider');

        if (in_array($provider, ['anthropic', 'openai'], true) && (string) config("ai.{$provider}.api_key") === '') {
            return $result->failed('AI is enabled but '.strtoupper($provider).'_API_KEY is empty.')->shortSummary('No API key');
        }

        if ($guard->circuitIsOpen()) {
            return $result->warning('Provider calls are paused after repeated failures.')->shortSummary('Paused');
        }

        if ($used >= $budget) {
            return $result->warning("Today's token budget is used up.")->shortSummary('Budget used');
        }

        return $result->ok()->shortSummary("{$used} / {$budget} tokens");
    }
}
