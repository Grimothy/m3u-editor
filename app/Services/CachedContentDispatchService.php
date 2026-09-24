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
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Standalone per-Channel / per-Episode cache dispatcher.
 *
 * Invariants:
 *  1. Single-row dispatch (one Channel or Episode per call). N calls with
 *     the same fingerprint produce exactly ONE INSERT total because the
 *     2nd..Nth calls hit the existing-row short-circuit.
 *  2. Ownership: every new `CachedContentFile` row stamps BOTH `user_id`
 *     (auth()->id() with a fallback to the source playlist's owner for
 *     the scheduled-orchestrator path where no user is logged in) AND
 *     `playlist_id` (from the source row's playlist).
 *  3. Cross-playlist sharing: the SOURCE playlist owns the file
 *     (write-locked). Other playlists read it only when the source's
 *     `share_cache_across_playlists` toggle is on AND a Completed row
 *     exists for the fingerprint with that source playlist's id.
 *  4. Fingerprint identity: `Channel::cacheFingerprint()` and
 *     `Episode::cacheFingerprint()` are the single source of truth - both
 *     the live dispatch path and the cached fingerprint row derive from
 *     them. No per-call string-building that could drift from the model's
 *     contract.
 */
class CachedContentDispatchService
{
    /**
     * Dispatch a single Channel.
     *
     * Returns a Collection of the DownloadCachedContentFile job(s) actually
     * queued. Empty Collection when:
     *  - The global `enable_cache` setting is off (kill switch)
     *  - The fingerprint already has any row (idempotent re-dispatch is a no-op)
     *  - The Channel has no URL (no work to do)
     *  - Cross-playlist sharing hits an existing Completed row owned by
     *    a sharing-enabled source playlist
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    public function dispatchForChannel(Channel $channel): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $playlist = $channel->playlist;
        if (! $playlist) {
            return collect();
        }

        $fingerprint = $channel->cacheFingerprint();

        if ($this->findSharedCacheHit($fingerprint, $playlist) !== null) {
            return collect();
        }

        return $this->dispatchNew($channel, $playlist, $fingerprint);
    }

    /**
     * Dispatch a single Episode. Identity inputs (tmdb_id/tvdb_id) come
     * from the parent Series via Episode::cacheFingerprint() so the cached
     * row's fingerprint matches the live fingerprint exactly.
     *
     * Returns an empty Collection when:
     *  - The global `enable_cache` setting is off (kill switch)
     *  - The fingerprint already has any row (idempotent re-dispatch is a no-op)
     *  - The Episode has no playlist or URL
     *  - Cross-playlist sharing hits an existing Completed row owned by
     *    a sharing-enabled source playlist
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    public function dispatchForEpisode(Episode $episode): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $playlist = $episode->playlist;
        if (! $playlist) {
            return collect();
        }

        $fingerprint = $episode->cacheFingerprint();

        if ($this->findSharedCacheHit($fingerprint, $playlist) !== null) {
            return collect();
        }

        return $this->dispatchNew($episode, $playlist, $fingerprint);
    }

    /**
     * Dispatch every eligible Channel/Episode in a DynamicGroup's current
     * membership.
     *
     * Behavior:
     *  - type='vod': iterates $group->channels, dispatches each via
     *    dispatchForChannel().
     *  - type='series': iterates $group->series (eager-loaded with
     *    episodes to avoid the N+1) and dispatches each episode via
     *    dispatchForEpisode().
     *  - For each successfully dispatched file, writes a fresh pivot row
     *    in `cached_content_file_dynamic_groups` with `dropped_at = NULL`.
     *    The pivot insert is BATCHED (chunks of 100) and uses
     *    `insertOrIgnore` so concurrent dispatchers racing on the same
     *    (file, group) pair no-op the second insert instead of crashing
     *    on the unique key.
     *
     * Short-circuits before any work when the global `enable_cache`
     * setting is off (kill switch).
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    public function dispatchForGroup(DynamicGroup $group): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $group->loadMissing(['channels', 'series.episodes']);

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
     * Whether the global `enable_cache` toggle in GeneralSettings is on.
     *
     * Centralized here so every public entrypoint (`dispatchForChannel`,
     * `dispatchForEpisode`, `dispatchForGroup`) reads the same way -
     * mirroring the read pattern used in the serve controllers and
     * Filament action visibility guards.
     */
    public function isEnabled(): bool
    {
        return (bool) (app(GeneralSettings::class)->enable_cache ?? false);
    }

    /**
     * Look up an existing CachedContentFile row for an item's content
     * fingerprint, regardless of status. Used by the "Cache Now" UI
     * handlers to disambiguate "dispatcher returned empty because the
     * row is already cached / queued" from a genuine empty result.
     *
     * Prefers a row matching the item's source playlist when one exists;
     * falls back to ANY row for the fingerprint so cross-playlist hits
     * are still surfaced as "Already cached" even when the local playlist
     * has no row of its own.
     */
    public function describeExisting(Channel|Episode $item): ?CachedContentFile
    {
        $fingerprint = $item->cacheFingerprint();
        $playlistId = $item->playlist_id;

        $hit = CachedContentFile::query()
            ->where('content_fingerprint', $fingerprint)
            ->when($playlistId !== null, fn ($q) => $q->where('playlist_id', $playlistId))
            ->first();

        if ($hit) {
            return $hit;
        }

        return CachedContentFile::query()
            ->where('content_fingerprint', $fingerprint)
            ->first();
    }

    /**
     * Build the Filament notification for a "Cache Now" dispatch result.
     *
     * Centralizes the result-to-notification mapping for both the VOD
     * (Channel) and Episode Cache Now action handlers so the wording,
     * severity, and key copies stay in lockstep. The body text is keyed
     * off whether `$item` is a Channel or an Episode; callers must pass
     * the same item they passed to `dispatchCacheNowFor*()`.
     *
     * Severity choices:
     *  - New dispatch queued (success): success toast
     *  - Already cached / already queued (no-op): info toast
     *  - Failure (no URL, kill switch, no row found): danger toast
     *
     * @param  array{queued: bool, already?: 'cached'|'queued', error?: string}  $result
     */
    public static function cacheNowNotification(Channel|Episode $item, array $result): Notification
    {
        if ($result['queued']) {
            $already = $result['already'] ?? null;

            if ($already === 'cached') {
                return Notification::make()
                    ->info()
                    ->title(__('Already cached'))
                    ->body($item instanceof Episode
                        ? __('This episode already has a completed cached file.')
                        : __('This VOD already has a completed cached file.'));
            }

            if ($already === 'queued') {
                return Notification::make()
                    ->info()
                    ->title(__('Already queued for caching'))
                    ->body($item instanceof Episode
                        ? __('A pending or downloading cached file already exists for this episode.')
                        : __('A pending or downloading cached file already exists for this VOD.'));
            }

            return Notification::make()
                ->success()
                ->title(__('Cache download queued'))
                ->body(__('Track progress on the Cached Downloads page.'));
        }

        return Notification::make()
            ->danger()
            ->title(__('Could not queue cache'))
            ->body($result['error'] ?? __('Unknown error.'));
    }

    /**
     * Batch-insert pivot rows linking freshly-dispatched CachedContentFile
     * rows to the source DynamicGroup. Chunks of 100 to keep the SQL
     * payload bounded; uses `insertOrIgnore` so concurrent dispatches for
     * the same (file, group) pair survive the unique index without
     * crashing.
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
     *  - That source playlist's `share_cache_across_playlists` MUST be on.
     *  - The row's status MUST be `Completed`.
     */
    public function isCrossPlaylistDuplicate(string $fingerprint, Playlist $sourcePlaylist): bool
    {
        return $this->findSharedCacheHit($fingerprint, $sourcePlaylist) !== null;
    }

    /**
     * Same logic as `isCrossPlaylistDuplicate` but returns the matching
     * row instead of a boolean. Null when no share-hit exists.
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
     * "N calls, same fingerprint, 1 INSERT" test invariant hold.
     *
     * The INSERT is wrapped in a savepoint (DB::transaction) + a
     * QueryException fallback so a concurrent dispatch racing on the
     * same fingerprint survives: the unique index on `content_fingerprint`
     * rejects the second INSERT, we swallow that specific exception, and
     * re-query the now-existing row. Postgres aborts the surrounding
     * transaction on a failed statement (SQLSTATE 25P02), so the
     * savepoint matters specifically for RefreshDatabase's per-test
     * wrapper.
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    protected function dispatchNew(Channel|Episode $item, Playlist $playlist, string $fingerprint): Collection
    {
        $url = (string) ($item->url ?? '');
        if ($url === '') {
            return collect();
        }

        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        if ($existing) {
            return collect();
        }

        $userId = Auth::id() ?? $playlist->user_id;
        $series = $item instanceof Episode ? $item->series : null;
        $tmdbId = $series?->tmdb_id ?? $item->tmdb_id;
        $tvdbId = $series?->tvdb_id ?? $item->tvdb_id;

        try {
            $row = DB::transaction(function () use ($item, $fingerprint, $playlist, $userId, $tmdbId, $tvdbId): CachedContentFile {
                return CachedContentFile::create([
                    'content_type' => $item instanceof Channel ? 'movie' : 'episode',
                    'tmdb_id' => $tmdbId !== null ? (string) $tmdbId : null,
                    'tvdb_id' => $tvdbId !== null ? (string) $tvdbId : null,
                    'season_number' => $item instanceof Episode ? $item->season : null,
                    'episode_number' => $item instanceof Episode ? $item->episode_num : null,
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

        // Construct the job once and dispatch that same instance. The
        // previous implementation built a `new` instance here AND called
        // `DownloadCachedContentFile::dispatch(...)` with a fresh
        // instance, producing two unrelated objects. We use the global
        // `dispatch()` helper (NOT `DownloadCachedContentFile::dispatch()`)
        // because the latter comes from the Dispatchable trait and would
        // try to re-instantiate the job from its arguments. The job's
        // constructor sets `onQueue('cache')` so the queue assignment
        // is preserved.
        $job = new DownloadCachedContentFile($item, $row->id);
        dispatch($job);

        return collect([$job]);
    }
}
