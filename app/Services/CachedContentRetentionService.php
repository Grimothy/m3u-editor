<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
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
 * Retention policy:
 *  - `never_expire = true` rows are kept regardless of scope membership.
 *  - Active downloads (Pending/Downloading) are NEVER deleted - retention
 *    would orphan an in-flight worker mid-write. The job's atomic reclaim
 *    has already flipped Failed -> Downloading but Pending/Downloading
 *    rows are explicitly excluded here so retention can never race a live
 *    worker.
 *  - All other rows whose fingerprint does NOT appear in their scope's
 *    live fingerprint set are eligible for deletion.
 *  - Actual deletion (file on disk + row) happens in
 *    `CachedContentRetentionCleanup::handle()` via chunked DELETEs.
 */
class CachedContentRetentionService
{
    /**
     * Identify every `CachedContentFile` row that is no longer wanted.
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
        $eligibleStatuses = [
            CachedContentFileStatus::Completed->value,
            CachedContentFileStatus::Failed->value,
        ];

        // Stream candidates: exclude never_expire and active downloads at
        // the DB layer so PHP never has to re-check them.
        $toDelete = collect();
        $liveByScope = [];

        CachedContentFile::query()
            ->where('never_expire', false)
            ->whereIn('status', $eligibleStatuses)
            ->select(['id', 'user_id', 'playlist_id', 'content_fingerprint'])
            ->lazy(1000)
            ->each(function (CachedContentFile $file) use (&$liveByScope, &$toDelete): void {
                $userId = $file->user_id ?? -1;
                $playlistId = $file->playlist_id ?? -1;
                $key = "{$userId}:{$playlistId}";

                if (! array_key_exists($key, $liveByScope)) {
                    $liveByScope[$key] = $this->liveFingerprintsForScope($userId, $playlistId);
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
            ->lazy(1000)
            ->each(function (Channel $channel) use (&$fingerprints): void {
                $fingerprints->push($channel->cacheFingerprint());
            });

        Episode::query()
            ->where('playlist_id', $playlistId)
            ->when($userId !== -1, fn ($q) => $q->where('user_id', $userId))
            ->with('series:id,tmdb_id,tvdb_id')
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
     * @return Collection<int, int>
     */
    public function evaluateForDynamicGroup(int $dynamicGroupId): Collection
    {
        $group = DynamicGroup::query()->find($dynamicGroupId);
        if (! $group) {
            return collect();
        }

        $liveFingerprints = $this->liveFingerprintsForGroup($group);

        $query = CachedContentFile::query()
            ->ownedByDynamicGroup($dynamicGroupId)
            ->where('never_expire', false)
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ]);

        if ($liveFingerprints->isEmpty()) {
            $toDelete = collect();

            $query->select('id')
                ->lazy(1000)
                ->each(function (CachedContentFile $file) use (&$toDelete): void {
                    $toDelete->push((int) $file->id);
                });

            return $toDelete;
        }

        $toDelete = collect();

        $query->select(['id', 'content_fingerprint'])
            ->lazy(1000)
            ->each(function (CachedContentFile $file) use ($liveFingerprints, &$toDelete): void {
                if (! $liveFingerprints->contains($file->content_fingerprint)) {
                    $toDelete->push((int) $file->id);
                }
            });

        return $toDelete->values();
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
            $group->loadMissing('channels');
            foreach ($group->channels as $channel) {
                $fingerprints->push($channel->cacheFingerprint());
            }
        } elseif ($group->type === 'series') {
            $group->loadMissing('series.episodes');
            foreach ($group->series as $series) {
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
     * Skips any row whose status has flipped to Pending or Downloading
     * since `evaluate()` decided to delete it - that's the only race
     * window (a worker reclaimed a Failed row between the SELECT and the
     * DELETE). A re-delete attempt would orphan the worker's bytes.
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
