<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support mailbox (Step 25b, docs/IMAP.md §3): its settings, edited by an
 * admin, and one row per sync for the history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A single row.
        Schema::create('mailbox_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('host', 255);
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 8);
            $table->string('username', 255);
            // Encrypted with APP_KEY (`encrypted` cast). Null = use IMAP_PASSWORD from .env.
            $table->text('password')->nullable();
            $table->string('folder', 255);
            $table->unsignedSmallInteger('fetch_interval_minutes');
            $table->unsignedSmallInteger('batch_size');
            // Sync cursor: the highest IMAP UID already queued, valid only for this UIDVALIDITY.
            $table->unsignedInteger('last_uid')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailbox_syncs', function (Blueprint $table) {
            $table->id();
            // Null = the scheduler.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('replied_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            // A safe, fixed message — never the raw exception (it can echo server responses).
            $table->string('error', 255)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // History table newest first, and the retention sweep.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_syncs');
        Schema::dropIfExists('mailbox_settings');
    }
};
