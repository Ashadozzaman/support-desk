<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('max_active_tickets')->default(10);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();

            // Covers the auto-assign SELECT ... FOR UPDATE SKIP LOCKED candidate pick (ARCHITECTURE.md §6.2).
            $table->index(['is_available', 'last_assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profiles');
    }
};
