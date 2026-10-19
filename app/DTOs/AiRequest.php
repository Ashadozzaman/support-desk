<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AiFeature;

/**
 * Provider-neutral input to AiClient. `system` is our own (trusted) prompt;
 * anything a user wrote goes in `messages`, wrapped with
 * AiGuard::wrapUntrusted() by the feature that builds the request.
 *
 * A message's content is plain text, or — in a tool loop — the content
 * blocks the Messages API defines (an assistant turn's blocks sent back
 * unchanged, `tool_result` blocks in the next user turn).
 */
readonly class AiRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools  read-only lookups the model may ask for
     */
    public function __construct(
        public AiFeature $feature,
        public string $system,
        public array $messages,
        public ?int $maxTokens = null,
        public array $tools = [],
    ) {}

    /**
     * @param  list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}>  $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->feature, $this->system, $messages, $this->maxTokens, $this->tools);
    }

    /** Lets ai_interactions group identical prompts without storing them. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode([$this->feature->value, $this->system, $this->messages]));
    }
}
