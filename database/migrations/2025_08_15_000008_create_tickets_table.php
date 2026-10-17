<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 32);
            $table->string('subject', 200);
            $table->text('description');
            $table->string('status', 24);
            $table->string('priority', 16);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_ref', 64)->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('sla_warning_at')->nullable();
            $table->timestamp('sla_warning_notified_at')->nullable();
            $table->timestamp('sla_breach_notified_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('sla_met')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique('ticket_number');
            $table->unique('external_ref');
            $table->index(['status', 'sla_warning_at']); // sla:scan approaching pass
            $table->index(['status', 'sla_due_at']); // sla:scan breach pass
            $table->index(['assigned_agent_id', 'status']); // agent active-ticket count / queue
            $table->index(['created_by', 'status']); // customer "my tickets" list
            $table->index(['status', 'priority']); // dashboard GROUP BY priority / queue filters
            $table->fullText(['subject', 'description']); // keyword search
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
