<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class AiResponse
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $stopReason,
    ) {}

    /** The model declined to answer; `text` is empty or partial and must not be shown. */
    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
