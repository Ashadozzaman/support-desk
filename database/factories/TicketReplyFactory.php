<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReplyType;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketReply>
 */
class TicketReplyFactory extends Factory
{
    protected $model = TicketReply::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'type' => ReplyType::Reply->value,
            'body' => fake()->paragraph(),
        ];
    }

    public function internalNote(): static
    {
        return $this->state(['type' => ReplyType::InternalNote->value]);
    }
}
