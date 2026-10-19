<?php

declare(strict_types=1);

use App\DTOs\AiRequest;
use App\Enums\AiFeature;
use App\Exceptions\AiProviderException;
use App\Support\Ai\AnthropicClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'ai.anthropic.api_key' => 'test-key',
        'ai.anthropic.base_url' => 'https://api.anthropic.test',
        'ai.model' => 'claude-opus-5-5',
        'ai.models.support_chat' => null,
        'ai.max_tokens' => 4096,
        'ai.effort' => 'medium',
        'ai.retries' => 2,
        'ai.anthropic.refusal_fallback' => true,
    ]);

    Http::preventStrayRequests();
    // Retries wait 500 ms between attempts; tests shouldn't.
    Sleep::fake();
});

function chatRequest(): AiRequest
{
    return new AiRequest(
        feature: AiFeature::SupportChat,
        system: 'Answer only from the articles.',
        messages: [['role' => 'user', 'content' => 'How do I reset my password?']],
    );
}

function messagesResponse(array $overrides = []): array
{
    return array_merge([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Use "Forgot your password?"'],
            ['type' => 'text', 'text' => 'on the sign-in page.'],
        ],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
    ], $overrides);
}

test('sends the Messages API request the docs describe', function () {
    Http::fake(['api.anthropic.test/v1/messages' => Http::response(messagesResponse())]);

    (new AnthropicClient)->complete(chatRequest());

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.anthropic.test/v1/messages'
            && $request->method() === 'POST'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
            && $request['model'] === 'claude-opus-5-5'
            && $request['max_tokens'] === 4096
            && $request['system'] === 'Answer only from the articles.'
            && $request['messages'] === [['role' => 'user', 'content' => 'How do I reset my password?']]
            && $request['output_config'] === ['effort' => 'medium']
            && $request['fallbacks'] === 'default';
    });
});

test('maps the response: text blocks only, usage and stop reason', function () {
    Http::fake(['*' => Http::response(messagesResponse())]);

    $response = (new AnthropicClient)->complete(chatRequest());

    expect($response->text)->toBe("Use \"Forgot your password?\"\non the sign-in page.")
        ->and($response->model)->toBe('claude-opus-5-5')
        ->and($response->inputTokens)->toBe(120)
        ->and($response->outputTokens)->toBe(30)
        ->and($response->stopReason)->toBe('end_turn')
        ->and($response->refused())->toBeFalse();
});

test('a refusal comes back as a response, not an error', function () {
    Http::fake(['*' => Http::response(messagesResponse(['content' => [], 'stop_reason' => 'refusal']))]);

    $response = (new AnthropicClient)->complete(chatRequest());

    expect($response->refused())->toBeTrue()->and($response->text)->toBe('');
});

test('a per-feature model override is used, and Haiku gets no fallbacks parameter', function () {
    config(['ai.models.support_chat' => 'claude-haiku-5-5']);
    Http::fake(['*' => Http::response(messagesResponse(['model' => 'claude-haiku-5-5']))]);

    (new AnthropicClient)->complete(chatRequest());

    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-haiku-5-5'
        && ! isset($request['fallbacks'])
        && ! $request->hasHeader('anthropic-beta'));
});

test('without an API key nothing is sent', function () {
    config(['ai.anthropic.api_key' => '']);
    Http::fake();

    expect(fn () => (new AnthropicClient)->complete(chatRequest()))
        ->toThrow(AiProviderException::class, 'No API key');

    Http::assertNothingSent();
});

test('a client error is not retried and keeps the provider error type', function () {
    Http::fake(['*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

    try {
        (new AnthropicClient)->complete(chatRequest());
        $this->fail('Expected AiProviderException');
    } catch (AiProviderException $e) {
        expect($e->errorType)->toBe('authentication_error')
            ->and($e->getMessage())->not->toContain('invalid x-api-key');
    }

    Http::assertSentCount(1);
});

test('overloaded responses are retried, then reported', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529)
        ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529)
        ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529)]);

    expect(fn () => (new AnthropicClient)->complete(chatRequest()))
        ->toThrow(fn (AiProviderException $e) => expect($e->errorType)->toBe('overloaded_error'));

    Http::assertSentCount(3);
});

test('a retry that succeeds returns the answer', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error']], 429)
        ->push(messagesResponse())]);

    expect((new AnthropicClient)->complete(chatRequest())->outputTokens)->toBe(30);

    Http::assertSentCount(2);
});

test('a connection failure becomes a provider exception', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => (new AnthropicClient)->complete(chatRequest()))
        ->toThrow(fn (AiProviderException $e) => expect($e->errorType)->toBe('connection_error'));
});

test('an unreadable response becomes a provider exception', function () {
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new AnthropicClient)->complete(chatRequest()))
        ->toThrow(fn (AiProviderException $e) => expect($e->errorType)->toBe('invalid_response'));
});
