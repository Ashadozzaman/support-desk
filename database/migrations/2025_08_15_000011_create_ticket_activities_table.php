<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 32);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at'); // append-only: no updated_at

            $table->index(['ticket_id', 'created_at']); // timeline query is always "this ticket, chronological"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_activities');
    }
};
