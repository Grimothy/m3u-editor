<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CachedContentSource;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\MonitorArrSearch;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\Arr\ArrService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues cache downloads for VOD channels and series episodes.
 *
 * Every entry point (Cache Now actions, the series "Cache all episodes"
 * action, `cache:content`, and the downloads widget's Retry) goes through
 * `dispatch()` / `requeue()` so the rules live in one place:
 *  - Nothing happens while `enable_cache` is off.
 *  - Only plain Playlist VOD channels and episodes with a non-HLS source
 *    URL can be cached.
 *  - An item has at most one cached file (unique on cacheable). A
 *    Completed file whose bytes are missing on disk, or a Failed one, is
 *    re-queued rather than reported as done.
 *  - A playable copy shared by another of the same user's playlists
 *    (`share_cache_across_playlists`) counts as already cached.
 *
 * Dynamic-group auto-caching enters through `dispatchForDynamicGroup()`,
 * which calls `dispatch(automatic: true)` per member: rows it creates are
 * stamped `managed_by = CachedContentManagedBy::DynamicGroup` so group
 * retention may release them later, members with an eligible media-server
 * match are skipped (local media always wins), and recently failed rows
 * are not re-queued (cooldown below).
 */
class CachedContentDispatchService
{
    /**
     * Hours after a failure before the automatic (dynamic-group) path may
     * re-queue an item. Manual Cache Now / Retry re-queue immediately.
     */
    public const AUTO_RETRY_COOLDOWN_HOURS = 24;

    /**
     * How often dispatchForDynamicGroup() re-queries the group's tracked
     * bytes while enforcing the rule's max-GB budget.
     */
    private const BUDGET_RECHECK_EVERY = 25;

    /** @var array<string, int> */
    private array $resolvedTvdbIds = [];

    /** @var array<int, Playlist> */
    private array $routablePlaylists = [];

    /**
     * Whether the global `enable_cache` toggle is on.
     */
    public function isEnabled(): bool
    {
        return (bool) (app(GeneralSettings::class)->enable_cache ?? false);
    }

    /**
     * Whether `$item` can be cached at all (ignores the global toggle).
     */
    public function canCache(Channel|Episode $item): bool
    {
        if ($item instanceof Channel && ! $item->is_vod) {
            return false;
        }

        if (! $item->playlist_id || ! $item->playlist instanceof Playlist) {
            return false;
        }

        $url = $item->cacheSourceUrl();
        if ($url === '') {
            return false;
        }

        // HLS manifests can't be cached as a single file.
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return ! str_ends_with($path, '.m3u8');
    }

    /**
     * Queue a download for one channel or episode, routing through the
     * arr stack when it applies.
     *
     * `$automatic` marks the dynamic-group path: rows it creates are
     * `managed_by = CachedContentManagedBy::DynamicGroup`, and a Failed row
     * inside the auto-retry cooldown returns CoolingDown instead of
     * re-queueing. Manual callers (the default) adopt an existing
     * group-managed row, turning it manual so group retention never
     * deletes it afterwards.
     *
     * `$providerOnly` forces the provider leg (arr fallbacks). Dynamic
     * groups route themselves via `dispatchForDynamicGroup()`.
     */
    public function dispatch(Channel|Episode $item, bool $automatic = false, bool $providerOnly = false): CacheDispatchResult
    {
        if (! $this->isEnabled()) {
            return CacheDispatchResult::Disabled;
        }

        // Dynamic groups route themselves (dispatchForDynamicGroup); fallbacks force the provider.
        if ($automatic || $providerOnly || ! $item->playlist instanceof Playlist) {
            return $this->dispatchProvider($item, $automatic);
        }

        $plan = $this->resolveMethod($item->playlist, $item instanceof Channel ? 'movie' : 'series');
        if ($plan['method'] !== 'arr') {
            return $this->dispatchProvider($item, false);
        }

        $target = $item instanceof Episode ? $item->series : $item;
        $seasons = $item instanceof Episode ? [(int) $item->season] : null;
        $arr = $target ? $this->dispatchArr($target, $plan['integration'], $seasons, automatic: false) : CacheDispatchResult::Unavailable;

        return $this->providerFallbackFor($arr, fn (): CacheDispatchResult => $this->dispatchProvider($item, false));
    }

    /**
     * Arr results that mean "arr can't deliver, use the provider".
     */
    private function shouldFallBack(CacheDispatchResult $arr): bool
    {
        return in_array($arr, [CacheDispatchResult::Unavailable, CacheDispatchResult::ArrMonitoredFallback], true);
    }

    /**
     * Run the provider when arr can't deliver, and report it as an arr fallback when the provider queued.
     *
     * @param  callable(): CacheDispatchResult  $provider
     */
    private function providerFallbackFor(CacheDispatchResult $arr, callable $provider): CacheDispatchResult
    {
        if (! $this->shouldFallBack($arr)) {
            return $arr;
        }

        $result = $provider();
        if ($result !== CacheDispatchResult::Queued) {
            return $result;
        }

        return $arr === CacheDispatchResult::ArrMonitoredFallback
            ? CacheDispatchResult::ArrMonitoredFallback
            : CacheDispatchResult::ArrFallbackQueued;
    }

    /**
     * Which cache method applies, and which arr integration to use.
     *
     * Order: rule override (when not 'global') → global setting. Arr needs an
     * integration (rule override → setting → the only enabled one) and the
     * playlist's "Prefer media server sources" on; otherwise it's provider.
     *
     * @param  'movie'|'series'  $contentType
     * @param  array<string, mixed>|null  $ruleSettings  DynamicGroup::cacheSettings() output
     * @return array{method: 'provider'|'arr', integration: ?ArrIntegration}
     */
    public function resolveMethod(Playlist $playlist, string $contentType, ?array $ruleSettings = null): array
    {
        $playlist = $this->routablePlaylist($playlist);

        $method = ($ruleSettings['method'] ?? 'global') !== 'global'
            ? $ruleSettings['method']
            : (app(GeneralSettings::class)->cache_primary_method ?: 'provider');

        if ($method !== 'arr' || ! $playlist->prefer_media_server_sources) {
            return ['method' => 'provider', 'integration' => null];
        }

        $arrType = $contentType === 'movie' ? 'radarr' : 'sonarr';
        $options = app(ContentRequestService::class)->cacheIntegrations((int) $playlist->user_id, $arrType);

        $wantedId = $ruleSettings['arr_integration_id']
            ?? (app(GeneralSettings::class)->{"cache_{$arrType}_integration_id"} ?? null);
        $integration = $wantedId ? $options->firstWhere('id', $wantedId) : null;
        $integration ??= $options->count() === 1 ? $options->first() : null;

        if (! $integration) {
            return ['method' => 'provider', 'integration' => null];
        }

        return ['method' => 'arr', 'integration' => $integration];
    }

    /**
     * The playlist with every column routing needs. Tables eager-load
     * playlists with a narrow select, and a missing attribute silently
     * reads as null, which would quietly route arr requests to the
     * provider. Reloads once per playlist per service instance.
     */
    private function routablePlaylist(Playlist $playlist): Playlist
    {
        $attributes = $playlist->getAttributes();
        if (array_key_exists('prefer_media_server_sources', $attributes) && array_key_exists('user_id', $attributes)) {
            return $playlist;
        }

        return $this->routablePlaylists[$playlist->getKey()] ??= Playlist::query()->findOrFail($playlist->getKey());
    }

    /**
     * The user's arr row for this title on this integration, whichever playlist's item it was created for.
     */
    public function findArrRow(int $userId, CachedContentSource $source, int $integrationId, int $externalId): ?CachedContentFile
    {
        return CachedContentFile::query()
            ->where('user_id', $userId)
            ->where('source', $source->value)
            ->where('arr_integration_id', $integrationId)
            ->where($source === CachedContentSource::Radarr ? 'tmdb_id' : 'tvdb_id', (string) $externalId)
            ->first();
    }

    /**
     * The arr external id for an item: TMDB (movies) or TVDB (series; resolved from TMDB via
     * Sonarr when missing). 0 = none. Resolutions are memoized per request so one dispatch
     * doesn't ask Sonarr twice.
     */
    private function resolveArrExternalId(Channel|Series $item, ArrIntegration $integration): int
    {
        if ($item instanceof Channel) {
            return (int) ($item->tmdb_id ?? 0);
        }

        if ((int) ($item->tvdb_id ?? 0) > 0) {
            return (int) $item->tvdb_id;
        }

        if ((int) $item->tmdb_id <= 0) {
            return 0;
        }

        $key = "{$integration->id}:{$item->tmdb_id}";

        return $this->resolvedTvdbIds[$key]
            ??= (int) (ArrService::make($integration)->resolveTvdbIdFromTmdb((int) $item->tmdb_id) ?? 0);
    }

    /**
     * Ask Radarr (Channel) or Sonarr (Series) for a title on behalf of the cache. IDs only, never titles.
     *
     * Returns ArrRequested / AlreadyQueued / AlreadyCached / ArrAlreadyAvailable when arr has it handled,
     * or Unavailable / ArrMonitoredFallback when the caller must fall back to the provider.
     *
     * @param  array<int, int>|null  $seasons  Sonarr only; null = all seasons
     */
    public function dispatchArr(Channel|Series $item, ArrIntegration $integration, ?array $seasons = null, bool $automatic = false): CacheDispatchResult
    {
        // 1. External id, resolved by ID only (never by title). A series with
        // no tvdb_id but a tmdb_id asks Sonarr to translate once; the
        // resolved id is used everywhere below but never written back.
        $isMovie = $item instanceof Channel;
        $source = $isMovie ? CachedContentSource::Radarr : CachedContentSource::Sonarr;
        $externalId = $this->resolveArrExternalId($item, $integration);

        if ($externalId <= 0) {
            return CacheDispatchResult::Unavailable;
        }

        // 2. The title's playlist.
        $playlist = $item->playlist;
        if (! $playlist instanceof Playlist) {
            return CacheDispatchResult::Unavailable;
        }
        $playlist = $this->routablePlaylist($playlist);

        // 3. Existing arr row (dedup across playlists).
        $row = $this->findArrRow((int) $playlist->user_id, $source, $integration->id, $externalId);
        if ($row) {
            if (! $automatic && $row->managed_by === CachedContentManagedBy::DynamicGroup) {
                // Manual dispatch adopts a group-created row.
                $row->forceFill(['managed_by' => null])->save();
            }

            if (! $isMovie && $row->arr_seasons !== null) {
                // The row covers only some seasons; widen it.
                if ($seasons !== null) {
                    $toMonitor = array_values(array_diff($seasons, $row->arr_seasons));
                } else {
                    // "All seasons": every season Sonarr knows about except specials (0).
                    $available = array_keys(ArrService::make($integration)->fetchEpisodeData($row->arr_library_id)['status']);
                    $toMonitor = array_values(array_diff($available, [0], $row->arr_seasons));
                }

                if ($toMonitor !== []) {
                    foreach ($toMonitor as $season) {
                        $monitorResult = ArrService::make($integration)->monitorSeasonAndSearch($row->arr_library_id, (int) $season);
                        if (! ($monitorResult['ok'] ?? false)) {
                            // Stop at the first failure: no partial widening.
                            return CacheDispatchResult::Unavailable;
                        }
                    }

                    $updates = ['arr_seasons' => $seasons !== null
                        ? array_values(array_unique(array_merge($row->arr_seasons, $toMonitor)))
                        : null];
                    if ($seasons !== null) {
                        sort($updates['arr_seasons']);
                    }

                    if (in_array($row->status, [CachedContentFileStatus::Imported, CachedContentFileStatus::Completed], true)) {
                        $updates['status'] = CachedContentFileStatus::Requested;
                    }

                    $row->update($updates);
                }
            }

            return match (true) {
                $row->status === CachedContentFileStatus::Failed => CacheDispatchResult::Unavailable,
                in_array($row->status, [CachedContentFileStatus::Requested, CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true) => CacheDispatchResult::AlreadyQueued,
                default => CacheDispatchResult::AlreadyCached,
            };
        }

        // 4. Claim a row first — the unique index on (cacheable, source) is
        // the lock, so a concurrent dispatch of the same item loses here.
        try {
            $row = CachedContentFile::create([
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'cacheable_type' => $item->getMorphClass(),
                'cacheable_id' => $item->getKey(),
                'content_type' => $isMovie ? 'movie' : 'series',
                'tmdb_id' => $isMovie
                    ? (string) $externalId
                    : (($item->tmdb_id ?? null) ? (string) $item->tmdb_id : null),
                'tvdb_id' => $isMovie ? null : (string) $externalId,
                'title' => mb_substr((string) ($isMovie ? $item->display_title : $item->name), 0, 500),
                'status' => CachedContentFileStatus::Requested,
                'source' => $source,
                'arr_integration_id' => $integration->id,
                'arr_seasons' => $isMovie ? null : $seasons,
                'managed_by' => $automatic ? CachedContentManagedBy::DynamicGroup : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent dispatch claimed the row first.
            return CacheDispatchResult::AlreadyQueued;
        }

        // 5. Ask the arr integration.
        $result = app(ContentRequestService::class)->requestForCache($integration, $isMovie ? 'movie' : 'series', $externalId, $seasons);

        // 6. Already in the arr library: release our claim — we never own
        // pre-existing arr titles — and report what the caller should do.
        if (($result['code'] ?? null) === 'already_available') {
            $row->delete();

            if ($isMovie) {
                return ($result['has_file'] ?? false)
                    ? CacheDispatchResult::ArrAlreadyAvailable
                    : CacheDispatchResult::ArrMonitoredFallback;
            }

            $status = ArrService::make($integration)->fetchEpisodeData($result['library_id'])['status'];
            foreach ($seasons ?? array_keys($status) as $season) {
                if (! in_array(true, $status[$season] ?? [], true)) {
                    return CacheDispatchResult::ArrMonitoredFallback;
                }
            }

            return CacheDispatchResult::ArrAlreadyAvailable;
        }

        // 7. Any other failure: record it and let the caller fall back now.
        if (! ($result['ok'] ?? false)) {
            $row->update([
                'status' => CachedContentFileStatus::Failed,
                'last_error_message' => $result['error'] ?? __('Radarr/Sonarr could not accept this title.'),
                'last_failed_at' => now(),
                'failure_count' => $row->failure_count + 1,
                'fallback_dispatched_at' => now(),
            ]);

            return CacheDispatchResult::Unavailable;
        }

        // 8. Accepted: link the row to the request and schedule the search check.
        $row->update([
            'media_request_id' => $result['media_request']->id,
            'arr_library_id' => $result['library_id'],
        ]);

        MonitorArrSearch::dispatch(
            $integration->id,
            $result['library_id'],
            (string) $row->title,
            (int) $playlist->user_id,
            $row->id,
        )->delay(now()->addSeconds(30));

        return CacheDispatchResult::ArrRequested;
    }

    /**
     * Queue a provider download for one channel or episode.
     *
     * `$automatic` marks the dynamic-group path: rows it creates are
     * `managed_by = CachedContentManagedBy::DynamicGroup`, and a Failed row
     * inside the auto-retry cooldown returns CoolingDown instead of
     * re-queueing. Manual callers (the default) adopt an existing
     * group-managed row, turning it manual so group retention never
     * deletes it afterwards.
     */
    private function dispatchProvider(Channel|Episode $item, bool $automatic): CacheDispatchResult
    {
        if (! $this->isEnabled()) {
            return CacheDispatchResult::Disabled;
        }

        if (! $this->canCache($item)) {
            return CacheDispatchResult::Unavailable;
        }

        $existing = $item->cachedContentFile()->first();

        if ($existing) {
            if (! $automatic && $existing->managed_by === CachedContentManagedBy::DynamicGroup) {
                // Manual Cache Now on a group-created file adopts it.
                $existing->forceFill(['managed_by' => null])->save();
            }

            if (in_array($existing->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
                return CacheDispatchResult::AlreadyQueued;
            }

            if ($existing->isPlayable()) {
                return CacheDispatchResult::AlreadyCached;
            }

            if (
                $automatic
                && $existing->status === CachedContentFileStatus::Failed
                && $existing->last_failed_at !== null
                && $existing->last_failed_at->isAfter(now()->subHours(self::AUTO_RETRY_COOLDOWN_HOURS))
            ) {
                return CacheDispatchResult::CoolingDown;
            }

            // Failed, or Completed with the file missing on disk.
            return $this->requeue($existing) ? CacheDispatchResult::Queued : CacheDispatchResult::Unavailable;
        }

        $shared = CachedContentFile::findServableFor($item);
        if ($shared?->isPlayable()) {
            return CacheDispatchResult::AlreadyCached;
        }

        /** @var Playlist $playlist */
        $playlist = $item->playlist;

        try {
            $file = CachedContentFile::create([
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'cacheable_type' => $item->getMorphClass(),
                'cacheable_id' => $item->getKey(),
                'content_type' => $item instanceof Channel ? 'movie' : 'episode',
                'tmdb_id' => $this->identityValue($item, 'tmdb_id'),
                'tvdb_id' => $this->identityValue($item, 'tvdb_id'),
                'season_number' => $item instanceof Episode ? $item->season : null,
                'episode_number' => $item instanceof Episode ? $item->episode_num : null,
                'content_fingerprint' => $item->cacheFingerprint(),
                'title' => $this->resolveTitle($item),
                'status' => CachedContentFileStatus::Pending,
                'managed_by' => $automatic ? CachedContentManagedBy::DynamicGroup : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent dispatch created the row first.
            return CacheDispatchResult::AlreadyQueued;
        }

        dispatch(new DownloadCachedContentFile($file->id));

        return CacheDispatchResult::Queued;
    }

    /**
     * Queue every episode of a series. Returns how many items ended up in
     * each result bucket.
     *
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchSeries(Series $series): array
    {
        $counts = $this->emptyCounts();

        if (! $this->isEnabled()) {
            $counts[CacheDispatchResult::Disabled->value] = 1;

            return $counts;
        }

        // Manual "Cache all episodes" asks Sonarr for the whole series first;
        // the per-episode loop only runs when Sonarr can't deliver.
        $providerOnly = false;
        $fallbackBucket = null;

        $plan = $series->playlist instanceof Playlist
            ? $this->resolveMethod($series->playlist, 'series')
            : ['method' => 'provider', 'integration' => null];
        if ($plan['method'] === 'arr') {
            $arr = $this->dispatchArr($series, $plan['integration'], null, automatic: false);
            if (! $this->shouldFallBack($arr)) {
                $counts[$arr->value]++;

                return $counts;
            }
            $providerOnly = true;
            $fallbackBucket = $arr === CacheDispatchResult::ArrMonitoredFallback
                ? CacheDispatchResult::ArrMonitoredFallback
                : CacheDispatchResult::ArrFallbackQueued;
        }

        $playlist = $series->playlist;

        foreach ($series->episodes()->orderBy('season')->orderBy('episode_num')->cursor() as $episode) {
            // cursor() can't eager load; the series and its playlist are the
            // same for every episode, so hand them over directly.
            $episode->setRelation('series', $series);
            if ($playlist && (int) $episode->playlist_id === (int) $playlist->id) {
                $episode->setRelation('playlist', $playlist);
            }

            $result = $this->dispatch($episode, providerOnly: $providerOnly);
            if ($fallbackBucket !== null && $result === CacheDispatchResult::Queued) {
                $result = $fallbackBucket;
            }

            $counts[$result->value]++;
        }

        return $counts;
    }

    /**
     * Queue every cacheable channel or episode in `$items` and aggregate the
     * result counts (bulk Cache Now entry point).
     *
     * @param  iterable<Channel|Episode>  $items
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchMany(iterable $items): array
    {
        $counts = $this->emptyCounts();

        foreach ($items as $item) {
            $counts[$this->dispatch($item)->value]++;
        }

        return $counts;
    }

    /**
     * Run `dispatchSeries()` for every series in `$series` and merge the
     * counts into one summary (bulk Cache all episodes entry point).
     *
     * @param  iterable<Series>  $series
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchManySeries(iterable $series): array
    {
        $counts = $this->emptyCounts();

        foreach ($series as $item) {
            foreach ($this->dispatchSeries($item) as $bucket => $count) {
                $counts[$bucket] += $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> keyed by CacheDispatchResult value, all zero
     */
    private function emptyCounts(): array
    {
        return array_fill_keys(array_map(fn (CacheDispatchResult $r): string => $r->value, CacheDispatchResult::cases()), 0);
    }

    /**
     * Queue cache downloads for every member of a dynamic group whose rule
     * has caching enabled. VOD rules cache each member channel; series
     * rules cache only the latest season (highest episodes.season) of each
     * member series. Members are processed in dynamic_group_items.position
     * (TMDB rank) order so the caps keep the top-ranked items.
     *
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchForDynamicGroup(DynamicGroup $group): array
    {
        $counts = $this->emptyCounts();

        if (! $this->isEnabled()) {
            $counts[CacheDispatchResult::Disabled->value] = 1;

            return $counts;
        }

        $settings = DynamicGroup::cacheSettings($group->ruleFromConfig());
        if ($settings === null) {
            return $counts;
        }

        $playlist = $group->playlist;
        $isSeries = $group->type === 'series';

        // The rule's cache-method override (falling back to the global
        // setting) decides whether members go through the arr stack first.
        $plan = $this->resolveMethod($playlist, $isSeries ? 'series' : 'movie', $settings);

        $members = $isSeries ? $group->series() : $group->channels();
        $members->orderByPivot('position');
        if ($settings['max_items'] !== null) {
            $members->limit((int) $settings['max_items']);
        }

        // Soft max-GB budget: stop queueing new items once this group's
        // tracked bytes (Completed file_size_bytes + in-flight
        // bytes_expected, 0 when unknown) reach the limit. Freshly queued
        // rows have no bytes_expected yet, so the cap is enforced across
        // runs, not within the first one — max_items is the hard limit.
        // Re-checked every BUDGET_RECHECK_EVERY items, not once per item.
        $trackedBytes = $settings['max_bytes'] === null ? 0 : null;
        $itemsSinceCheck = 0;

        $budgetReached = function () use ($group, $settings, &$trackedBytes, &$itemsSinceCheck): bool {
            if ($settings['max_bytes'] === null) {
                return false;
            }

            if ($trackedBytes === null || $itemsSinceCheck >= self::BUDGET_RECHECK_EVERY) {
                $trackedBytes = (int) $group->cachedContentFiles()
                    ->where('cached_content_files.source', CachedContentSource::Provider->value)
                    ->sum(DB::raw('COALESCE(cached_content_files.file_size_bytes, cached_content_files.bytes_expected, 0)'));
                $itemsSinceCheck = 0;
            }

            return $trackedBytes >= $settings['max_bytes'];
        };

        foreach ($members->cursor() as $member) {
            // cursor() can't eager load; every member shares the group's
            // playlist, so hand it over directly.
            $member->setRelation('playlist', $playlist);

            if (! $isSeries) {
                if ($budgetReached()) {
                    return $this->finishGroupDispatch($group, $counts);
                }

                if ($plan['method'] === 'arr') {
                    $arr = $this->dispatchGroupArrItem($member, $group, $settings, $plan['integration'], null, $counts);
                    if (! $this->shouldFallBack($arr)) {
                        $itemsSinceCheck++;

                        continue;
                    }
                }

                $this->dispatchGroupItem($member, $group, $settings, $counts);
                $itemsSinceCheck++;

                continue;
            }

            // Series rules cache only the latest season of each series.
            $latestSeason = (int) $member->episodes()->max('season');

            if ($plan['method'] === 'arr') {
                $arr = $this->dispatchGroupArrItem($member, $group, $settings, $plan['integration'], [$latestSeason], $counts);
                if (! $this->shouldFallBack($arr)) {
                    // Arr took the series; skip the episode loop entirely.
                    continue;
                }
            }

            foreach ($member->episodes()->where('season', $latestSeason)->orderBy('episode_num')->cursor() as $episode) {
                if ($budgetReached()) {
                    return $this->finishGroupDispatch($group, $counts);
                }

                $episode->setRelation('series', $member);
                $episode->setRelation('playlist', $playlist);

                $this->dispatchGroupItem($episode, $group, $settings, $counts);
                $itemsSinceCheck++;
            }
        }

        return $this->finishGroupDispatch($group, $counts);
    }

    /**
     * Dispatch one in-scope group member and attach (or refresh) its
     * provenance row carrying the rule's retention snapshot.
     *
     * Local media always wins: a member with an eligible media-server
     * match is skipped entirely — no download, no provenance row — and
     * keeps playing from the media server. The skipped member counts
     * toward neither the budget nor new downloads (top N is applied to
     * the member list before this).
     *
     * @param  array{retention: string, retention_days: int, max_items: int|null, max_bytes: int|null}  $settings
     * @param  array<string, int>  $counts
     */
    private function dispatchGroupItem(Channel|Episode $item, DynamicGroup $group, array $settings, array &$counts): void
    {
        if (app(MediaSourcePreferenceService::class)->hasEligibleMatch($item)) {
            $counts[CacheDispatchResult::MediaServerAvailable->value]++;

            return;
        }

        $counts[$this->dispatch($item, automatic: true)->value]++;

        $file = $item->cachedContentFile()->first();
        if (($file?->managed_by ?? null) !== CachedContentManagedBy::DynamicGroup) {
            // Manual files and shared sibling-playlist copies (no row on
            // this item) are not group-managed; never attach to them.
            return;
        }

        // syncWithoutDetaching refreshes the snapshot and clears dropped_at
        // when an item re-enters scope, and is how a renamed rule's new
        // group adopts the old group's files (their NULL-group pivot rows
        // are then released under their own snapshots). Adoption timing:
        // SyncDynamicGroups::runSync() materializes the renamed rule's new
        // group (queueing this fan-out job) and deletes the old group in
        // the same run, so adoption is queued at the moment the old rows
        // go NULL. It does NOT rely on cron ordering — the daily refresh
        // runs at 04:15, after the 03:00 retention sweep; NULL-group rows
        // carry a 24h floor grace (see
        // CachedContentRetentionService::deleteReleasedPivotRows) so a
        // same-day sweep can't race the queue.
        $file->dynamicGroups()->syncWithoutDetaching([
            $group->id => [
                'retention' => $settings['retention'],
                'retention_days' => $settings['retention_days'],
                'dropped_at' => null,
            ],
        ]);

        if ($settings['retention'] === 'never_expire') {
            // Pin the file: retention never deletes it. The pivot row stays
            // so the group budget still counts it.
            $file->forceFill(['managed_by' => null])->save();
        }
    }

    /**
     * Arr leg for one group member (a Channel, or a Series with its latest
     * season). Attaches the same provenance pivot as dispatchGroupItem()
     * when the arr row is group-managed.
     *
     * @param  array<string, mixed>  $settings  DynamicGroup::cacheSettings() output
     * @param  array<int, int>|null  $seasons
     * @param  array<string, int>  $counts
     */
    private function dispatchGroupArrItem(Channel|Series $item, DynamicGroup $group, array $settings, ArrIntegration $integration, ?array $seasons, array &$counts): CacheDispatchResult
    {
        $source = $item instanceof Channel ? CachedContentSource::Radarr : CachedContentSource::Sonarr;
        $externalId = $this->resolveArrExternalId($item, $integration);

        // Look the row up by identity, not via this item's relation: the
        // shared row may belong to another playlist's item (cross-playlist
        // dedup) and must still get this group's pivot.
        $existing = $externalId > 0
            ? $this->findArrRow((int) $group->user_id, $source, $integration->id, $externalId)
            : null;

        if (! $existing) {
            $probe = $item instanceof Channel
                ? $item
                : $item->episodes()->whereIn('season', $seasons ?? [])->orderBy('episode_num')->first();
            if ($probe !== null && app(MediaSourcePreferenceService::class)->hasEligibleMatch($probe)) {
                $counts[CacheDispatchResult::MediaServerAvailable->value]++;

                return CacheDispatchResult::MediaServerAvailable;
            }
        }

        $result = $this->dispatchArr($item, $integration, $seasons, automatic: true);
        if (! $this->shouldFallBack($result)) {
            // Fallback results are counted by the provider leg instead.
            $counts[$result->value]++;
        }

        $row = $externalId > 0
            ? $this->findArrRow((int) $group->user_id, $source, $integration->id, $externalId)
            : null;
        if (($row?->managed_by ?? null) === CachedContentManagedBy::DynamicGroup) {
            // Same provenance attach + never_expire pin as dispatchGroupItem().
            $row->dynamicGroups()->syncWithoutDetaching([
                $group->id => [
                    'retention' => $settings['retention'],
                    'retention_days' => $settings['retention_days'],
                    'dropped_at' => null,
                ],
            ]);

            if ($settings['retention'] === 'never_expire') {
                $row->forceFill(['managed_by' => null])->save();
            }
        }

        return $result;
    }

    /**
     * Arr reported an error for $arrRow: download the item from the provider instead. Runs once per row.
     *
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function fallbackToProvider(CachedContentFile $arrRow): array
    {
        $counts = $this->emptyCounts();

        // Atomic once-guard: only the caller that flips the marker proceeds,
        // so a webhook burst and a cron tick can't double-dispatch.
        $claimed = CachedContentFile::query()
            ->whereKey($arrRow->id)
            ->whereNull('fallback_dispatched_at')
            ->update(['fallback_dispatched_at' => now()]);

        if ($claimed !== 1) {
            return $counts;
        }

        if ($arrRow->managed_by === CachedContentManagedBy::DynamicGroup) {
            // Group-managed: go by identity, not $arrRow->cacheable — a
            // shared row's cacheable may be another playlist's item (or
            // already deleted). Each live group re-derives the row's
            // member items.
            foreach ($arrRow->dynamicGroups()->get() as $group) {
                $settings = DynamicGroup::cacheSettings($group->ruleFromConfig());
                if ($settings === null) {
                    continue;
                }

                foreach ($this->arrRowMembersOf($group, $arrRow) as $item) {
                    $this->dispatchGroupItem($item, $group, $settings, $counts);
                }
            }

            return $counts;
        }

        $cacheable = $arrRow->cacheable;
        if ($cacheable === null) {
            return $counts;
        }

        // Manual branch: the row's own items go straight to the provider.
        $items = [];
        if ($cacheable instanceof Channel) {
            $items = [$cacheable];
        } elseif ($cacheable instanceof Series) {
            // cursor() can't eager load; the series and its playlist are the
            // same for every episode, so hand them over directly.
            $playlist = $cacheable->playlist;
            foreach (
                $cacheable->episodes()
                    ->when($arrRow->arr_seasons !== null, fn ($q) => $q->whereIn('season', $arrRow->arr_seasons))
                    ->orderBy('season')
                    ->orderBy('episode_num')
                    ->cursor() as $episode
            ) {
                $episode->setRelation('series', $cacheable);
                if ($playlist && (int) $episode->playlist_id === (int) $playlist->id) {
                    $episode->setRelation('playlist', $playlist);
                }

                $items[] = $episode;
            }
        }

        foreach ($items as $item) {
            $counts[$this->dispatch($item, providerOnly: true)->value]++;
        }

        return $counts;
    }

    /**
     * This group's in-scope member items that a (possibly shared) arr row stands for, matched by
     * identity: VOD → member channels with the row's tmdb_id; series → episodes (row's arr_seasons,
     * all if null) of member series whose tvdb_id or tmdb_id matches the row.
     *
     * @return iterable<Channel|Episode>
     */
    public function arrRowMembersOf(DynamicGroup $group, CachedContentFile $arrRow): iterable
    {
        $playlist = $group->playlist;

        if ($group->type !== 'series') {
            foreach ($group->channels()->where('tmdb_id', $arrRow->tmdb_id)->cursor() as $channel) {
                $channel->setRelation('playlist', $playlist);

                yield $channel;
            }

            return;
        }

        // The matched member series are few (the group's in-scope top-N);
        // keyed by id so each streamed episode can receive its series
        // relation without a query (cursor() can't eager load).
        $seriesById = $group->series()
            ->where(fn ($q) => $q->where('tvdb_id', $arrRow->tvdb_id)
                ->when($arrRow->tmdb_id, fn ($q) => $q->orWhere('tmdb_id', $arrRow->tmdb_id)))
            ->get()
            ->keyBy('id');

        $episodes = Episode::query()
            ->whereIn('series_id', $seriesById->keys())
            ->when($arrRow->arr_seasons !== null, fn ($q) => $q->whereIn('season', $arrRow->arr_seasons))
            ->orderBy('season')
            ->orderBy('episode_num')
            ->cursor();

        foreach ($episodes as $episode) {
            $episode->setRelation('series', $seriesById->get($episode->series_id));
            $episode->setRelation('playlist', $playlist);

            yield $episode;
        }
    }

    /**
     * Stamp dropped_at for files that just left the group's scope, then
     * hand the counts back.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function finishGroupDispatch(DynamicGroup $group, array $counts): array
    {
        app(CachedContentRetentionService::class)->markDroppedForGroup($group);

        return $counts;
    }

    /**
     * Reset a Failed (or missing-file Completed) row to Pending and queue it
     * again. Returns false when the row's source item no longer exists or
     * can't be cached.
     */
    public function requeue(CachedContentFile $file): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($file->isArr()) {
            return false;
        }

        $item = $file->cacheable;
        if (! $item instanceof Channel && ! $item instanceof Episode) {
            return false;
        }

        if (! $this->canCache($item)) {
            return false;
        }

        $file->deleteStoredFile();

        $file->forceFill([
            'status' => CachedContentFileStatus::Pending,
            'file_path' => null,
            'file_size_bytes' => null,
            'failure_count' => 0,
            'last_failed_at' => null,
            'last_error_message' => null,
            'bytes_downloaded' => null,
            'bytes_expected' => null,
            'bytes_per_second' => null,
            'last_progress_at' => null,
        ])->save();

        dispatch(new DownloadCachedContentFile($file->id));

        return true;
    }

    /**
     * Filament notification for a single-item Cache Now result.
     */
    public static function cacheNowNotification(Channel|Episode $item, CacheDispatchResult $result): Notification
    {
        $isEpisode = $item instanceof Episode;
        $arr = $isEpisode ? 'Sonarr' : 'Radarr';

        return match ($result) {
            CacheDispatchResult::Queued => Notification::make()
                ->success()
                ->title(__('Cache download queued'))
                ->body(__('Track progress on the Cached Downloads page.')),
            CacheDispatchResult::AlreadyCached => Notification::make()
                ->info()
                ->title(__('Already cached'))
                ->body($isEpisode
                    ? __('This episode already has a completed cached file.')
                    : __('This VOD already has a completed cached file.')),
            CacheDispatchResult::AlreadyQueued => Notification::make()
                ->info()
                ->title(__('Already queued for caching'))
                ->body($isEpisode
                    ? __('A pending or downloading cached file already exists for this episode.')
                    : __('A pending or downloading cached file already exists for this VOD.')),
            CacheDispatchResult::Disabled => Notification::make()
                ->warning()
                ->title(__('Could not queue cache'))
                ->body(__('Caching is disabled in Settings.')),
            CacheDispatchResult::Unavailable => Notification::make()
                ->danger()
                ->title(__('Could not queue cache'))
                ->body($isEpisode
                    ? __('This episode has no cacheable source URL.')
                    : __('This VOD has no cacheable source URL.')),
            CacheDispatchResult::CoolingDown => Notification::make()
                ->info()
                ->title(__('Recently failed'))
                ->body(__('This item failed recently; automatic caching will retry it later.')),
            CacheDispatchResult::MediaServerAvailable => Notification::make()
                ->info()
                ->title(__('Available on your media server'))
                ->body(__('This item already exists on your media server, so it was not cached.')),
            CacheDispatchResult::ArrRequested => Notification::make()
                ->success()
                ->title(__('Sent to :arr', ['arr' => $arr]))
                ->body($item->display_title),
            CacheDispatchResult::ArrAlreadyAvailable => Notification::make()
                ->info()
                ->title(__('Already in :arr', ['arr' => $arr])),
            CacheDispatchResult::ArrMonitoredFallback => Notification::make()
                ->info()
                ->title(__('Already waiting in :arr', ['arr' => $arr]))
                ->body(__('Downloading from the provider now so you can watch it.')),
            CacheDispatchResult::ArrFallbackQueued => Notification::make()
                ->warning()
                ->title(__(':arr couldn\'t take it', ['arr' => $arr]))
                ->body(__('Downloading from the provider instead.')),
        };
    }

    /**
     * Filament notification summarizing a multi-item cache run: a series
     * "Cache all episodes" (`$episodes` true) or a bulk VOD Cache Now.
     *
     * @param  array<string, int>  $counts
     */
    public static function summaryNotification(array $counts, bool $episodes = true): Notification
    {
        if (($counts[CacheDispatchResult::Disabled->value] ?? 0) > 0) {
            return Notification::make()
                ->warning()
                ->title(__('Could not queue cache'))
                ->body(__('Caching is disabled in Settings.'));
        }

        // Provider downloads that really were queued, including the two
        // arr fallback buckets (arr couldn't deliver, provider queued).
        $queued = ($counts[CacheDispatchResult::Queued->value] ?? 0)
            + ($counts[CacheDispatchResult::ArrMonitoredFallback->value] ?? 0)
            + ($counts[CacheDispatchResult::ArrFallbackQueued->value] ?? 0);
        $skipped = ($counts[CacheDispatchResult::AlreadyCached->value] ?? 0)
            + ($counts[CacheDispatchResult::AlreadyQueued->value] ?? 0)
            + ($counts[CacheDispatchResult::CoolingDown->value] ?? 0)
            + ($counts[CacheDispatchResult::MediaServerAvailable->value] ?? 0);
        $unavailable = $counts[CacheDispatchResult::Unavailable->value] ?? 0;

        $arrLines = [];
        $arrRequested = $counts[CacheDispatchResult::ArrRequested->value] ?? 0;
        if ($arrRequested > 0) {
            $arrLines[] = __(':count sent to Radarr/Sonarr', ['count' => $arrRequested]);
        }

        $arrAvailable = $counts[CacheDispatchResult::ArrAlreadyAvailable->value] ?? 0;
        if ($arrAvailable > 0) {
            $arrLines[] = __(':count already in your arr library', ['count' => $arrAvailable]);
        }

        $arrFallback = ($counts[CacheDispatchResult::ArrMonitoredFallback->value] ?? 0)
            + ($counts[CacheDispatchResult::ArrFallbackQueued->value] ?? 0);
        if ($arrFallback > 0) {
            $arrLines[] = __(':count downloading from the provider instead', ['count' => $arrFallback]);
        }

        $notification = Notification::make();
        ($queued + $arrRequested) > 0 ? $notification->success() : $notification->info();

        $title = ($queued === 0 && $arrRequested > 0)
            ? __('Sent :count to Radarr/Sonarr', ['count' => $arrRequested])
            : ($episodes
                ? match (true) {
                    $queued === 0 => __('No episodes queued'),
                    $queued === 1 => __('Queued 1 episode for caching'),
                    default => __('Queued :count episodes for caching', ['count' => $queued]),
                }
                : match (true) {
                    $queued === 0 => __('No VODs queued'),
                    $queued === 1 => __('Queued 1 VOD for caching'),
                    default => __('Queued :count VODs for caching', ['count' => $queued]),
                });

        $body = __(':skipped already cached or queued, :unavailable without a cacheable source.', [
            'skipped' => $skipped,
            'unavailable' => $unavailable,
        ]);

        if ($arrLines !== []) {
            $body .= ' '.implode(' ', $arrLines);
        }

        return $notification
            ->title($title)
            ->body($body);
    }

    /**
     * TMDB/TVDB id stored on the row. Episodes carry their series' ids.
     */
    private function identityValue(Channel|Episode $item, string $column): ?string
    {
        $value = $item instanceof Episode
            ? ($item->series?->{$column} ?? ($column === 'tmdb_id' ? $item->tmdb_id : null))
            : $item->{$column};

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Display title persisted on the row so the downloads table never has to
     * look it up again.
     */
    private function resolveTitle(Channel|Episode $item): ?string
    {
        $title = trim((string) $item->display_title);

        if ($item instanceof Episode) {
            $seriesName = trim((string) $item->series?->name);
            $code = sprintf('S%02dE%02d', (int) $item->season, (int) $item->episode_num);
            $title = trim(implode(' - ', array_filter([$seriesName, $code, $title])));
        }

        return $title === '' ? null : mb_substr($title, 0, 500);
    }
}
