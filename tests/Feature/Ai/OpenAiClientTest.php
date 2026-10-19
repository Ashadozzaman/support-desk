<?php

declare(strict_types=1);

use App\DTOs\AiRequest;
use App\Enums\AiFeature;
use App\Exceptions\AiProviderException;
use App\Support\Ai\OpenAiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'ai.openai.api_key' => 'test-openai-key',
        'ai.openai.base_url' => 'https://api.openai.test',
        'ai.model' => 'gpt-4.1-mini',
        'ai.models.data_assistant' => null,
        'ai.max_tokens' => 4096,
        'ai.retries' => 2,
    ]);

    Http::preventStrayRequests();
    Sleep::fake();
});

function completion(array $message, string $finishReason = 'stop'): array
{
    return [
        'id' => 'chatcmpl-1',
        'model' => 'gpt-4.1-mini-2025-04-14',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'refusal' => null, ...$message], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 12],
    ];
}

function summaryTool(): array
{
    return [['name' => 'current_summary', 'description' => 'Live counts', 'input_schema' => ['type' => 'object', 'properties' => new stdClass]]];
}

test('sends a Chat Completions request: bearer key, system first, tools as functions', function () {
    Http::fake(['api.openai.test/v1/chat/completions' => Http::response(completion(['content' => 'OK']))]);

    (new OpenAiClient)->complete(new AiRequest(AiFeature::DataAssistant, 'You are helpful.', [['role' => 'user', 'content' => 'ping']], null, summaryTool()));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer test-openai-key')
        && $request['model'] === 'gpt-4.1-mini'
        && $request['max_completion_tokens'] === 4096
        && $request['messages'] === [['role' => 'system', 'content' => 'You are helpful.'], ['role' => 'user', 'content' => 'ping']]
        && $request['tools'][0]['type'] === 'function'
        && $request['tools'][0]['function']['name'] === 'current_summary'
        && ! isset($request['output_config'], $request['fallbacks']));
});

test('maps a text answer', function () {
    Http::fake(['*' => Http::response(completion(['content' => 'Twelve open tickets.']))]);

    $response = (new OpenAiClient)->complete(new AiRequest(AiFeature::SupportChat, 'sys', [['role' => 'user', 'content' => 'hi']]));

    expect($response->text)->toBe('Twelve open tickets.')
        ->and($response->stopReason)->toBe('end_turn')
        ->and($response->inputTokens)->toBe(90)
        ->and($response->outputTokens)->toBe(12)
        ->and($response->wantsTools())->toBeFalse()
        ->and($response->content)->toBe([['type' => 'text', 'text' => 'Twelve open tickets.']]);
});

test('maps tool calls to the app\'s tool_use shape', function () {
    Http::fake(['*' => Http::response(completion(['content' => null, 'tool_calls' => [
        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_tickets', 'arguments' => '{"tab":"active","limit":5}']],
        ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'current_summary', 'arguments' => 'not json']],
    ]], 'tool_calls'))]);

    $response = (new OpenAiClient)->complete(new AiRequest(AiFeature::DataAssistant, 'sys', [['role' => 'user', 'content' => 'latest']], null, summaryTool()));

    expect($response->stopReason)->toBe('tool_use')
        ->and($response->toolCalls)->toBe([
            ['id' => 'call_1', 'name' => 'search_tickets', 'input' => ['tab' => 'active', 'limit' => 5]],
            ['id' => 'call_2', 'name' => 'current_summary', 'input' => []],
        ])
        ->and($response->content[0])->toBe(['type' => 'tool_use', 'id' => 'call_1', 'name' => 'search_tickets', 'input' => ['tab' => 'active', 'limit' => 5]]);
});

test('a tool loop turn is translated to tool_calls + role:tool messages', function () {
    Http::fake(['*' => Http::response(completion(['content' => 'Done.']))]);

    (new OpenAiClient)->complete(new AiRequest(AiFeature::DataAssistant, 'sys', [
        ['role' => 'user', 'content' => 'latest'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'current_summary', 'input' => []],
            ['type' => 'tool_use', 'id' => 'call_2', 'name' => 'search_tickets', 'input' => ['tab' => 'active']],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"open":3}'],
            ['type' => 'tool_result', 'tool_use_id' => 'call_2', 'content' => 'Invalid input', 'is_error' => true],
        ]],
    ], null, summaryTool()));

    Http::assertSent(function (Request $request) {
        $messages = $request['messages'];

        return $messages[2] === ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'current_summary', 'arguments' => '{}']],
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'search_tickets', 'arguments' => '{"tab":"active"}']],
        ]]
            && $messages[3] === ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"open":3}']
            && $messages[4] === ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => 'ERROR: Invalid input'];
    });
});

test('a refusal or content filter becomes a refusal', function (array $message, string $finish) {
    Http::fake(['*' => Http::response(completion($message, $finish))]);

    expect((new OpenAiClient)->complete(new AiRequest(AiFeature::SupportChat, 'sys', [['role' => 'user', 'content' => 'x']]))->refused())->toBeTrue();
})->with([
    'refusal field' => [['content' => null, 'refusal' => 'I can\'t help with that.'], 'stop'],
    'content filter' => [['content' => null], 'content_filter'],
]);

test('without a key nothing is sent', function () {
    config(['ai.openai.api_key' => '']);
    Http::fake();

    expect(fn () => (new OpenAiClient)->complete(new AiRequest(AiFeature::SupportChat, 'sys', [['role' => 'user', 'content' => 'x']])))
        ->toThrow(AiProviderException::class);

    Http::assertNothingSent();
});

test('an empty balance is reported at once, not retried', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 429)]);

    expect(fn () => (new OpenAiClient)->complete(new AiRequest(AiFeature::SupportChat, 'sys', [['role' => 'user', 'content' => 'x']])))
        ->toThrow(fn (AiProviderException $e) => expect($e->errorType)->toBe('insufficient_quota'));

    Http::assertSentCount(1);
});

test('rate limits and server errors are retried', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['type' => 'rate_limit_exceeded', 'code' => 'rate_limit_exceeded']], 429)
        ->push(completion(['content' => 'OK']))]);

    expect((new OpenAiClient)->complete(new AiRequest(AiFeature::SupportChat, 'sys', [['role' => 'user', 'content' => 'x']]))->text)->toBe('OK');

    Http::assertSentCount(2);
});
