<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamLocalFile;
use App\Models\CachedContentFile;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
 * Phase 1 only supports caching for plain `Playlist` rows - the
 * `cached_content_files.playlist_id` column is a foreign key into the
 * `playlists` table, so files owned by `CustomPlaylist` /
 * `MergedPlaylist` / `PlaylistAlias` rows are not currently cached.
 * Auth still resolves those types (so the route stays compatible with
 * existing client URLs), but the ownership lookup returns 404 for them
 * which is the correct "no cache for this playlist type" answer.
 *
 * Range serving reuses `StreamLocalFile::serve()` so the 8 KiB chunk
 * size, 200/206 status split, and Content-Range header construction are
 * shared with `DvrStreamController::stream()`.
 */
class CachedContentStreamController extends Controller
{
    /**
     * Returns [$playlist, $playlistAuth, $isGuestCredential] where:
     *  - $playlist is null when credentials do not resolve,
     *  - $playlistAuth is non-null only when auth resolved via PlaylistAuth, and
     *  - $isGuestCredential mirrors `DvrStreamController::resolveUser()`'s same flag.
     *
     * @return array{0: Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null, 1: PlaylistAuth|null, 2: bool}
     */
    private function resolvePlaylist(string $username, string $password): array
    {
        // Method 1: PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && ! $playlistAuth->isExpired()) {
            $playlist = $playlistAuth->getAssignedModel();
            if ($playlist) {
                return [$playlist, $playlistAuth, true];
            }
        }

        // Method 2: password = playlist UUID, username = owner's name
        $playlistTypes = [Playlist::class, MergedPlaylist::class, CustomPlaylist::class, PlaylistAlias::class];

        foreach ($playlistTypes as $type) {
            try {
                $playlist = $type::with('user')->where('uuid', $password)->firstOrFail();

                if ($playlist->user && $playlist->user->name === $username) {
                    return [$playlist, null, false];
                }
            } catch (ModelNotFoundException) {
                // Try next type
            }
        }

        return [null, null, false];
    }

    public function stream(Request $request, string $username, string $password, string $uuid)
    {
        [$playlist, $playlistAuth, $isGuestCredential] = $this->resolvePlaylist($username, $password);
        if (! $playlist) {
            abort(401, 'Invalid credentials');
        }

        // Ownership check: the file's `playlist_id` must equal the resolved
        // playlist's id. Phase 1 only stores Playlist-owned files (the FK
        // is into `playlists`), so non-Playlist auth types (CustomPlaylist,
        // MergedPlaylist, PlaylistAlias) fall through to 404 here - which is
        // the correct answer for "no cache for this playlist type" without
        // leaking whether the uuid exists.
        $file = CachedContentFile::query()
            ->ownedByPlaylist($playlist->id)
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
        $fileSize = filesize($fullPath);

        return StreamLocalFile::serve(
            fullPath: $fullPath,
            fileSize: $fileSize,
            mimeType: $file->resolveMimeType(),
            filename: basename($file->file_path),
            range: $request->header('Range'),
        );
    }
}
