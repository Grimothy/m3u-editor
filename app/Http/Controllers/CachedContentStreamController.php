<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamLocalFile;
use App\Models\CachedContentFile;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\PlaylistCredentialResolver;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Serve a cached content file (VOD movie or series episode).
 *
 * GET /cached-content/{username}/{password}/{uuid}.{format?}
 *
 * Auth follows the same two-step pattern as `DvrStreamController::resolveUser()`
 * and `XtreamStreamController::findAuthenticatedPlaylistAndStreamModel()`:
 *  - Method 1: PlaylistAuth credentials (returns the assigned playlist)
 *  - Method 2: password = playlist UUID, username = playlist owner's name
 *
 * Both steps are delegated to the shared `PlaylistCredentialResolver`
 * service; this controller only adds the endpoint-specific ownership
 * and type checks below.
 *
 * Phase 1 only supports caching for plain `Playlist` rows. The
 * `cached_content_files.playlist_id` column is a FK into the `playlists`
 * table, so files owned by `CustomPlaylist` / `MergedPlaylist` /
 * `PlaylistAlias` rows are not currently cached. We still resolve those
 * types in the auth step (so the route stays compatible with existing
 * client URLs), but we reject them at the ownership check with 404 -
 * the correct "no cache for this playlist type" answer without leaking
 * whether the uuid exists.
 *
 * The 404 gate also prevents a numeric `id` collision between
 * `playlists.id` and `custom_playlists.id` / `merged_playlists.id` /
 * `playlist_aliases.id` (all are independent auto-incrementing
 * sequences). Without the type guard, a CustomPlaylist with id=5
 * could pass `ownedByPlaylist(5)` and accidentally serve a row that
 * actually belongs to a plain Playlist id=5.
 *
 * Range serving reuses `StreamLocalFile::serve()` so the 8 KiB chunk
 * size, 200/206 status split, and Content-Range header construction
 * are shared with `DvrStreamController::stream()`.
 */
class CachedContentStreamController extends Controller
{
    public function __construct(protected PlaylistCredentialResolver $resolver) {}

    /**
     * Resolve the authenticated playlist. Returns null when credentials
     * do not resolve. The returned model is one of Playlist /
     * MergedPlaylist / CustomPlaylist / PlaylistAlias depending on which
     * table the uuid (or PlaylistAuth assignment) maps to; the caller is
     * responsible for narrowing to Playlist before reading the cache.
     *
     * @return Model|null Playlist | MergedPlaylist | CustomPlaylist | PlaylistAlias
     */
    private function resolvePlaylist(string $username, string $password): ?Model
    {
        return $this->resolver->resolve($username, $password);
    }

    public function stream(Request $request, string $username, string $password, string $uuid)
    {
        $playlist = $this->resolvePlaylist($username, $password);
        if (! $playlist) {
            abort(401, 'Invalid credentials');
        }

        if (! (app(GeneralSettings::class)->enable_cache ?? false)) {
            abort(404, 'Cached file not found');
        }

        // Type guard: only plain Playlist rows own cached files. The
        // `cached_content_files.playlist_id` column is a FK into the
        // `playlists` table, so any other type falls through to 404.
        // This also blocks a numeric `id` collision with custom /
        // merged / alias tables (all are independent auto-incrementing
        // sequences). Returning 404 here matches the "no cache for
        // this playlist type" answer and avoids leaking whether the
        // uuid exists in another table.
        if (! $playlist instanceof Playlist) {
            abort(404, 'Cached file not found');
        }

        // PR #1524 review item 5: authorize the uuid via the same
        // servable scope the gate + dispatcher use, so a file shared by
        // another of the same user's playlists with
        // `share_cache_across_playlists = true` plays through this
        // playlist's credentials. Rows owned by a different user NEVER
        // resolve - the subquery inside the scope filters sharing
        // candidates by `playlists.user_id = $playlist->user_id`.
        $file = CachedContentFile::query()
            ->servableForPlaylist($playlist)
            ->where('uuid', $uuid)
            ->first();

        if (! $file) {
            abort(404, 'Cached file not found');
        }

        if (! $file->hasFilePath()) {
            abort(404, 'Cached file content not available');
        }

        $disk = $file->resolveStorageDisk();

        if (! Storage::disk($disk)->exists($file->file_path)) {
            abort(404, 'Cached file not found on disk');
        }

        $fullPath = Storage::disk($disk)->path($file->file_path);

        // Storage::size() throws (Flysystem FileNotFoundException) on a
        // missing file. Some drivers return false instead. Neither is
        // acceptable for `int $fileSize` -- previously this tripped a
        // TypeError 500 when a file vanished between exists() and
        // filesize() (TOCTOU). Surface the disappearance as 404.
        try {
            $fileSize = Storage::disk($disk)->size($file->file_path);
        } catch (\Throwable) {
            abort(404, 'Cached file not found on disk');
        }
        if ($fileSize === false || $fileSize < 1) {
            abort(404, 'Cached file not found on disk');
        }
        $fileSize = (int) $fileSize;

        // Guard the actual read too: the file could vanish between
        // size() and fopen() inside the streamed response.
        if (@fopen($fullPath, 'rb') === false) {
            abort(404, 'Cached file not found on disk');
        }

        return StreamLocalFile::serve(
            fullPath: $fullPath,
            fileSize: $fileSize,
            mimeType: $file->resolveMimeType(),
            filename: basename($file->file_path),
            range: $request->header('Range'),
        );
    }
}
