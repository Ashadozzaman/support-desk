<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class AiResponse
{
    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls  lookups the model asked for
     * @param  list<array<string, mixed>>  $content  the raw content blocks, to send back unchanged in a tool loop
     */
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $stopReason,
        public array $toolCalls = [],
        public array $content = [],
    ) {}

    /** The model declined to answer; `text` is empty or partial and must not be shown. */
    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
