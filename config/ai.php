<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI assistant (ENHANCEMENTS.md §3) — off by default
    |--------------------------------------------------------------------------
    |
    | The whole app works with AI disabled and no API key: AiGuard refuses
    | every call and the AI routes 404 (EnsureAiEnabled). `provider` picks the
    | AiClient implementation (RepositoryServiceProvider); anything other than
    | "anthropic" — or AI disabled — binds the deterministic FakeAiClient.
    */
    'enabled' => (bool) env('AI_ENABLED', false),

    'provider' => env('AI_PROVIDER', 'fake'),

    // One model for every feature unless a feature has its own override
    // (AiFeature::model()). Empty override = use the default.
    'model' => env('AI_MODEL', 'claude-opus-5-5'),
    'models' => [
        'ticket_triage' => env('AI_MODEL_TRIAGE'),
        'reply_draft' => env('AI_MODEL_REPLY_DRAFT'),
        'ticket_summary' => env('AI_MODEL_SUMMARY'),
        'support_chat' => env('AI_MODEL_CHAT'),
    ],

    // Thinking counts towards max_tokens on current models, so this is not a
    // "length of the answer" setting — too low and answers are cut off.
    'max_tokens' => (int) env('AI_MAX_TOKENS', 4096),
    'effort' => env('AI_EFFORT', 'medium'),

    // Per HTTP attempt; with retries a call can take up to timeout × (retries + 1).
    'timeout' => (int) env('AI_TIMEOUT', 15),
    'retries' => (int) env('AI_RETRIES', 2),

    // Soft cap on input + output tokens per business-timezone day, across all
    // users and features. Checked before each call, so one call may overshoot.
    'daily_token_budget' => (int) env('AI_DAILY_TOKEN_BUDGET', 200000),

    'rate_limits' => [
        'per_user_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 10),
        'per_user_per_day' => (int) env('AI_RATE_LIMIT_PER_DAY', 200),
    ],

    // After `failures` provider errors in a row, stop calling for `cooldown_seconds`.
    'circuit_breaker' => [
        'failures' => (int) env('AI_CIRCUIT_FAILURES', 5),
        'cooldown_seconds' => (int) env('AI_CIRCUIT_COOLDOWN_SECONDS', 300),
    ],

    // Replace emails, phone numbers and long digit runs before text leaves the app.
    'redact_pii' => (bool) env('AI_REDACT_PII', true),

    // ai_interactions rows (metadata only, never prompt text) are deleted
    // after this many days by the daily `model:prune`.
    'retention_days' => (int) env('AI_RETENTION_DAYS', 90),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',

        // Server-side refusal fallback: if the model declines on a safety
        // classifier, the API retries the same request on another model
        // within the same call. Claude Haiku models reject the parameter,
        // so AnthropicClient never sends it for them.
        'refusal_fallback' => (bool) env('AI_REFUSAL_FALLBACK', true),
    ],

];
