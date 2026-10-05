<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a Radarr movie that dynamic-group auto-cache added (and tagged
     * for cleanup) left every group, so `cache:cleanup` can honor the
     * rule's "Keep after leaving (days)" before removing it from Radarr.
     * A row exists only while the movie is out of every group.
     */
    public function up(): void
    {
        Schema::create('arr_cache_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arr_integration_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('arr_movie_id');
            $table->unsignedInteger('tmdb_id');
            $table->timestamp('left_at');
            $table->timestamps();

            $table->unique(['arr_integration_id', 'arr_movie_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arr_cache_departures');
    }
};
