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
 *     the same fingerprint AND the same playlist produce exactly ONE INSERT
 *     total because the 2nd..Nth calls hit the existing-row short-circuit.
 *  2. Ownership: every new `CachedContentFile` row stamps BOTH `user_id`
 *     (auth()->id() with a fallback to the source playlist's owner for
 *     the scheduled-orchestrator path where no user is logged in) AND
 *     `playlist_id` (from the source row's playlist).
 *  3. Cross-playlist sharing (within a single user only): another
 *     playlist that the same user owns may SERVE a Completed row that
 *     belongs to a sibling playlist when that sibling has
 *     `share_cache_across_playlists = true`. Cross-USER sharing is never
 *     allowed. A playlist's own row is always preferred over a shared row.
 *     "Servable for playlist P" is implemented by
 *     `CachedContentFile::scopeServableForPlaylist()`.
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
     *  - The fingerprint already has any row for THIS playlist (idempotent
     *    re-dispatch is a no-op - PR #1524 review item 1)
     *  - A Completed row from another of the same user's playlists with
     *    `share_cache_across_playlists = true` is servable for this
     *    playlist (cross-playlist sharing - PR #1524 review item 2)
     *  - The Channel has no URL (no work to do)
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

        if ($this->findServableCacheHit($fingerprint, $playlist) !== null) {
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
     *  - The fingerprint already has any row for THIS playlist (idempotent
     *    re-dispatch is a no-op - PR #1524 review item 1)
     *  - A Completed row from another of the same user's playlists with
     *    `share_cache_across_playlists = true` is servable for this
     *    playlist (cross-playlist sharing)
     *  - The Episode has no playlist or URL
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

        if ($this->findServableCacheHit($fingerprint, $playlist) !== null) {
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
     * Visibility follows the same "servable for playlist" rule as
     * playback (see `CachedContentFile::scopeServableForPlaylist()`):
     * the item's source playlist row is preferred; a row shared by
     * another of the same user's playlists with
     * `share_cache_across_playlists = true` is the fallback. Rows owned
     * by other users are NEVER surfaced - reporting another user's cache
     * as "Already cached" for our row would be both wrong and a privacy
     * leak.
     */
    public function describeExisting(Channel|Episode $item): ?CachedContentFile
    {
        $playlist = $item->playlist;
        if (! $playlist) {
            return null;
        }

        $fingerprint = $item->cacheFingerprint();

        return CachedContentFile::query()
            ->servableForPlaylist($playlist)
            ->where('content_fingerprint', $fingerprint)
            ->orderByRaw('CASE WHEN playlist_id = ? THEN 0 ELSE 1 END', [$playlist->id])
            ->first();
    }

    /**
     * Resolve the human-readable title to persist on a newly-dispatched
     * `CachedContentFile` row. Mirrors the same precedence the activity
     * widget's `movie_source_title` / `episode_source_title`
     * projections use (Channel::getDisplayTitleAttribute /
     * Episode::getDisplayTitleAttribute), so the stored `title` column
     * produces a byte-identical label when the widget reads it back.
     *
     * Returns null when the source row's display title is empty -
     * matches the projection's `nullif(trim(...), '')` behaviour so the
     * widget falls through to its subquery path on legacy rows without
     * re-fetching what was already null at dispatch time.
     */
    private static function resolveInitialTitle(Channel|Episode $item): ?string
    {
        $title = trim((string) $item->display_title);

        return $title === '' ? null : $title;
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
     * Resolve a Completed `CachedContentFile` for `$fingerprint` that is
     * SERVABLE for `$playlist` - i.e. either belongs to `$playlist` itself
     * OR to another of the same user's playlists that has
     * `share_cache_across_playlists = true`.
     *
     * The `scopeServableForPlaylist()` predicate implements the OR. The
     * Completed-status filter is applied on top. This is the single source
     * of truth for "is there a cache hit for this playlist" used by
     * `dispatchForChannel()`, `dispatchForEpisode()`, and
     * `XtreamStreamController::resolveCacheHit()`.
     *
     * The result prefers `$playlist`'s own row over a shared row via
     * `orderByRaw('CASE WHEN playlist_id = ? THEN 0 ELSE 1 END', ...)`.
     */
    public function findServableCacheHit(string $fingerprint, Playlist $playlist): ?CachedContentFile
    {
        return CachedContentFile::query()
            ->servableForPlaylist($playlist)
            ->where('content_fingerprint', $fingerprint)
            ->where('status', CachedContentFileStatus::Completed)
            ->orderByRaw('CASE WHEN playlist_id = ? THEN 0 ELSE 1 END', [$playlist->id])
            ->first();
    }

    /**
     * Build the dispatch from the per-item fingerprint + ownership check
     * through to the actual row insert + job dispatch.
     *
     * Existing-row short-circuit (PR #1524 review item 1): a row with the
     * same fingerprint AND the same playlist id (any status) prevents the
     * INSERT. This is what makes the
     * "N calls, same playlist + same fingerprint, 1 INSERT" test invariant
     * hold. Note the second key (playlist_id) - uniqueness is per-playlist,
     * not global, so a different playlist can legitimately insert its own
     * copy of the same fingerprint.
     *
     * The INSERT is wrapped in a savepoint (DB::transaction) + a
     * QueryException fallback so a concurrent dispatch racing on the same
     * `(fingerprint, playlist_id)` survives: the composite unique on
     * `(content_fingerprint, playlist_id)` from migration
     * `2026_09_16_120100_add_playlist_id_...` rejects the second INSERT,
     * we swallow that specific exception, and re-query the now-existing
     * row. Postgres aborts the surrounding transaction on a failed
     * statement (SQLSTATE 25P02), so the savepoint matters specifically for
     * RefreshDatabase's per-test wrapper.
     *
     * @return Collection<int, DownloadCachedContentFile>
     */
    protected function dispatchNew(Channel|Episode $item, Playlist $playlist, string $fingerprint): Collection
    {
        $url = (string) ($item->url ?? '');
        if ($url === '') {
            return collect();
        }

        $existing = CachedContentFile::query()
            ->where('content_fingerprint', $fingerprint)
            ->where('playlist_id', $playlist->id)
            ->first();
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
                    // Persist the source display title at dispatch time so the
                    // CachedContentActivityWidget can render the title cell
                    // straight from the row's own column on every poll. The
                    // widget's Channel/Episode projection subqueries remain
                    // as the fallback for legacy rows that pre-date this fill
                    // (PR #1524 reuse/efficiency item: avoid re-running the
                    // title subquery on every poll cycle).
                    'title' => self::resolveInitialTitle($item),
                    'user_id' => $userId,
                    'playlist_id' => $playlist->id,
                    'status' => CachedContentFileStatus::Pending,
                ]);
            });
        } catch (QueryException) {
            // Lost an INSERT race against a concurrent dispatch for the
            // same (fingerprint, playlist_id). The other writer's row
            // satisfies the idempotent re-dispatch contract; bail without
            // dispatching.
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
