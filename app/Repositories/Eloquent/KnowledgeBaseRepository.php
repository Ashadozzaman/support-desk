<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\KnowledgeBaseRepositoryInterface;
use App\DTOs\KbArticleData;
use App\Models\KbArticle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * FULLTEXT in NATURAL LANGUAGE MODE (Laravel's default for whereFullText),
 * not boolean mode like the ticket search: the input here is a sentence a
 * customer typed, and characters such as + - * " ( ) would be operators in
 * boolean mode. Natural-language mode treats them as plain text and ranks
 * by relevance.
 */
class KnowledgeBaseRepository implements KnowledgeBaseRepositoryInterface
{
    private const LIST_COLUMNS = ['id', 'title', 'slug', 'is_published', 'category_id', 'updated_at'];

    public function paginatePublished(?string $query, int $perPage = 15): LengthAwarePaginator
    {
        return $this->listQuery()
            ->published()
            ->when(
                $query,
                fn (Builder $q, string $term) => $q->whereFullText(['title', 'body'], $term)
                    ->orderByRaw('MATCH(title, body) AGAINST (?) DESC', [$term]),
                fn (Builder $q) => $q->orderBy('title'),
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateAll(int $perPage = 20): LengthAwarePaginator
    {
        return $this->listQuery()
            ->latest('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function search(string $query, int $limit = 5): Collection
    {
        return KbArticle::query()
            ->published()
            ->select(['id', 'title', 'slug', 'body', 'updated_at'])
            ->selectRaw('MATCH(title, body) AGAINST (?) AS relevance', [$query])
            ->whereFullText(['title', 'body'], $query)
            ->orderByDesc('relevance')
            ->limit($limit)
            ->get();
    }

    public function loadDetails(KbArticle $article): KbArticle
    {
        return $article->loadMissing('category:id,name');
    }

    public function create(KbArticleData $data): KbArticle
    {
        return KbArticle::create($this->attributes($data));
    }

    public function update(KbArticle $article, KbArticleData $data): KbArticle
    {
        $article->update($this->attributes($data));

        return $article;
    }

    public function delete(KbArticle $article): void
    {
        $article->delete();
    }

    /**
     * @return Builder<KbArticle>
     */
    private function listQuery(): Builder
    {
        return KbArticle::query()
            ->select(self::LIST_COLUMNS)
            ->selectRaw('LEFT(body, 240) AS excerpt')
            ->with('category:id,name');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(KbArticleData $data): array
    {
        return [
            'title' => $data->title,
            'slug' => $data->slug,
            'body' => $data->body,
            'is_published' => $data->isPublished,
            'category_id' => $data->categoryId,
        ];
    }
}
