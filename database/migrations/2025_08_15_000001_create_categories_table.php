<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
