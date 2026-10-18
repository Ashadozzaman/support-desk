<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Large ticket exports run on the `exports` queue (ENHANCEMENTS.md §1.4);
     * this row tracks one from request to download, and its file is deleted
     * with the row once `expires_at` passes (ReportExport is Prunable).
     */
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->json('filters');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('disk_path')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('expires_at'); // model:prune
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
