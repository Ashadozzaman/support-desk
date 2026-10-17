<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        $uuid = fake()->uuid();

        return [
            'ticket_id' => Ticket::factory(),
            'ticket_reply_id' => null,
            'uploaded_by' => User::factory(),
            'disk_path' => "tickets/1/{$uuid}.pdf",
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1_000, 500_000),
            'checksum_sha256' => hash('sha256', $uuid),
        ];
    }
}
