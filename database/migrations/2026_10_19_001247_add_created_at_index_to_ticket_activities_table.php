<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit page "Tickets" log (Step 19c): every ticket's activity, newest
 * first. The existing (ticket_id, created_at) / (event, created_at) indexes
 * both lead with another column, so an unfiltered ORDER BY created_at
 * would scan + filesort the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
