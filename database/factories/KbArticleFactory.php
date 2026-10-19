<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KbArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KbArticle>
 */
class KbArticleFactory extends Factory
{
    protected $model = KbArticle::class;

    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(4, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'body' => fake()->paragraphs(3, true),
            'is_published' => true,
            'category_id' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_published' => false]);
    }
}
