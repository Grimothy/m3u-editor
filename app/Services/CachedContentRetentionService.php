<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Standalone per-Channel / per-Episode retention enforcement.
 *
 * The core invariant: a cached file is "still wanted" when its
 * fingerprint matches a content row in the same (user, playlist) scope.
 * The implementation groups cached files by (user_id, playlist_id) ONCE
 * (lazy iteration so a multi-thousand-row deployment does not hydrate
 * the whole set), then rebuilds each scope's fingerprint set ONCE per
 * scope. Total work is O(scopes + files).
 *
 * Retention policy (effective per playlist via
 * `Playlist::effectiveCacheRetentionMode()`):
 *  - `never-expire` and `manual` both block automatic cleanup for the
 *    owning playlist. Files in those playlists survive the sweep.
 *    Operators delete them from the UI manually.
 *  - `time-based` lets the automatic sweep pick up stale rows. "Stale"
 *    means the row's `content_fingerprint` no longer appears in the
 *    owning playlist's live membership.
 *  - `never_expire = true` rows are kept regardless of scope membership
 *    (per-row pin, beats any per-playlist mode).
 *  - Active downloads (Pending/Downloading) are NEVER deleted - the job's
 *    atomic reclaim can flip Failed -> Downloading; retention must never
 *    orphan a live worker.
 *  - Actual deletion (file on disk + row) happens in
 *    `CachedContentRetentionCleanup::handle()` via chunked DELETEs, and
 *    re-checks status AND `never_expire` to close the SELECT/DELETE race.
 *
 * Empty live-set behaviour:
 *  When `evaluate()` / `evaluateForDynamicGroup()` ends up with a live
 *  fingerprint set whose cardinality is empty (the playlist/group has
 *  zero content rows), the scope is considered "no membership at all".
 *  - For evaluate(): we treat the scope as "stale" — every non-pinned
 *    candidate owned by that scope is eligible for deletion, consistent
 *    with the legacy PR #1500 contract (no live = nothing wanted).
 *  - For evaluateForDynamicGroup(): same — explicit empty-set branch
 *    collects every candidate.
 *
 *  The unified helper below documents both branches so this contract is
 *  preserved when extending it.
 */
class CachedContentRetentionService
{
    /**
     * Identify every `CachedContentFile` row that is no longer wanted.
     *
     * Respects per-playlist cache-retention mode: playlists whose effective
     * mode is `never-expire` or `manual` are skipped entirely (their files
     * survive the sweep). The set of "automatic" playlist IDs is resolved
     * via a small subquery against `playlists` so we never load the model
     * list into PHP.
     *
     * Returns a Collection of integer IDs (NOT Eloquent models) so the
     * caller can chunk them into a chunked DELETE without reloading the
     * model.
     *
     * Memory-efficient: streams candidate rows via `lazy(1000)` instead
     * of `->get()`, then groups by (user_id, playlist_id) in memory. The
     * fingerprint rebuild per group runs once and is reused across every
     * cached file in that group.
     *
     * @return Collection<int, int>
     */
    public function evaluate(): Collection
    {
        $autoQuery = $this->automaticPlaylistIdsQuery();

        // Restrict the candidate query to playlists whose effective
        // retention mode is automatic ("time-based"). Rows whose owning
        // playlist chose 'never-expire' or 'manual' are filtered out at
        // the DB layer via a subquery so they don't even enter the lazy
        // iteration. Filtering them in the live-fingerprint resolver
        // would return an empty set, which the contains() check would
        // read as "no live membership → row is stale" and incorrectly
        // delete.
        $query = CachedContentFile::query()
            ->where('never_expire', false)
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ])
            ->where(function ($q) use ($autoQuery): void {
                $q->whereNull('playlist_id')
                    ->orWhereIn('playlist_id', $autoQuery);
            });

        return $this->collectStaleIdsFromCandidateQuery($query, function (?int $userId, ?int $playlistId): Collection {
            return $this->liveFingerprintsForScope($userId ?? -1, $playlistId ?? -1);
        });
    }

    /**
     * Eloquent query builder for playlist IDs whose effective retention
     * mode is automatic ("time-based").
     *
     * Returns a Builder rather than a Collection so callers can compose
     * it inline via `orWhereIn('playlist_id', $builder)`, which compiles
     * to a single SQL subquery. On a multi-thousand-row playlists table
     * this is the difference between one indexed lookup and a
     * full-table PHP filter loop (this project forbids unbounded
     * `->all()` / `->get()` on large tables).
     *
     * Branching on the global default happens in PHP because the default
     * is a PHP value resolved before the query. The two emitted shapes
     * match the rules on `Playlist::effectiveCacheRetentionMode()`:
     *  - Global default (post null/empty normalization) is 'time-based':
     *    a playlist is automatic when its column is NULL, '' (empty), OR
     *    explicitly 'time-based'. ONE indexed scan, three OR branches.
     *  - Otherwise (global 'never-expire' or 'manual'): a playlist is
     *    automatic only when its column is explicitly 'time-based'.
     *
     * `GeneralSettings` is refreshed first so an admin's in-session
     * change to the global default is reflected by the sweep (otherwise
     * the singleton's stale in-memory copy would silently outlive an
     * admin edit).
     */
    public function automaticPlaylistIdsQuery(): Builder
    {
        $raw = app(GeneralSettings::class)->refresh()->cache_retention_mode ?? null;
        $global = (is_string($raw) && $raw !== '') ? $raw : 'time-based';

        if ($global === 'time-based') {
            return Playlist::query()
                ->select('id')
                ->where(function ($q) use ($global): void {
                    $q->whereNull('cache_retention_mode')
                        ->orWhere('cache_retention_mode', '')
                        ->orWhere('cache_retention_mode', $global);
                });
        }

        return Playlist::query()
            ->select('id')
            ->where('cache_retention_mode', 'time-based');
    }

    /**
     * Shared "build live-fingerprint set, stream candidates excluding
     * never_expire/active, collect stale ids" implementation.
     *
     * Accepts an already-built candidate query builder (the caller
     * decides which rows are eligible for evaluation — e.g. via
     * `ownedByDynamicGroup()` for the DG path, or the retention-mode
     * filter for the standalone path) and a closure that resolves the
     * live fingerprint set for the (userId, playlistId) of each
     * candidate. Returning an empty Collection from the closure
     * signals "no live membership in this scope" — every eligible
     * candidate row in that scope is then considered stale.
     *
     * @param  Builder<CachedContentFile>  $query
     * @param  Closure(?int, ?int): Collection<int, string>  $resolveLive
     * @return Collection<int, int>
     */
    protected function collectStaleIdsFromCandidateQuery(Builder $query, Closure $resolveLive): Collection
    {
        $toDelete = collect();
        $liveByScope = [];

        $query
            ->select(['id', 'user_id', 'playlist_id', 'content_fingerprint'])
            ->lazy(1000)
            ->each(function (CachedContentFile $file) use (&$liveByScope, &$toDelete, $resolveLive): void {
                $userId = $file->user_id ?? -1;
                $playlistId = $file->playlist_id ?? -1;
                $key = "{$userId}:{$playlistId}";

                if (! array_key_exists($key, $liveByScope)) {
                    $liveByScope[$key] = $resolveLive($file->user_id, $file->playlist_id);
                }

                if (! $liveByScope[$key]->contains($file->content_fingerprint)) {
                    $toDelete->push((int) $file->id);
                }
            });

        return $toDelete->values();
    }

    /**
     * Build the set of content fingerprints currently live in the given
     * (user, playlist) scope.
     *
     * Memory: cursor()-style iteration via `lazy()` to bound memory for
     * large playlists. Pulls `tvdb_id` from BOTH Channel and the parent
     * Series so the produced fingerprint matches `Channel::cacheFingerprint()` /
     * `Episode::cacheFingerprint()`'s shape exactly.
     *
     * @return Collection<int, string>
     */
    protected function liveFingerprintsForScope(int $userId, int $playlistId): Collection
    {
        if ($playlistId === -1) {
            // Orphan row (NULL playlist_id). It has no live scope to
            // match against, so by definition it's not wanted.
            return collect();
        }

        $fingerprints = collect();

        Channel::query()
            ->where('playlist_id', $playlistId)
            ->when($userId !== -1, fn ($q) => $q->where('user_id', $userId))
            ->select(['id', 'tmdb_id', 'tvdb_id', 'playlist_id'])
            ->lazy(1000)
            ->each(function (Channel $channel) use (&$fingerprints): void {
                $fingerprints->push($channel->cacheFingerprint());
            });

        Episode::query()
            ->where('playlist_id', $playlistId)
            ->when($userId !== -1, fn ($q) => $q->where('user_id', $userId))
            ->with(['series:id,tmdb_id,tvdb_id'])
            ->select(['id', 'playlist_id', 'series_id', 'tmdb_id', 'season', 'episode_num'])
            ->lazy(1000)
            ->each(function (Episode $episode) use (&$fingerprints): void {
                $fingerprints->push($episode->cacheFingerprint());
            });

        return $fingerprints;
    }

    /**
     * Identify every `CachedContentFile` row linked to a single DynamicGroup
     * that is no longer wanted by that group's current membership.
     *
     * Algorithm:
     *  1. Build the live fingerprint set for the group ONCE (one pass
     *     over the group's channels / series->episodes).
     *  2. Load the cached files linked via the pivot with `dropped_at IS NULL`,
     *     excluding `never_expire = true` AND excluding active downloads
     *     so retention can never race a live worker.
     *  3. In-memory compare each file's fingerprint to the live set;
     *     collect the IDs whose fingerprint is no longer present.
     *
     * Empty-set behaviour: when the group's live membership is empty
     * (no channels / series), every non-pinned candidate in scope is
     * considered stale and returned. Empty-Collection live-set → "no
     * membership, nothing wanted".
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

        $autoQuery = $this->automaticPlaylistIdsQuery();

        $query = CachedContentFile::query()
            ->ownedByDynamicGroup($dynamicGroupId)
            ->where('never_expire', false)
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ])
            // Same per-playlist retention-mode filter as evaluate().
            // Files linked into a DG but owned by a playlist that's
            // 'never-expire' or 'manual' are skipped here so the
            // shared helper can't mark them stale via the empty-set branch.
            ->where(function ($q) use ($autoQuery): void {
                $q->whereNull('playlist_id')
                    ->orWhereIn('playlist_id', $autoQuery);
            });

        // Live set resolved once for the whole DG; every candidate row
        // sees the same fingerprint set regardless of its (user, playlist).
        $resolve = function () use ($liveFingerprints): Collection {
            return $liveFingerprints;
        };

        return $this->collectStaleIdsFromCandidateQuery($query, $resolve);
    }

    /**
     * Build the live fingerprint set for one DynamicGroup's current
     * membership. VOD-type groups iterate `$group->channels`; series-type
     * groups iterate `$group->series.episodes`. Eager-loads series.episodes
     * so the inner loop doesn't trip an N+1.
     *
     * @return Collection<int, string>
     */
    private function liveFingerprintsForGroup(DynamicGroup $group): Collection
    {
        $fingerprints = collect();

        if ($group->type === 'vod') {
            $group->loadMissing('channels:id,playlist_id,tmdb_id,tvdb_id,user_id');
            foreach ($group->channels as $channel) {
                $fingerprints->push($channel->cacheFingerprint());
            }
        } elseif ($group->type === 'series') {
            $group->loadMissing('series.episodes');
            foreach ($group->series as $series) {
                $series->loadMissing('episodes:id,series_id,playlist_id,tmdb_id,season,episode_num');
                foreach ($series->episodes as $episode) {
                    $fingerprints->push($episode->cacheFingerprint());
                }
            }
        }

        return $fingerprints;
    }

    /**
     * Delete a set of `CachedContentFile` rows + their on-disk files.
     * Called by `CachedContentRetentionCleanup::handle()` after `evaluate()`.
     *
     * Re-checks both `status` (so we don't orphan a worker that flipped
     * Failed -> Pending/Downloading between SELECT and DELETE) AND
     * `never_expire` (so a row that gets pinned between evaluate() and
     * deleteIds() survives). Without the `never_expire` re-check, a
     * pin-and-evaluate race would silently delete the pinned row.
     *
     * Chunked DELETE via `chunkById` so a multi-thousand-row cleanup
     * doesn't lock the table.
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
        $activeStatuses = [
            CachedContentFileStatus::Pending->value,
            CachedContentFileStatus::Downloading->value,
        ];

        CachedContentFile::query()
            ->whereIn('id', $ids->all())
            ->where('never_expire', false)
            ->whereNotIn('status', $activeStatuses)
            ->chunkById(500, function ($rows) use (&$deleted): void {
                foreach ($rows as $row) {
                    if (! empty($row->file_path)) {
                        try {
                            $disk = $row->resolveStorageDisk();
                            \Storage::disk($disk)->delete($row->file_path);
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
