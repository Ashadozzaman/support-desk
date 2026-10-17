<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->text('body');
            $table->timestamps();

            // Covers "public replies for ticket, ordered" and "notes for ticket, ordered" in one shape.
            $table->index(['ticket_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
    }
};
