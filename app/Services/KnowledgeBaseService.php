<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\KnowledgeBaseRepositoryInterface;
use App\DTOs\KbArticleData;
use App\Models\Category;
use App\Models\KbArticle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Help-centre articles (Step 22): admin CRUD, the help-centre list, and the
 * retrieval the support chatbot answers from. Each write is a single row,
 * so no transaction is needed; the slug's uniqueness is validated in
 * KbArticleRequest and backed by the UNIQUE index.
 */
class KnowledgeBaseService
{
    // MySQL's default innodb_ft_min_token_size: shorter words are never indexed.
    private const MIN_SEARCH_LENGTH = 3;

    public function __construct(
        private readonly KnowledgeBaseRepositoryInterface $articles,
        private readonly TicketService $tickets,
    ) {}

    /**
     * @return LengthAwarePaginator<int, KbArticle>
     */
    public function helpCentre(?string $query): LengthAwarePaginator
    {
        return $this->articles->paginatePublished($this->searchable($query));
    }

    /**
     * @return LengthAwarePaginator<int, KbArticle>
     */
    public function adminList(): LengthAwarePaginator
    {
        return $this->articles->paginateAll();
    }

    /**
     * Top published articles for a question — the "R" in the chatbot's RAG.
     *
     * @return Collection<int, KbArticle>
     */
    public function relevantArticles(string $question, int $limit = 5): Collection
    {
        $query = $this->searchable($question);

        return $query === null ? new Collection : $this->articles->search($query, $limit);
    }

    public function forDisplay(KbArticle $article): KbArticle
    {
        return $this->articles->loadDetails($article);
    }

    public function create(KbArticleData $data): KbArticle
    {
        return $this->articles->create($data);
    }

    public function update(KbArticle $article, KbArticleData $data): KbArticle
    {
        return $this->articles->update($article, $data);
    }

    public function delete(KbArticle $article): void
    {
        $this->articles->delete($article);
    }

    /**
     * @return Collection<int, Category>
     */
    public function categoryOptions(): Collection
    {
        return $this->tickets->categoryOptions();
    }

    private function searchable(?string $query): ?string
    {
        $query = trim((string) $query);

        return mb_strlen($query) >= self::MIN_SEARCH_LENGTH ? $query : null;
    }
}
