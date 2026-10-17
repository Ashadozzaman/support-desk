<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'ticket_number' => 'TKT-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::Open->value,
            'priority' => fake()->randomElement(TicketPriority::cases())->value,
            'category_id' => Category::factory(),
            'created_by' => User::factory(),
            'assigned_agent_id' => null,
            'external_ref' => null,
            'sla_due_at' => null,
            'sla_warning_at' => null,
        ];
    }
}
