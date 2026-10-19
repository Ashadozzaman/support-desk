<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\KbArticleData;
use App\Models\KbArticle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface KnowledgeBaseRepositoryInterface
{
    /**
     * Help centre: published only, A–Z, or by relevance when `$query` is
     * given. List columns plus an `excerpt` — never the whole body.
     *
     * @return LengthAwarePaginator<int, KbArticle>
     */
    public function paginatePublished(?string $query, int $perPage = 15): LengthAwarePaginator;

    /**
     * Admin list: drafts included, most recently edited first.
     *
     * @return LengthAwarePaginator<int, KbArticle>
     */
    public function paginateAll(int $perPage = 20): LengthAwarePaginator;

    /**
     * Retrieval for the chatbot (RAG): the top `$limit` published articles
     * for a free-text question, best first, each with a `relevance` score
     * (MySQL FULLTEXT natural-language relevance; 0 = no match is never returned).
     *
     * @return Collection<int, KbArticle>
     */
    public function search(string $query, int $limit = 5): Collection;

    /** Relations the article page and edit form show. */
    public function loadDetails(KbArticle $article): KbArticle;

    public function create(KbArticleData $data): KbArticle;

    public function update(KbArticle $article, KbArticleData $data): KbArticle;

    public function delete(KbArticle $article): void;
}
