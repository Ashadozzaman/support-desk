<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChatAssistant;
use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatSession>
 */
class ChatSessionFactory extends Factory
{
    protected $model = ChatSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'assistant' => ChatAssistant::Support,
            'status' => ChatSessionStatus::Active,
            'ticket_id' => null,
        ];
    }
}
