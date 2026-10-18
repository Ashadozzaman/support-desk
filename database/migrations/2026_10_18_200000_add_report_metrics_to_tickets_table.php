<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report metrics written at the moment of the event (ENHANCEMENTS.md
     * §1.2), so reports are plain SUM/AVG over columns instead of
     * re-running business-hours maths over every ticket on each read. Both
     * durations are business minutes from created_at. sla_met already
     * exists (Step 2). No indexes here: Step 17b adds only the ones its
     * report queries' EXPLAIN shows they need.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('first_response_minutes')->nullable()->after('first_response_at');
            $table->unsignedInteger('resolution_minutes')->nullable()->after('resolved_at');
            $table->unsignedSmallInteger('reopened_count')->default(0)->after('resolution_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['first_response_minutes', 'resolution_minutes', 'reopened_count']);
        });
    }
};
