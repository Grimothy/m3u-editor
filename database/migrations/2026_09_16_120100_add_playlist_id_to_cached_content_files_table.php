<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamp the source playlist directly on each cached file so PR B's
     * cross-playlist sharing rule can resolve ownership without walking
     * a pivot.
     *
     * Sharing semantics: the source playlist owns the file (read-shared,
     * write-locked). Other playlists read it only if the source playlist
     * has `share_cache_across_playlists` enabled. Without a direct
     * `playlist_id` column there is no durable mapping from a Completed
     * row back to its source playlist.
     *
     * Nullable for the same reason as `user_id`: PR A lands this column
     * ahead of any writer.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->foreignId('playlist_id')
                ->nullable()
                ->after('user_id')
                ->constrained('playlists')
                ->nullOnDelete();
            $table->index('playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropIndex(['playlist_id']);
            $table->dropConstrainedForeignId('playlist_id');
        });
    }
};
