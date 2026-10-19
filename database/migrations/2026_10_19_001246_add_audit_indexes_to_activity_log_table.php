<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin audit-log page filters by one equality (event, else log_name)
 * plus a created_at range, newest first; `activitylog:clean` deletes by
 * created_at. Each shape gets an index so none of them filesorts or scans
 * the whole table. The package's own migrations stay unmodified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['log_name', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['log_name', 'created_at']);
            $table->dropIndex(['event', 'created_at']);
            $table->dropIndex(['created_at']);
        });
    }
};
