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
     *
     * Also rewrites the create migration's global unique on
     * `content_fingerprint` to a composite unique on
     * `(content_fingerprint, playlist_id)`. Cache identity is per-playlist:
     * two different playlists (even owned by different users) are entitled
     * to their own cached copy of the same content, while two channels in
     * the same playlist still dedup to one row. The default index name
     * Laravel generates for `$table->string('foo')->unique()` is
     * `<table>_<col>_unique`, so the drop uses
     * `cached_content_files_content_fingerprint_unique`.
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

        // Replace the global unique on content_fingerprint with a composite
        // unique on (content_fingerprint, playlist_id). Same fingerprint is
        // allowed across distinct playlists; same fingerprint within one
        // playlist still dedups to a single row.
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropUnique('cached_content_files_content_fingerprint_unique');
            $table->unique(['content_fingerprint', 'playlist_id'], 'cached_content_files_content_fingerprint_playlist_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropUnique('cached_content_files_content_fingerprint_playlist_id_unique');
            $table->unique('content_fingerprint', 'cached_content_files_content_fingerprint_unique');
        });

        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropIndex(['playlist_id']);
            $table->dropConstrainedForeignId('playlist_id');
        });
    }
};
