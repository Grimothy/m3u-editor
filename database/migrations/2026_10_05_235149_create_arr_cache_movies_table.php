<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Radarr movies dynamic-group auto-cache added for a rule with "Remove
     * from Radarr after leaving" on, so `cache:cleanup` can remove them once
     * they've been out of every group for the rule's "Keep after leaving
     * (days)". `left_at` is set while a movie is out of every group.
     */
    public function up(): void
    {
        Schema::create('arr_cache_movies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arr_integration_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tmdb_id');
            $table->unsignedInteger('arr_movie_id');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['arr_integration_id', 'tmdb_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arr_cache_movies');
    }
};
