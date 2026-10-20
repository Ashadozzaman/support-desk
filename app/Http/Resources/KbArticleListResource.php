<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KbArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List row: expects KnowledgeBaseRepository's list columns (`excerpt`
 * instead of the body, `category` loaded).
 *
 * @property-read KbArticle $resource
 *
 * @mixin KbArticle
 */
class KbArticleListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'is_published' => $this->is_published,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'updated_at' => $this->updated_at,
        ];
    }
}
