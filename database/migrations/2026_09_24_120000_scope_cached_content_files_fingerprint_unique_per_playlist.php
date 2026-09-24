<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the global unique on `cached_content_files.content_fingerprint`
     * with a composite unique on `(content_fingerprint, playlist_id)`.
     *
     * Cache identity is per playlist: two playlists (even owned by different
     * users) are each entitled to their own cached copy of the same content,
     * while two channels in the same playlist still dedup to one row.
     * Same-user sharing is resolved at read time via
     * `CachedContentFile::scopeServableForPlaylist()`.
     *
     * Shipped as its own migration (rather than editing the earlier
     * `add_playlist_id` migration) so databases that already ran the
     * original cache migrations pick up the new constraint. The table is
     * introduced in this same feature, so it is empty or near-empty at
     * deploy time and the index swap does not contend with the sync jobs.
     */
    public function up(): void
    {
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
    }
};
