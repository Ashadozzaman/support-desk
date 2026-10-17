<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 20);
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedTinyInteger('warning_threshold_percent')->default(80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
