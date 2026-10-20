<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel-agnostic intake (Step 25a, docs/IMAP.md §3). Message bodies live on
 * the ticket/reply as today; these tables only hold what's needed to dedupe
 * and to thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16);
            // Email Message-ID (WhatsApp wamid later). Longer ids are hashed by the service.
            $table->string('external_message_id', 255);
            $table->string('sender_address', 255);
            $table->string('subject', 200);
            $table->string('status', 24);
            $table->string('skip_reason', 64)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_reply_id')->nullable()->constrained()->nullOnDelete();
            // Attachment names refused by the upload allow-list, with the reason.
            $table->json('rejected_attachments')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            // The duplicate guard: a redelivered message or a retried job inserts nothing.
            $table->unique(['channel', 'external_message_id']);
            // Sync history / retention, newest first.
            $table->index('created_at');
        });

        Schema::create('ticket_channel_threads', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16);
            // A message id that belongs to the ticket's conversation.
            $table->string('thread_key', 255);
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // "Which ticket does In-Reply-To / References point at?" — and one ticket per key.
            $table->unique(['channel', 'thread_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_channel_threads');
        Schema::dropIfExists('inbound_messages');
    }
};
