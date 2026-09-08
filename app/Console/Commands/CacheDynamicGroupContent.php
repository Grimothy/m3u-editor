<?php

namespace App\Console\Commands;

use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Walk every playlist's enabled Dynamic Group cache rules and dispatch
 * DownloadCachedContentFile jobs for eligible content. Skips:
 *  - Playlists without enable_proxy=true (caching only applies when proxy is on)
 *  - Playlists without any cache_enabled rule
 *  - Content already completed in CachedContentFile (dedup)
 *  - Content with a Failed row in cooldown
 *  - cache_content_selection='select' (no picker UI yet, deferred to Phase 4)
 *
 * Run on a tight Laravel schedule (every 2 min via routes/console.php); the
 * command itself checks the user-configurable cron string via CronExpression::isDue().
 *
 * Dedup, fingerprint, dispatch, and quality resolution are owned by
 * DynamicGroupCacheDispatchService so the playback-time lazy trigger
 * (Phase 3 — `XtreamStreamController`) shares the same implementation.
 * This command retains only the scheduled-dispatch-specific orchestration:
 * the cron gate, the cursor-over-playlists loop, and the recency-window
 * filter (`cache_content_selection === 'recent'`).
 */
class CacheDynamicGroupContent extends Command
{
    protected $signature = 'app:cache-dynamic-group-content';

    protected $description = 'Walk every playlist\'s Dynamic Group cache rules and dispatch DownloadCachedContentFile jobs for eligible content';

    public function __construct(protected DynamicGroupCacheDispatchService $dispatchService)
    {
        parent::__construct();
    }

    public function handle(GeneralSettings $settings): int
    {
        if (! $settings->enable_dynamic_group_cache) {
            return self::SUCCESS;
        }

        if ($settings->dynamic_group_cache_lazy_load) {
            // Lazy mode fires from playback path (Phase 3), not schedule
            return self::SUCCESS;
        }

        try {
            if (! (new CronExpression($settings->dynamic_group_cache_schedule ?? '0 3 * * *'))->isDue()) {
                return self::SUCCESS; // cron gate
            }
        } catch (\Throwable $e) {
            Log::warning("CacheDynamicGroupContent: invalid cron expression '{$settings->dynamic_group_cache_schedule}': {$e->getMessage()}");

            return self::SUCCESS;
        }

        $count = 0;
        $skipped = 0;

        // Cursor, never ->all()/->get() — playlist table grows with users.
        Playlist::query()
            ->where('enable_proxy', true)
            ->whereNotNull('dynamic_groups_config')
            ->cursor()
            ->each(function (Playlist $playlist) use (&$count, &$skipped): void {
                if (! DynamicGroup::configHasEnabledRule($playlist->dynamic_groups_config)) {
                    $skipped++;

                    return;
                }

                foreach ($playlist->dynamic_groups_config as $rule) {
                    if (! ($rule['cache_enabled'] ?? false)) {
                        continue;
                    }

                    $selection = $rule['cache_content_selection'] ?? 'all';
                    if ($selection === 'select') {
                        Log::debug("CacheDynamicGroupContent: rule '{$rule['name']}' uses cache_content_selection='select' which is no-op until Phase 4 — skipping");

                        continue;
                    }

                    $this->dispatchForRule($playlist, $rule);
                    $count++;
                }
            });

        $this->info("Dispatched DownloadCachedContentFile jobs for {$count} rule(s); skipped {$skipped} playlist(s) without cache-enabled rules.");

        return self::SUCCESS;
    }

    /**
     * For one cache-enabled rule, iterate the rule's materialized DynamicGroup
     * row(s) and dispatch a DownloadCachedContentFile job for each eligible item.
     *
     * The recency filter (`cache_content_selection === 'recent'`) is
     * scheduled-dispatch-specific — the playback-time lazy trigger does
     * not apply it (the user is explicitly watching the content, so
     * "within X days" doesn't apply).
     */
    private function dispatchForRule(Playlist $playlist, array $rule): void
    {
        $groups = DynamicGroup::query()
            ->where('playlist_id', $playlist->id)
            ->where('name', $rule['name'] ?? '')
            ->get();

        if ($groups->isEmpty()) {
            return;
        }

        $selection = $rule['cache_content_selection'] ?? 'all';
        $days = (int) ($rule['cache_content_days'] ?? 30);

        foreach ($groups as $group) {
            if ($group->type === 'vod') {
                foreach ($group->channels as $channel) {
                    if ($selection === 'recent'
                        && ! $this->isWithinRecentWindow(
                            $channel->info['release_date'] ?? null,
                            $days,
                            "channel {$channel->id}"
                        )) {
                        continue;
                    }
                    $this->dispatchService->dispatchForChannel($playlist, $group, $channel, $rule);
                }
            } elseif ($group->type === 'series') {
                foreach ($group->series as $series) {
                    foreach ($series->episodes as $episode) {
                        if ($selection === 'recent') {
                            // Episode: try aio_air_date (real datetime) then fall back to info JSON.
                            // Series: release_date lives in info JSON (no top-level column, intentionally not cast).
                            $release = $episode->aio_air_date?->toDateString()
                                ?? ($episode->info['release_date'] ?? null)
                                ?? ($series->info['release_date'] ?? null);
                            if (! $this->isWithinRecentWindow($release, $days, "episode {$episode->id}")) {
                                continue;
                            }
                        }
                        $this->dispatchService->dispatchForEpisode($playlist, $group, $episode, $rule);
                    }
                }
            }
        }
    }

    /**
     * True when `$releaseDate` parses and falls within the last `$days` days.
     * Returns false for null / unparseable — the recency filter is opt-in via
     * `cache_content_selection === 'recent'`, and a missing release date for
     * a "recent only" rule means "exclude" (no evidence it actually aired
     * within the window).
     */
    private function isWithinRecentWindow(?string $releaseDate, int $days, string $contextLabel): bool
    {
        if (! $releaseDate) {
            return false;
        }

        try {
            $release = new \DateTimeImmutable($releaseDate);
        } catch (\Exception) {
            Log::debug("CacheDynamicGroupContent: unparseable release_date '{$releaseDate}' for {$contextLabel}");

            return false;
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");

        return $release >= $cutoff;
    }
}
