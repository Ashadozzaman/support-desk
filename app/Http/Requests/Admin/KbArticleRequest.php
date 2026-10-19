<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\KbArticleData;
use App\Models\KbArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create and edit share one request; on edit the unique slug ignores the article itself. */
class KbArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var KbArticle|null $article */
        $article = $this->route('article');

        return [
            'title' => ['required', 'string', 'max:200'],
            // The URL of the article (/help/{slug}); the form pre-fills it from the title.
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('kb_articles', 'slug')->ignore($article?->id),
            ],
            'body' => ['required', 'string', 'max:20000'],
            'is_published' => ['required', 'boolean'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['slug.regex' => 'Use lowercase letters, digits and single dashes (e.g. reset-your-password).'];
    }

    public function toData(): KbArticleData
    {
        return new KbArticleData(
            title: $this->string('title')->trim()->value(),
            slug: $this->string('slug')->trim()->value(),
            body: $this->string('body')->trim()->value(),
            isPublished: $this->boolean('is_published'),
            categoryId: $this->filled('category_id') ? $this->integer('category_id') : null,
        );
    }
}
