<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-sender rate limit (Step 25c, docs/IMAP.md §4): "messages from this
 * address in the last hour" is checked for every inbound message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->index(['channel', 'sender_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropIndex(['channel', 'sender_address', 'created_at']);
        });
    }
};
