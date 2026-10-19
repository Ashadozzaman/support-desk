<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KbArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Help-centre article (Step 22). Plain text, like replies — rendered
 * escaped, never as HTML. Published articles are what the support chatbot
 * may answer from (ENHANCEMENTS.md §3.1 retrieval).
 */
#[Fillable(['title', 'slug', 'body', 'is_published', 'category_id'])]
class KbArticle extends Model
{
    /** @use HasFactory<KbArticleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @param  Builder<KbArticle>  $query
     * @return Builder<KbArticle>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
