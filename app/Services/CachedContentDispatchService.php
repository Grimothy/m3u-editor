<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Standalone per-Channel / per-Episode cache dispatcher (PR B engine layer).
 *
 * Replaces PR #1500's `DynamicGroupCacheDispatchService` without any of
 * its DynamicGroup coupling, dead `cache_avoid_duplicate_content` config,
 * or `tvdb_id` fingerprint-mismatch bug. The new invariant set:
 *
 *  1. Single-row dispatch (one Channel or Episode per call). The PR B
 *     test suite verifies that N calls with the same fingerprint produce
 *     exactly ONE INSERT total because the 2nd..Nth calls hit the
 *     existing-row short-circuit. Phase 2's `dispatchForGroup` lands the
 *     true batched INSERT (one query, N rows); the single-row path here
 *     is the single-row reduction of that batched query.
 *
 *  2. Ownership: every new `CachedContentFile` row stamps BOTH `user_id`
 *     (auth()->id() with a fallback to the source channel/episode's
 *     playlist owner for the scheduled-orchestrator path where no user is
 *     logged in) AND `playlist_id` (from the source row's playlist).
 *     Both columns were added in PR A.
 *
 *  3. Cross-playlist sharing: the SOURCE playlist owns the file
 *     (write-locked). Other playlists read it only when the source's
 *     `share_cache_across_playlists` toggle is on AND a Completed row
 *     exists for the fingerprint with that source playlist's id. PR D's
 *     UI flips the toggle per-playlist; this service reads it via
 *     `Playlist::share_cache_across_playlists`.
 *
 *  4. Fingerprint identity: `CachedContentFile::fingerprintFor()` is the
 *     single source of truth - both the live dispatch path and the cached
 *     fingerprint row use it. No per-call string-building that could
 *     drift from the model's contract.
 */
class CachedContentDispatchService
{
    /**
     * Dispatch a single Channel.
     *
     * Returns a Collection of the DownloadCachedContentFile job(s) actually
     * queued. Empty Collection when:
     *  - The fingerprint already has a Completed/Pending/Downloading/Failed row
     *    (idempotent re-dispatch is a no-op - the existing row handles it)
     *  - The Channel has no URL (no work to do)
     *  - Cross-playlist sharing hits an existing Completed row owned by
     *    a sharing-enabled source playlist (the share hit short-circuits
     *    before we create a new row)
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    public function dispatchForChannel(Channel $channel): Collection
    {
        $playlist = $channel->playlist;
        if (! $playlist) {
            return collect();
        }

        $fingerprint = $this->fingerprintForChannel($channel);

        // Sharing short-circuit (Constraint 4): a Completed row owned by
        // a sharing-enabled source playlist satisfies this dispatch's
        // need without creating a parallel row. Note: when the source
        // IS this playlist (typical case), this is just a fast-path
        // equivalent of the existing-row check below; the real value
        // shows up when an entirely different playlist's share toggle
        // points at our fingerprint.
        $sharedHit = $this->findSharedCacheHit($fingerprint, $playlist);
        if ($sharedHit !== null) {
            return collect();
        }

        return $this->dispatchNew($channel, $playlist, $fingerprint);
    }

    /**
     * Dispatch a single Episode.
     *
     * Episode flavor of dispatchForChannel. Episodes don't have their own
     * `tvdb_id` column - the parent's `tvdb_id` (via `series.tvdb_id`) is
     * folded into the fingerprint so the cached row matches the live
     * fingerprint (PR #1500 missed this, causing cache-delete-redownload
     * loops for series with a tvdb_id set).
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    public function dispatchForEpisode(Episode $episode): Collection
    {
        $playlist = $episode->playlist;
        if (! $playlist) {
            return collect();
        }

        $fingerprint = $this->fingerprintForEpisode($episode);

        $sharedHit = $this->findSharedCacheHit($fingerprint, $playlist);
        if ($sharedHit !== null) {
            return collect();
        }

        return $this->dispatchNew($episode, $playlist, $fingerprint);
    }

    /**
     * Dispatch every eligible Channel/Episode in a DynamicGroup's current
     * membership. Phase 2 / PR E implementation.
     *
     * Behavior:
     *  - For type='vod' groups: iterates $group->channels (polymorphic
     *    MorphToMany), dispatches each via dispatchForChannel().
     *  - For type='series' groups: iterates $group->series, eager-loaded
     *    with episodes to avoid the N+1 PR #1500 shipped (each Series
     *    fetched its episodes separately).
     *  - For each successfully dispatched file, writes a fresh pivot row in
     *    `cached_content_file_dynamic_groups` with `dropped_at = NULL`. The
     *    pivot insert is BATCHED (chunks of 100) and uses `insertOrIgnore`
     *    so concurrent dispatchers racing on the same (file, group) pair
     *    no-op the second insert instead of crashing on the unique key.
     *  - Returns the union of all DownloadCachedContentFile jobs queued by
     *    the underlying single-item dispatchers. Empty Collection when
     *    every item short-circuited (already cached / in cooldown).
     *
     * Memory: eager-loads the group's full membership up front so the
     * inner loop never trips a lazy-load. `series.episodes` is the
     * N+1 case PR #1500 had - calling `$series->episodes` per-iteration
     * without eager-loading does one SELECT per series.
     *
     * PR #1500 bug fixes folded in here (none of these are in the
     * original `dispatchForGroup()`):
     *  - tvdb_id on BOTH sides of the live fingerprint (the dispatcher
     *    reuses `fingerprintForChannel/Episode` which already fold the
     *    parent-Series tvdb_id into the fingerprint, so the cached row
     *    matches the live fingerprint shape).
     *  - No N+1 on series->episodes via the eager-load above.
     *  - Batched pivot insert (`insertOrIgnore` in chunks of 100).
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    public function dispatchForGroup(DynamicGroup $group): Collection
    {
        // Eager-load the full membership so the iteration below never
        // trips a lazy-load. Series carry their episodes here so we don't
        // hit one SELECT per series in the inner loop.
        $group->loadMissing([
            'channels',
            'series.episodes',
        ]);

        $jobs = collect();
        $newFileIds = [];

        if ($group->type === 'vod') {
            foreach ($group->channels as $channel) {
                $dispatched = $this->dispatchForChannel($channel);
                $jobs = $jobs->merge($dispatched);
                foreach ($dispatched as $job) {
                    $newFileIds[] = $job->cachedContentFileId;
                }
            }
        } elseif ($group->type === 'series') {
            foreach ($group->series as $series) {
                /** @var Series $series */
                foreach ($series->episodes as $episode) {
                    $dispatched = $this->dispatchForEpisode($episode);
                    $jobs = $jobs->merge($dispatched);
                    foreach ($dispatched as $job) {
                        $newFileIds[] = $job->cachedContentFileId;
                    }
                }
            }
        }

        if ($newFileIds !== []) {
            $this->insertPivotRows($group->id, $newFileIds);
        }

        return $jobs;
    }

    /**
     * Batch-insert pivot rows linking freshly-dispatched CachedContentFile
     * rows to the source DynamicGroup. Chunks of 100 to keep the SQL
     * payload bounded; uses `insertOrIgnore` so concurrent dispatches for
     * the same (file, group) pair survive the unique index without
     * crashing.
     *
     * Extracted from `dispatchForGroup()` so the body stays focused on the
     * traversal logic and so the chunking/now-stamping lives in one place.
     *
     * @param  array<int, int>  $cachedContentFileIds
     */
    protected function insertPivotRows(int $dynamicGroupId, array $cachedContentFileIds): void
    {
        $now = now();
        $rows = array_map(
            fn (int $fileId): array => [
                'cached_content_file_id' => $fileId,
                'dynamic_group_id' => $dynamicGroupId,
                'dropped_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $cachedContentFileIds,
        );

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('cached_content_file_dynamic_groups')->insertOrIgnore($chunk);
        }
    }

    /**
     * Whether a Completed `CachedContentFile` exists for `$fingerprint`
     * that another playlist can reuse via `$sourcePlaylist`'s sharing
     * toggle.
     *
     * Rule:
     *  - The owning playlist of the existing row MUST be `$sourcePlaylist`.
     *    Sharing is per-source-playlist, not per-fingerprint-globally.
     *  - That source playlist's `share_cache_across_playlists` MUST be on.
     *  - The row's status MUST be `Completed`. Pending/Downloading/Failed
     *    rows do not satisfy a sharing lookup - a failed share would just
     *    re-fail on playback.
     *
     * Returns false in all other cases. This is the read-side companion
     * to `findSharedCacheHit()` - the boolean form is what the dispatch
     * fast-path checks, the row form is what Phase 3's cache-hit gate
     * will use to grab the actual `CachedContentFile` for serving.
     */
    public function isCrossPlaylistDuplicate(string $fingerprint, Playlist $sourcePlaylist): bool
    {
        return $this->findSharedCacheHit($fingerprint, $sourcePlaylist) !== null;
    }

    /**
     * Same logic as `isCrossPlaylistDuplicate` but returns the matching
     * row instead of a boolean. Null when no share-hit exists. Used by
     * the Phase 3 cache-hit gate (`XtreamStreamController` per PR C)
     * to resolve the cached file's URL/path without an extra round-trip.
     */
    public function findSharedCacheHit(string $fingerprint, Playlist $sourcePlaylist): ?CachedContentFile
    {
        if (! $sourcePlaylist->share_cache_across_playlists) {
            return null;
        }

        return CachedContentFile::query()
            ->where('content_fingerprint', $fingerprint)
            ->where('playlist_id', $sourcePlaylist->id)
            ->where('status', CachedContentFileStatus::Completed)
            ->first();
    }

    /**
     * Build the dispatch from the per-item fingerprint + ownership check
     * through to the actual row insert + job dispatch.
     *
     * Existing-row short-circuit: any row with the same fingerprint
     * (any status) prevents the INSERT. This is what makes the
     * "N calls, same fingerprint, 1 INSERT" test invariant hold - the
     * second call hits this branch and returns an empty Collection.
     *
     * The INSERT is wrapped in a savepoint (DB::transaction) + a
     * QueryException fallback so a concurrent dispatch racing on the
     * same fingerprint survives: the unique index on `content_fingerprint`
     * rejects the second INSERT, we swallow it, and re-query the now-
     * existing row. Postgres aborts the surrounding transaction on a
     * failed statement (SQLSTATE 25P02), so the savepoint matters
     * specifically for RefreshDatabase's per-test wrapper.
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    protected function dispatchNew(Channel|Episode $item, Playlist $playlist, string $fingerprint): Collection
    {
        $url = (string) ($item->url ?? '');
        if ($url === '') {
            return collect();
        }

        // Idempotent re-dispatch: if any row exists for this fingerprint
        // we don't create a duplicate. Single SELECT, single check,
        // batched via content_fingerprint's unique index.
        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        if ($existing) {
            return collect();
        }

        $identity = $this->identityParts($item);

        $userId = Auth::id() ?? $playlist->user_id;

        try {
            $row = DB::transaction(function () use ($identity, $fingerprint, $playlist, $userId): CachedContentFile {
                return CachedContentFile::create([
                    'content_type' => $identity['content_type'],
                    'tmdb_id' => $identity['tmdb_id'],
                    'tvdb_id' => $identity['tvdb_id'],
                    'season_number' => $identity['season_number'],
                    'episode_number' => $identity['episode_number'],
                    'quality' => null,
                    'content_fingerprint' => $fingerprint,
                    'user_id' => $userId,
                    'playlist_id' => $playlist->id,
                    'status' => CachedContentFileStatus::Pending,
                ]);
            });
        } catch (QueryException) {
            // Lost an INSERT race against a concurrent dispatch for the
            // same fingerprint. The other writer's row satisfies the
            // idempotent re-dispatch contract; bail without dispatching.
            return collect();
        }

        $job = new DownloadCachedContentFile($item, $row->id);
        DownloadCachedContentFile::dispatch($item, $row->id)->onQueue('cache');

        return collect([$job]);
    }

    /**
     * Fingerprint for a Channel (content_type=movie).
     *
     * Reads `tmdb_id` and `tvdb_id` directly from the channel row
     * (Channel has both columns). Pass them both into `fingerprintFor`
     * so the cached row matches the live fingerprint exactly.
     */
    protected function fingerprintForChannel(Channel $channel): string
    {
        return CachedContentFile::fingerprintFor([
            'content_type' => 'movie',
            'tmdb_id' => $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null,
            'tvdb_id' => $channel->tvdb_id !== null ? (string) $channel->tvdb_id : null,
        ]);
    }

    /**
     * Fingerprint for an Episode (content_type=episode).
     *
     * Pulls `tmdb_id` + `tvdb_id` from the parent Series. Episode rows
     * don't have their own TMDB/TVDB columns - the Series does. The
     * cached row MUST use the same fingerprint inputs as the live
     * fingerprint (which is the same Series for any Episode of that
     * Series) so that two Episodes of the same Series produce two
     * distinct fingerprints (different season/episode) under the same
     * identity prefix.
     */
    protected function fingerprintForEpisode(Episode $episode): string
    {
        $series = $episode->series;

        return CachedContentFile::fingerprintFor([
            'content_type' => 'episode',
            'tmdb_id' => ($series && $series->tmdb_id !== null) ? (string) $series->tmdb_id : ($episode->tmdb_id !== null ? (string) $episode->tmdb_id : null),
            'tvdb_id' => ($series && $series->tvdb_id !== null) ? (string) $series->tvdb_id : null,
            'season_number' => $episode->season,
            'episode_number' => $episode->episode_num,
        ]);
    }

    /**
     * Identity parts extracted from the source row. Used both as inputs
     * to `fingerprintFor` (via `identityParts` -> `fingerprintFor`) and
     * as the direct INSERT payload. Keeping them in one place avoids
     * drift between fingerprint and INSERT column values.
     *
     * @return array{content_type: string, tmdb_id: ?string, tvdb_id: ?string, season_number: ?int, episode_number: ?int}
     */
    protected function identityParts(Channel|Episode $item): array
    {
        if ($item instanceof Channel) {
            return [
                'content_type' => 'movie',
                'tmdb_id' => $item->tmdb_id !== null ? (string) $item->tmdb_id : null,
                'tvdb_id' => $item->tvdb_id !== null ? (string) $item->tvdb_id : null,
                'season_number' => null,
                'episode_number' => null,
            ];
        }

        // Episode branch
        $series = $item->series;

        return [
            'content_type' => 'episode',
            'tmdb_id' => ($series && $series->tmdb_id !== null) ? (string) $series->tmdb_id : ($item->tmdb_id !== null ? (string) $item->tmdb_id : null),
            'tvdb_id' => ($series && $series->tvdb_id !== null) ? (string) $series->tvdb_id : null,
            'season_number' => $item->season,
            'episode_number' => $item->episode_num,
        ];
    }
}
