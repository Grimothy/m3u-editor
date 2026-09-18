<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the per-playlist toggle that controls whether this playlist's
     * cached files are eligible to be shared with (read by) other playlists.
     *
     * Sharing semantics: the source playlist OWNS each of its cached files
     * (write-locked - other playlists can never delete or replace the
     * underlying file via this toggle). Other playlists can READ those files
     * (their own playback path resolves them via the cached URL) only when
     * the owning playlist has `share_cache_across_playlists` enabled.
     *
     * Default false because cross-playlist sharing is the safer-opt-in path -
     * a Playlist that imports a movie from a provider should not silently
     * let an unrelated user's Playlist with the same TMDB id reuse the
     * underlying download, especially before operators have a UI to inspect
     * which playlist owns which file.
     *
     * The dedup logic lives in
     * `CachedContentDispatchService::isCrossPlaylistDuplicate()` /
     * `findSharedCacheHit()` (PR B) and reads this column directly via
     * `Playlist::share_cache_across_playlists`. PR D exposes the toggle in
     * the per-playlist override fields on `PlaylistResource`.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->boolean('share_cache_across_playlists')
                ->default(false)
                ->after('enable_proxy');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn('share_cache_across_playlists');
        });
    }
};
