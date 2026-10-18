<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AgentRepository::performanceList()/DashboardRepository::byAgent()
     * (ARCHITECTURE.md §14/§14.1) group by assigned_agent_id and
     * conditionally SUM both tickets.resolved_at and tickets.assigned_at
     * in the same query. A 3-column index ending in only one of those two
     * columns still forces InnoDB to bookmark-lookup the full row (which
     * carries a TEXT `description`) for every matching ticket to read
     * whichever column the index didn't cover — at ~1M seeded tickets that
     * made the by-agent breakdown take minutes instead of seconds
     * (verified: the old 2-column `(assigned_agent_id, status)` index, and
     * a first attempt with two separate 3-column indexes, both left
     * `EXPLAIN`'s `tickets` row without "Using index" and the query never
     * finished inside a 120s timeout). This single 4-column index covers
     * every column the aggregate touches on `tickets` — nothing outside
     * the index needs to be read — turning it into an index-only scan
     * (confirmed via `EXPLAIN`: `Using index`, no filesort). It also
     * serves as a leftmost-prefix substitute for the old
     * `(assigned_agent_id, status)` index, so dropping that loses no other
     * query path (DATABASE.md §5).
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Added before the drop below: assigned_agent_id is a FK, and
            // InnoDB requires some index covering it at all times.
            $table->index(['assigned_agent_id', 'status', 'resolved_at', 'assigned_at']);
            $table->dropIndex(['assigned_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['assigned_agent_id', 'status']);
            $table->dropIndex(['assigned_agent_id', 'status', 'resolved_at', 'assigned_at']);
        });
    }
};
