<?php

namespace App\Services;

use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Standalone per-Channel / per-Episode retention enforcement (PR B engine layer).
 *
 * Replaces PR #1500's `DynamicGroupCacheRetentionService` with a
 * per-(user, playlist) grouping model and a one-shot fingerprint rebuild.
 *
 * The core invariant (Constraint 12): a cached file is "still wanted"
 * when its fingerprint matches a content row in the same (user, playlist)
 * scope. The naive implementation walks each cached file and rebuilds
 * that scope's full fingerprint set on every iteration - O(files x
 * scope_size) total work, which PR #1500 shipped as-is.
 *
 * This implementation groups cached files by (user_id, playlist_id) ONCE
 * (one DB read for all candidate files), then rebuilds each scope's
 * fingerprint set ONCE per scope. Total work is O(scopes + files) - the
 * typical deployment has 1 scope per playlist so the cost is O(playlists +
 * cached_files), not O(playlists x cached_files).
 *
 * Retention policy:
 *  - `never_expire = true` rows are kept regardless of scope membership
 *    (Constraint 5 - never_expire survives rule/playlist deletion).
 *  - All other rows whose fingerprint does NOT appear in their scope's
 *    live fingerprint set are eligible for deletion.
 *  - Actual deletion (file on disk + row) happens in
 *    `CachedContentRetentionCleanup::handle()` via chunked DELETEs so
 *    very large cleanups don't blow past a single transaction's row
 *    limits on Postgres.
 */
class CachedContentRetentionService
{
    /**
     * Identify every `CachedContentFile` row that is no longer wanted.
     *
     * Returns a Collection of integer IDs (NOT Eloquent models) so the
     * caller can chunk them into a chunked DELETE without reloading the
     * model. The deletion path runs AFTER this returns - separation of
     * "what to delete" from "do the deletion" lets the cleanup job log
     * counts without holding row locks and lets tests assert on the ID
     * list directly.
     *
     * Memory-efficient: loads all candidate cached files in ONE query
     * (excludes `never_expire` since those rows are immune to retention
     * and would just be filtered out in PHP), then groups by
     * (user_id, playlist_id) in memory. The fingerprint rebuild per
     * group runs once and is reused across every cached file in that
     * group.
     *
     * @return Collection<int, int>
     */
    public function evaluate(): Collection
    {
        // Single SELECT: all candidate rows. never_expire=true is
        // excluded at the DB layer so the PHP-side loop never has to
        // re-check it (matches Constraint 5 - never_expire survives).
        // Excludes rows with NULL playlist_id / NULL user_id because
        // they have no scope and PR A's data layer explicitly considers
        // them orphan rows that should not leak across users. Including
        // them here would just produce IDs the cleanup job would
        // hard-delete, which IS what we want for them.
        $candidates = CachedContentFile::query()
            ->where('never_expire', false)
            ->get(['id', 'user_id', 'playlist_id', 'content_fingerprint']);

        if ($candidates->isEmpty()) {
            return collect();
        }

        // Group cached files by (user_id, playlist_id). Rows with a NULL
        // either side get bucketed under a synthetic -1 key so they don't
        // collide with real ids and don't accidentally share scope.
        $grouped = $candidates->groupBy(function (CachedContentFile $file): string {
            $userId = $file->user_id ?? -1;
            $playlistId = $file->playlist_id ?? -1;

            return "{$userId}:{$playlistId}";
        });

        $toDelete = collect();

        foreach ($grouped as $key => $files) {
            [$userId, $playlistId] = array_map('intval', explode(':', $key, 2));

            // Scope fingerprint set: built ONCE per (user, playlist)
            // group, reused across every file in the group. This is the
            // Constraint 12 invariant - PR #1500 rebuilt this set per
            // cached file (O(files x scope)), here it's O(scope) once.
            $liveFingerprints = $this->liveFingerprintsForScope($userId, $playlistId);

            foreach ($files as $file) {
                if (! $liveFingerprints->contains($file->content_fingerprint)) {
                    $toDelete->push($file->id);
                }
            }
        }

        return $toDelete;
    }

    /**
     * Build the set of content fingerprints currently live in the given
     * (user, playlist) scope.
     *
     * Scope identity: a Channel's scope is its `playlist_id` (the
     * `user_id` of the channel may differ from the owning user of the
     * cached file when the cached file was dispatched by an admin via
     * the orchestrator - that's why we group by playlist_id and rebuild
     * against the playlist's own channel/episode set). For Episode, the
     * scope is the playlist's episodes.
     *
     * Memory: cursor()-style iteration via `lazy()` to bound memory
     * for large playlists (a 50k-channel playlist doesn't need to be
     * loaded all at once). `lazy()` returns a LazyCollection that
     * streams results one chunk at a time.
     *
     * Pulls `tvdb_id` from BOTH Channel and the parent Series so the
     * produced fingerprint matches `CachedContentFile::fingerprintFor`'s
     * `content_type:tmdb_id:tvdb_id:...` shape - without this, any
     * cached row with a non-null tvdb_id would never match a live
     * fingerprint and retention would evict it while the live membership
     * still references the same content (PR #1500 bug).
     *
     * @return Collection<int, string>
     */
    protected function liveFingerprintsForScope(int $userId, int $playlistId): Collection
    {
        if ($playlistId === -1) {
            // Orphan row (NULL playlist_id). It has no live scope to
            // match against, so by definition it's not wanted. Caller
            // has already included it in candidates via the
            // never_expire=false filter; the liveFingerprintsForScope
            // returning an empty set makes the !=-contains check
            // succeed and the row ends up in toDelete - which is the
            // correct outcome for an orphan.
            return collect();
        }

        $fingerprints = collect();

        // Channel/movie fingerprints
        Channel::query()
            ->where('playlist_id', $playlistId)
            ->when($userId !== -1, fn ($q) => $q->where('user_id', $userId))
            ->lazy(1000)
            ->each(function (Channel $channel) use (&$fingerprints): void {
                $fingerprints->push(CachedContentFile::fingerprintFor([
                    'content_type' => 'movie',
                    'tmdb_id' => $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null,
                    'tvdb_id' => $channel->tvdb_id !== null ? (string) $channel->tvdb_id : null,
                ]));
            });

        // Episode fingerprints (uses parent Series for tmdb_id/tvdb_id)
        Episode::query()
            ->where('playlist_id', $playlistId)
            ->when($userId !== -1, fn ($q) => $q->where('user_id', $userId))
            ->with('series:id,tmdb_id,tvdb_id')
            ->lazy(1000)
            ->each(function (Episode $episode) use (&$fingerprints): void {
                $series = $episode->series;
                $fingerprints->push(CachedContentFile::fingerprintFor([
                    'content_type' => 'episode',
                    'tmdb_id' => ($series && $series->tmdb_id !== null) ? (string) $series->tmdb_id : ($episode->tmdb_id !== null ? (string) $episode->tmdb_id : null),
                    'tvdb_id' => ($series && $series->tvdb_id !== null) ? (string) $series->tvdb_id : null,
                    'season_number' => $episode->season,
                    'episode_number' => $episode->episode_num,
                ]));
            });

        return $fingerprints;
    }

    /**
     * Identify every `CachedContentFile` row linked to a single DynamicGroup
     * that is no longer wanted by that group's current membership.
     *
     * Phase 2 / PR E addition. Composes with `evaluate()`: the standalone
     * (user, playlist) sweep covers files that are not DG-owned, and
     * `CachedContentRetentionCleanup::handle()` calls this once per
     * DynamicGroup to cover the DG-owned files.
     *
     * Algorithm (Constraint 12 — O(groups + files), NOT O(groups x files)):
     *  1. Build the live fingerprint set for the group ONCE (one pass
     *     over the group's channels / series->episodes).
     *  2. Load the cached files linked via the pivot with `dropped_at IS NULL`,
     *     excluding `never_expire = true` (Constraint 5 — never_expire
     *     survives regardless of pivot state).
     *  3. In-memory compare each file's fingerprint to the live set;
     *     collect the IDs whose fingerprint is no longer present.
     *
     * Files linked via SOFT-UNSHARED pivot rows (`dropped_at IS NOT NULL`)
     * are intentionally NOT scanned here — those pivot rows are evidence
     * the file was once requested by the group but the link has since
     * been severed. The standalone `evaluate()` sweep still considers
     * such files by their (user, playlist) ownership and may evict them
     * through that path; the DG path stays focused on live pivots.
     *
     * Memory: `whereHas('dynamicGroups')` (via `scopeOwnedByDynamicGroup`)
     * compiles to a single SELECT with a subquery on the pivot. Loading
     * only `(id, content_fingerprint)` keeps the row payload small. The
     * live fingerprint set for a typical DynamicGroup is bounded by the
     * group's TMDB list size (a few hundred to a few thousand items).
     *
     * @return Collection<int, int>
     */
    public function evaluateForDynamicGroup(int $dynamicGroupId): Collection
    {
        $group = DynamicGroup::query()->find($dynamicGroupId);
        if (! $group) {
            return collect();
        }

        $liveFingerprints = $this->liveFingerprintsForGroup($group);
        if ($liveFingerprints->isEmpty()) {
            // Empty live membership means EVERY pivot-linked file is no
            // longer wanted (excluding never_expire, which the query
            // already filters out). Cheap to flag all of them.
            return CachedContentFile::query()
                ->ownedByDynamicGroup($dynamicGroupId)
                ->where('never_expire', false)
                ->pluck('id');
        }

        $candidates = CachedContentFile::query()
            ->ownedByDynamicGroup($dynamicGroupId)
            ->where('never_expire', false)
            ->get(['id', 'content_fingerprint']);

        return $candidates
            ->reject(fn (CachedContentFile $file): bool => $liveFingerprints->contains($file->content_fingerprint))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * Build the live fingerprint set for one DynamicGroup's current
     * membership. Used by `evaluateForDynamicGroup()` and kept private to
     * this service so the build path is the single source of truth for
     * DG-scope fingerprints.
     *
     * VOD-type groups: iterate `$group->channels` (polymorphic MorphToMany
     * over the `dynamic_group_items` pivot) and produce the same
     * fingerprint the dispatcher would produce for that channel.
     *
     * Series-type groups: iterate `$group->series` (eager-loaded with
     * `episodes`) and produce one fingerprint per episode. `series` and
     * `episodes` both have `tvdb_id` columns; episodes don't have their
     * own `tmdb_id` — the parent's `tmdb_id` is folded into the fingerprint
     * via `fingerprintFor()` so the cached row's identity matches the live
     * fingerprint exactly (PR #1500 Bug 1 — `tvdb_id`/identity mismatch
     * caused cache-delete-redownload loops).
     *
     * Eager-loads series.episodes so the inner loop doesn't trip an N+1.
     *
     * @return Collection<int, string>
     */
    private function liveFingerprintsForGroup(DynamicGroup $group): Collection
    {
        $fingerprints = collect();

        if ($group->type === 'vod') {
            $group->loadMissing('channels');
            foreach ($group->channels as $channel) {
                $fingerprints->push(CachedContentFile::fingerprintFor([
                    'content_type' => 'movie',
                    'tmdb_id' => $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null,
                    'tvdb_id' => $channel->tvdb_id !== null ? (string) $channel->tvdb_id : null,
                ]));
            }
        } elseif ($group->type === 'series') {
            $group->loadMissing('series.episodes');
            foreach ($group->series as $series) {
                $tmdbId = $series->tmdb_id !== null ? (string) $series->tmdb_id : null;
                $tvdbId = $series->tvdb_id !== null ? (string) $series->tvdb_id : null;
                foreach ($series->episodes as $episode) {
                    $fingerprints->push(CachedContentFile::fingerprintFor([
                        'content_type' => 'episode',
                        'tmdb_id' => $tmdbId,
                        'tvdb_id' => $tvdbId,
                        'season_number' => $episode->season,
                        'episode_number' => $episode->episode_num,
                    ]));
                }
            }
        }

        return $fingerprints;
    }

    /**
     * Delete a set of `CachedContentFile` rows + their on-disk files. Called by
     * `CachedContentRetentionCleanup::handle()` after `evaluate()`.
     *
     * Chunked DELETE via `chunkById` so a multi-thousand-row cleanup
     * doesn't lock the table. Each chunk:
     *  1. Loads the row's `file_path` + `disk`
     *  2. Deletes the file from storage
     *  3. Deletes the row
     *
     * On-disk delete failures are logged but don't stop the row delete -
     * a leaked file is recoverable from the disk while an orphaned row
     * hides a phantom cache hit. (Reverse is also bad, but disk cleanup
     * is recoverable; the DB row isn't.)
     *
     * @param  Collection<int, int>  $ids
     * @return int Number of rows actually deleted.
     */
    public function deleteIds(Collection $ids): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }

        $deleted = 0;
        // chunkById is the cursor-friendly, mutation-safe iteration
        // primitive from `db-performance.md` - cursor() is read-only.
        CachedContentFile::query()
            ->whereIn('id', $ids->all())
            ->chunkById(500, function ($rows) use (&$deleted): void {
                foreach ($rows as $row) {
                    if (! empty($row->file_path)) {
                        try {
                            $row->resolveStorageDisk() && \Storage::disk($row->resolveStorageDisk())->delete($row->file_path);
                        } catch (\Throwable $e) {
                            Log::warning("CachedContentRetention: failed to delete file {$row->file_path} for row {$row->id}: {$e->getMessage()}");
                        }
                    }
                    $row->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }
}
