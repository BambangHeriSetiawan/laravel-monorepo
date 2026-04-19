<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the load_test_posts table used exclusively by the heavy-query
 * load test endpoints.
 *
 * Deliberately uses a non-indexed `status` column and a fulltext-less
 * `body` TEXT column so that queries against them are slow enough to
 * be captured by the HeavyQuerySampler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_test_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('body');
            // Intentionally NO index on status — forces full table scan
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->decimal('score', 8, 4)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('load_test_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')
                ->references('id')->on('load_test_posts')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_test_comments');
        Schema::dropIfExists('load_test_posts');
    }
};
