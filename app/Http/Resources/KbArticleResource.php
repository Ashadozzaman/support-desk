<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KbArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full article (help-centre page, admin edit form). Expects `category` loaded.
 *
 * @property-read KbArticle $resource
 *
 * @mixin KbArticle
 */
class KbArticleResource extends JsonResource
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
            'body' => $this->body,
            'is_published' => $this->is_published,
            'category_id' => $this->category_id,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'updated_at' => $this->updated_at,
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}
