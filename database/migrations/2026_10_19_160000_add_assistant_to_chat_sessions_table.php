<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 24b: chat_sessions now hold two kinds of conversation — the customer
 * help chatbot and the staff data assistant — so each lookup and the AI
 * report's deflection rate can stay to their own kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('assistant', 16)->default('support')->after('user_id');

            // Replaces (user_id, status): "this user's active conversation with this assistant".
            // Still leads with user_id, so it also serves the user_id foreign key.
            $table->index(['user_id', 'assistant', 'status']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'assistant', 'status']);
            $table->dropColumn('assistant');
        });
    }
};
