<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Settings\GeneralSettings;

/**
 * Shared dispatch + lookup helpers for the Dynamic Group Cache feature.
 *
 * Extracted from `CacheDynamicGroupContent` (Phase 2) so the scheduled
 * dispatcher and the playback-time lazy trigger (Phase 3) share one
 * implementation of: dedup/cooldown checks, identity+fingerprint build,
 * source-URL resolution, and job dispatch.
 *
 * Stateless — all dependencies are static (PlaylistUrlService, the job's
 * `dispatch` factory, the model's `fingerprintFor` static, and the
 * `GeneralSettings` singleton via the `app()` helper to match the
 * convention already used in CacheDynamicGroupContent).
 */
class DynamicGroupCacheDispatchService
{
    /**
     * Look up a Completed CachedContentFile by content identity only —
     * quality is deliberately ignored at playback time (Phase 3 limitation:
     * a single Channel/Episode being played doesn't carry a queryable
     * "which quality variant" attribute, so the first Completed match wins).
     *
     * Returns null if no Completed match exists.
     */
    public function findCompletedCache(
        string $contentType,
        ?string $tmdbId,
        ?string $tvdbId,
        ?int $seasonNumber,
        ?int $episodeNumber,
    ): ?CachedContentFile {
        return CachedContentFile::query()
            ->where('content_type', $contentType)
            ->where('tmdb_id', $tmdbId)
            ->where('tvdb_id', $tvdbId)
            ->where('season_number', $seasonNumber)
            ->where('episode_number', $episodeNumber)
            ->where('status', CachedContentFileStatus::Completed)
            ->first();
    }

    /**
     * Find a Dynamic Group + cache rule applicable to a Channel at play time.
     *
     * Iterates the channel's already-materialized DynamicGroup memberships
     * (via the polymorphic `dynamic_group_items` pivot), then resolves each
     * group's owning rule from its playlist's `dynamic_groups_config`
     * (matched by rule `name`). Returns the first rule where
     * `cache_enabled === true`.
     *
     * Used by the lazy trigger to decide whether the play request should
     * enqueue a download for missing content.
     *
     * @return array{group: DynamicGroup, rule: array<string, mixed>}|null
     */
    public function findCacheableRuleForChannel(Channel $channel): ?array
    {
        foreach ($channel->dynamicGroups as $group) {
            $rule = $this->resolveRuleForGroup($group);
            if ($rule !== null && ($rule['cache_enabled'] ?? false) === true) {
                return ['group' => $group, 'rule' => $rule];
            }
        }

        return null;
    }

    /**
     * Find a Dynamic Group + cache rule applicable to an Episode at play time.
     *
     * Episodes don't have their own DynamicGroup membership — they go
     * through their parent Series. Mirror what the scheduled dispatcher
     * does at `CacheDynamicGroupContent::dispatchForRule()` so a play
     * and the next scheduled run agree on which group "owns" this content.
     *
     * @return array{group: DynamicGroup, rule: array<string, mixed>}|null
     */
    public function findCacheableRuleForEpisode(Episode $episode): ?array
    {
        $series = $episode->series;
        if (! $series) {
            return null;
        }

        foreach ($series->dynamicGroups as $group) {
            $rule = $this->resolveRuleForGroup($group);
            if ($rule !== null && ($rule['cache_enabled'] ?? false) === true) {
                return ['group' => $group, 'rule' => $rule];
            }
        }

        return null;
    }

    /**
     * Build a DownloadCachedContentFile dispatch for a Channel, with all
     * the standard skip-cooldown / fingerprint / url-resolve checks applied.
     *
     * Does NOT apply the recency filter (`cache_content_selection === 'recent'`)
     * — that is a scheduled-dispatch-only concern. At lazy-trigger time the
     * user is explicitly watching this content, so "within X days" doesn't
     * apply to "the user is watching it right now."
     */
    public function dispatchForChannel(Playlist $playlist, DynamicGroup $group, Channel $channel, array $rule): void
    {
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

    /**
     * Same as dispatchForChannel but for Episode content. Uses the *series'*
     * tmdb_id (matches Phase 2's `CacheDynamicGroupContent::maybeDispatchForEpisode`
     * convention so a fingerprint built here matches what's already cached).
     */
    public function dispatchForEpisode(Playlist $playlist, DynamicGroup $group, Episode $episode, array $rule): void
    {
        $series = $episode->series;
        $tmdbId = ($series && $series->tmdb_id !== null) ? (string) $series->tmdb_id : null;
        $quality = $this->resolveQuality($rule);

        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => 'episode',
            'tmdb_id' => $tmdbId,
            'season_number' => $episode->season,
            'episode_number' => $episode->episode_number,
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

    /**
     * Quality preference read from the per-rule config. Free-text tag
     * (e.g. "4K", "1080p") folded into the content fingerprint so
     * different rules with different quality preferences produce
     * different fingerprint buckets.
     */
    public function resolveQuality(array $rule): ?string
    {
        return $rule['cache_prefer_quality_keyword'] ?? null;
    }

    /**
     * Returns true if we should NOT dispatch — already completed, or
     * failed within its cooldown window.
     */
    public function shouldSkip(string $fingerprint): bool
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

    /**
     * Resolve the rule config for a single DynamicGroup by matching its
     * `name` against the owning playlist's `dynamic_groups_config` array.
     * Returns null if the playlist has no matching rule (e.g. group
     * materialized from a rule that has since been deleted).
     */
    private function resolveRuleForGroup(DynamicGroup $group): ?array
    {
        $playlist = $group->playlist;
        if (! $playlist) {
            return null;
        }

        $config = $playlist->dynamic_groups_config;
        if (! is_array($config)) {
            return null;
        }

        foreach ($config as $rule) {
            if (is_array($rule) && ($rule['name'] ?? null) === $group->name) {
                return $rule;
            }
        }

        return null;
    }

    private function dispatchJob(
        DynamicGroup $group,
        string $contentType,
        ?string $tmdbId,
        ?string $tvdbId,
        ?int $seasonNumber,
        ?int $episodeNumber,
        ?string $quality,
        string $url,
    ): void {
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
