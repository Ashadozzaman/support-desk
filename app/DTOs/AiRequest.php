<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AiFeature;

/**
 * Provider-neutral input to AiClient. `system` is our own (trusted) prompt;
 * anything a user wrote goes in `messages`, wrapped with
 * AiGuard::wrapUntrusted() by the feature that builds the request.
 */
readonly class AiRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function __construct(
        public AiFeature $feature,
        public string $system,
        public array $messages,
        public ?int $maxTokens = null,
    ) {}

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->feature, $this->system, $messages, $this->maxTokens);
    }

    /** Lets ai_interactions group identical prompts without storing them. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode([$this->feature->value, $this->system, $this->messages]));
    }
}
