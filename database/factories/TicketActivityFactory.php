<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketActivity>
 */
class TicketActivityFactory extends Factory
{
    protected $model = TicketActivity::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'actor_id' => User::factory(),
            'event' => ActivityEvent::TicketCreated->value,
            'old_values' => [],
            'new_values' => [],
            'metadata' => [],
            'created_at' => now(),
        ];
    }
}
