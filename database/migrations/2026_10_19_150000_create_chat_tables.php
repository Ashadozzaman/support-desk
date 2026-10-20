<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support chatbot conversations (Step 24). Customer text is kept so the
 * conversation can be shown and handed off; it is deleted after
 * `ai.chat.retention_days` by `model:prune` (messages go with their session).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            // Set once, by the hand-off; the ticket outlives the chat.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // "This user's active conversation" on every chat request.
            $table->index(['user_id', 'status']);
            // AI report (sessions in a period) and the retention sweep.
            $table->index('created_at');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            // Articles the answer was grounded in, shown as its sources.
            $table->json('kb_article_ids')->nullable();
            // The assistant couldn't answer from the articles: offer a human.
            $table->boolean('suggests_handoff')->default(false);
            $table->timestamp('created_at');

            // The conversation, in order.
            $table->index(['chat_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
    }
};
