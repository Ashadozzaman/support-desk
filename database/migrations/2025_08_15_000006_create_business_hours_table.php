<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday ... 6 = Saturday
            $table->boolean('is_working_day');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->unique('day_of_week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
    }
};
