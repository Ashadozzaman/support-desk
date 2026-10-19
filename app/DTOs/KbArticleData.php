<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class KbArticleData
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $body,
        public bool $isPublished,
        public ?int $categoryId,
    ) {}
}
