<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticket list (`TicketRepository::paginateFor`, ARCHITECTURE.md §12)
     * filters by tab (status IN active|resolved) and always sorts by
     * `created_at` DESC (`->latest()`). None of the existing `tickets`
     * indexes end in `created_at`, so at real volume MySQL can't use any
     * of them to satisfy the ORDER BY and falls back to a full table scan
     * + filesort (verified via EXPLAIN: `type: ALL`, `Using filesort`,
     * ~2.6s at ~1M rows / ~350K matching the active tab). This index lets
     * the common case — admin/staff browsing a tab with no extra filter —
     * range-scan `status` and read rows back already in `created_at`
     * order, no filesort.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
