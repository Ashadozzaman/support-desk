<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 120);
            $table->text('body');
            $table->boolean('is_published')->default(false);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique('slug');
            // Help centre: published articles A–Z.
            $table->index(['is_published', 'title']);
            // Admin list: most recently edited first.
            $table->index('updated_at');
            // Help-centre search and the chatbot's retrieval (KnowledgeBaseRepository).
            $table->fullText(['title', 'body']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
