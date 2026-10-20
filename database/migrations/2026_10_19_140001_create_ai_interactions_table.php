<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per provider call (ENHANCEMENTS.md §3.2 rule 5). Metadata only:
 * the prompt is kept as a hash, never as text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 32);
            $table->string('model', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms');
            $table->string('status', 16);
            // Set later by the human acting on the suggestion (Step 23).
            $table->string('outcome', 16)->nullable();
            $table->string('error_type', 64)->nullable();
            $table->char('prompt_hash', 64);
            $table->timestamp('created_at');

            // AI report KPIs per feature over a period.
            $table->index(['feature', 'created_at']);
            // model:prune retention sweep.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
