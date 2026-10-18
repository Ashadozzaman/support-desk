<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report rollups (ENHANCEMENTS.md §1.2). `ticket_daily_stats` is
     * RECOMPUTED by `reports:aggregate`, never incremented, so it can always
     * be rebuilt from `tickets` + `ticket_activities`. One row per business
     * day × category × priority × agent; 0 stands for "no category" /
     * "unassigned" because primary-key columns can't be NULL — which is
     * also why these columns carry no foreign keys.
     */
    public function up(): void
    {
        Schema::create('ticket_daily_stats', function (Blueprint $table) {
            $table->date('stat_date');
            $table->unsignedBigInteger('category_id');
            $table->string('priority', 16);
            $table->unsignedBigInteger('agent_id');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->unsignedInteger('sla_met_count')->default(0);
            $table->unsignedInteger('sla_breached_count')->default(0);
            $table->unsignedBigInteger('sum_resolution_minutes')->default(0);
            $table->unsignedInteger('responded_count')->default(0);
            $table->unsignedBigInteger('sum_first_response_minutes')->default(0);
            $table->unsignedInteger('escalated_count')->default(0);
            $table->unsignedInteger('reopened_count')->default(0);

            $table->primary(['stat_date', 'category_id', 'priority', 'agent_id']);
        });

        Schema::create('ticket_daily_snapshots', function (Blueprint $table) {
            $table->date('stat_date')->primary();
            $table->unsignedInteger('open_count');
            $table->unsignedInteger('unassigned_count');
            $table->unsignedInteger('breached_open_count');
            $table->timestamp('taken_at');
        });

        // reports:aggregate selects one business day at a time by each
        // event's own timestamp. Without these, EXPLAIN on a one-day
        // created_at range was a full scan of ~1M rows.
        //
        // The resolved_at / first_response_at ones also COVER the live
        // median/p90 query (ReportRepository::percentiles): 90 days is ~45%
        // of the table, so with a plain (resolved_at) index MySQL chose a
        // full scan + filesort (5.5s at ~1M rows); with priority and the
        // minutes in the index it reads the index alone.
        //
        // (status, priority) is widened to cover the live backlog and aging
        // queries (ReportRepository::backlogNow/agingBuckets): over ~350k
        // active tickets they read every row (1.4–3.2s) because no index
        // held assigned_agent_id / created_at / sla_due_at. The dashboard's
        // GROUP BY priority keeps using the same (status, priority) prefix.
        //
        // Everything goes in ONE ALTER: tickets has a FULLTEXT index, and one
        // statement means one pass over the table instead of five
        // (DATABASE.md §3 note). INPLACE/LOCK=NONE makes MySQL fail loudly
        // rather than silently fall back to a write-blocking table copy.
        DB::statement('ALTER TABLE tickets
            ADD INDEX tickets_created_at_index (created_at),
            ADD INDEX tickets_resolved_at_priority_minutes_index (resolved_at, priority, resolution_minutes),
            ADD INDEX tickets_first_response_at_priority_minutes_index (first_response_at, priority, first_response_minutes),
            ADD INDEX tickets_escalated_at_index (escalated_at),
            ADD INDEX tickets_status_priority_agent_created_due_index (status, priority, assigned_agent_id, created_at, sla_due_at),
            DROP INDEX tickets_status_priority_index,
            ALGORITHM=INPLACE, LOCK=NONE');

        // Reopens per day are read from the audit trail; the existing
        // (ticket_id, created_at) index can't serve "all reopens on a day".
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->dropIndex(['event', 'created_at']);
        });

        DB::statement('ALTER TABLE tickets
            ADD INDEX tickets_status_priority_index (status, priority),
            DROP INDEX tickets_status_priority_agent_created_due_index,
            DROP INDEX tickets_created_at_index,
            DROP INDEX tickets_resolved_at_priority_minutes_index,
            DROP INDEX tickets_first_response_at_priority_minutes_index,
            DROP INDEX tickets_escalated_at_index');

        Schema::dropIfExists('ticket_daily_snapshots');
        Schema::dropIfExists('ticket_daily_stats');
    }
};
