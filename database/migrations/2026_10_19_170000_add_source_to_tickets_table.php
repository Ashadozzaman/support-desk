<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a ticket came in (Step 25a, docs/IMAP.md). One column in one ALTER:
 * `tickets` has a FULLTEXT index, so every ALTER copies the whole table
 * (DATABASE.md note). Existing rows keep the default `web` — no backfill.
 * No index: nothing filters on it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('source', 16)->default('web')->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
