<?php

namespace App\Console\Commands;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\PlaylistUrlService;
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
 *  - cache_content_selection='select' (no picker UI yet, deferred to Phase 3)
 *
 * Run on a tight Laravel schedule (every 2 min via routes/console.php); the
 * command itself checks the user-configurable cron string via CronExpression::isDue().
 */
class CacheDynamicGroupContent extends Command
{
    protected $signature = 'app:cache-dynamic-group-content';

    protected $description = 'Walk every playlist\'s Dynamic Group cache rules and dispatch DownloadCachedContentFile jobs for eligible content';

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
                        Log::debug("CacheDynamicGroupContent: rule '{$rule['name']}' uses cache_content_selection='select' which is no-op until Phase 3 — skipping");

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
                    $this->maybeDispatchForChannel($playlist, $group, $channel, $rule, $selection, $days);
                }
            } elseif ($group->type === 'series') {
                foreach ($group->series as $series) {
                    foreach ($series->episodes as $episode) {
                        $this->maybeDispatchForEpisode($playlist, $group, $series, $episode, $rule, $selection, $days);
                    }
                }
            }
        }
    }

    private function maybeDispatchForChannel(Playlist $playlist, DynamicGroup $group, $channel, array $rule, string $selection, int $days): void
    {
        if ($selection === 'recent') {
            // release_date lives in the info JSON column on Channel (not a top-level column).
            $release = $channel->info['release_date'] ?? null;
            if ($release && ! $this->withinDays($release, $days)) {
                return;
            }
        }

        $tmdbId = $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null;
        $quality = $this->resolveQuality($rule);

        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => 'movie',
            'tmdb_id' => $tmdbId,
            'quality' => $quality,
        ]);

        if ($this->shouldSkip($fingerprint)) {
            return;
        }

        $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
        if (! $url) {
            return;
        }

        $this->dispatchJob($group, 'movie', $tmdbId, null, null, null, $quality, $url);
    }

    private function maybeDispatchForEpisode(Playlist $playlist, DynamicGroup $group, $series, $episode, array $rule, string $selection, int $days): void
    {
        if ($selection === 'recent') {
            // Episode: try aio_air_date (real datetime) then fall back to info JSON.
            // Series: release_date lives in info JSON (no top-level column, intentionally not cast).
            $release = $episode->aio_air_date?->toDateString()
                ?? ($episode->info['release_date'] ?? null)
                ?? ($series->info['release_date'] ?? null);
            if ($release && ! $this->withinDays($release, $days)) {
                return;
            }
        }

        $tmdbId = $series->tmdb_id !== null ? (string) $series->tmdb_id : null;
        $quality = $this->resolveQuality($rule);

        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => 'episode',
            'tmdb_id' => $tmdbId,
            'season_number' => $episode->season,
            'episode_number' => $episode->episode_number, // accessor returns (int) episode_num
            'quality' => $quality,
        ]);

        if ($this->shouldSkip($fingerprint)) {
            return;
        }

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        if (! $url) {
            return;
        }

        $this->dispatchJob($group, 'episode', $tmdbId, null, $episode->season, $episode->episode_number, $quality, $url);
    }

    private function resolveQuality(array $rule): ?string
    {
        return $rule['cache_prefer_quality_keyword'] ?? null;
    }

    private function withinDays(string $releaseDate, int $days): bool
    {
        try {
            $release = new \DateTimeImmutable($releaseDate);
        } catch (\Exception) {
            return false;
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");

        return $release >= $cutoff;
    }

    /**
     * Returns true if we should NOT dispatch — already completed, or
     * failed within its cooldown window.
     */
    private function shouldSkip(string $fingerprint): bool
    {
        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        if (! $existing) {
            return false;
        }

        if ($existing->status === CachedContentFileStatus::Completed) {
            return true; // cross-playlist dedup
        }

        if ($existing->status === CachedContentFileStatus::Failed) {
            // Cooldown: short (retry_cooldown_minutes) for low failure_count,
            // long (failure_cooldown_hours) once we cross 3 failures.
            $settings = app(GeneralSettings::class);
            $cooldownSeconds = (int) $existing->failure_count < 3
                ? ((int) $settings->dynamic_group_cache_retry_cooldown_minutes * 60)
                : ((int) $settings->dynamic_group_cache_failure_cooldown_hours * 3600);

            return $existing->last_failed_at && $existing->last_failed_at->addSeconds($cooldownSeconds)->isFuture();
        }

        return false; // Pending or Downloading — proceed (no harm, job handles concurrency)
    }

    private function dispatchJob(DynamicGroup $group, string $contentType, ?string $tmdbId, ?string $tvdbId, ?int $seasonNumber, ?int $episodeNumber, ?string $quality, string $url): void
    {
        DownloadCachedContentFile::dispatch(
            $group,
            $contentType,
            $tmdbId,
            $tvdbId,
            $seasonNumber,
            $episodeNumber,
            $quality,
            $url,
        )->onQueue('dynamic-group-cache');
    }
}
