<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiFeature;
use App\Enums\AiInteractionStatus;
use App\Models\AiInteraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInteraction>
 */
class AiInteractionFactory extends Factory
{
    protected $model = AiInteraction::class;

    public function definition(): array
    {
        return [
            'feature' => AiFeature::SupportChat,
            'model' => 'claude-opus-5-5',
            'user_id' => null,
            'ticket_id' => null,
            'tokens_in' => fake()->numberBetween(100, 2000),
            'tokens_out' => fake()->numberBetween(50, 800),
            'latency_ms' => fake()->numberBetween(300, 9000),
            'status' => AiInteractionStatus::Succeeded,
            'prompt_hash' => hash('sha256', fake()->sentence()),
            'created_at' => now(),
        ];
    }
}
