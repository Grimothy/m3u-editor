<?php

namespace App\Services;

use App\Models\ArrCacheDeparture;
use App\Models\ArrIntegration;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Services\Arr\ArrService;
use App\Services\Arr\RadarrService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes movies from Radarr that dynamic-group auto-cache added, once
 * they've been out of every group for the rule's "Keep after leaving
 * (days)". Opt-in per rule ("Remove from Radarr after leaving").
 *
 * Only movies carrying the integration's cleanup tag are considered, and
 * the tag is only added to movies auto-cache added itself, so titles added
 * by hand or already in the library are never touched. Removing the tag in
 * Radarr opts a movie out.
 *
 * A movie stays while any of the owner's cache-enabled groups holds it,
 * cleanup or not. Departures are recorded in `arr_cache_departures` so keep
 * days survive between runs, with a one-day minimum so a single bad
 * membership refresh can't delete anything.
 */
class ArrCacheCleanupService
{
    /**
     * Run cleanup for every enabled Radarr. With `$dryRun`, nothing is
     * recorded or removed.
     *
     * @return array<int, array{integration: string, movie_id: int, tmdb_id: int, title: string}> movies removed (or that would be)
     */
    public function sweep(bool $dryRun = false): array
    {
        $removed = [];

        $integrations = ArrIntegration::query()
            ->where('type', 'radarr')
            ->enabled()
            ->cursor();

        foreach ($integrations as $integration) {
            array_push($removed, ...$this->sweepIntegration($integration, $dryRun));
        }

        return $removed;
    }

    /**
     * @return array<int, array{integration: string, movie_id: int, tmdb_id: int, title: string}>
     */
    private function sweepIntegration(ArrIntegration $integration, bool $dryRun): array
    {
        $scope = $this->ownerScope((int) $integration->user_id);
        if ($scope['keep_days'] === null) {
            // No rule asks for cleanup: turning the option off stops it.
            return [];
        }

        $removed = [];

        try {
            /** @var RadarrService $radarr */
            $radarr = ArrService::make($integration);

            $tagId = $radarr->cacheCleanupTagId(create: false);
            $movieIds = $tagId !== null ? $radarr->taggedMovieIds($tagId) : [];

            if (! $dryRun) {
                // Movies that lost the tag, or left Radarr, aren't tracked.
                ArrCacheDeparture::query()
                    ->where('arr_integration_id', $integration->id)
                    ->whereNotIn('arr_movie_id', $movieIds)
                    ->delete();
            }

            foreach ($movieIds as $movieId) {
                $movie = $radarr->fetchMovie($movieId);
                if ($movie === null || ! in_array($tagId, array_map('intval', $movie['tags'] ?? []), true)) {
                    continue;
                }

                $tmdbId = (int) ($movie['tmdbId'] ?? 0);
                $departure = ArrCacheDeparture::query()
                    ->where('arr_integration_id', $integration->id)
                    ->where('arr_movie_id', $movieId)
                    ->first();

                if (isset($scope['tmdb_ids'][$tmdbId])) {
                    if (! $dryRun) {
                        $departure?->delete();
                    }

                    continue;
                }

                if ($departure === null) {
                    if (! $dryRun) {
                        ArrCacheDeparture::query()->create([
                            'arr_integration_id' => $integration->id,
                            'arr_movie_id' => $movieId,
                            'tmdb_id' => $tmdbId,
                            'left_at' => now(),
                        ]);
                    }

                    continue;
                }

                if ($departure->left_at->gt(now()->subDays(max(1, $scope['keep_days'])))) {
                    continue;
                }

                $entry = [
                    'integration' => $integration->name,
                    'movie_id' => $movieId,
                    'tmdb_id' => $tmdbId,
                    'title' => (string) ($movie['title'] ?? ''),
                ];

                if ($dryRun) {
                    $removed[] = $entry;

                    continue;
                }

                if (! $radarr->deleteMovie($movieId)['ok']) {
                    // Kept; the next run tries again.
                    continue;
                }

                $departure->delete();
                $removed[] = $entry;
            }
        } catch (Throwable $e) {
            // Unreachable Radarr: change nothing, try again next run.
            Log::warning('ArrCacheCleanup: Radarr unavailable, skipping cleanup', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($removed !== [] && ! $dryRun) {
            Log::info('ArrCacheCleanup: removed '.count($removed).' movies from Radarr.', [
                'integration_id' => $integration->id,
            ]);
        }

        return $removed;
    }

    /**
     * TMDB ids every cache-enabled movie group of the user holds (as a set),
     * and the longest keep days among its rules with cleanup on (null when
     * none has it on).
     *
     * @return array{tmdb_ids: array<int, true>, keep_days: int|null}
     */
    private function ownerScope(int $userId): array
    {
        $tmdbIds = [];
        $keepDays = null;

        $groups = DynamicGroup::query()
            ->where('user_id', $userId)
            ->where('type', '!=', 'series')
            ->with('playlist')
            ->cursor();

        foreach ($groups as $group) {
            $settings = $group->cacheSettings();
            if (! ($settings['enabled'] ?? false)) {
                continue;
            }

            if ($settings['arr_cleanup']) {
                $keepDays = max($keepDays ?? 0, $settings['keep_days']);
            }

            // getTmdbId() is how the movie was looked up when it was added.
            foreach ($group->cacheMembers($settings['max_items'])->cursor() as $member) {
                /** @var Channel $member */
                $tmdbId = (int) $member->getTmdbId();
                if ($tmdbId > 0) {
                    $tmdbIds[$tmdbId] = true;
                }
            }
        }

        return ['tmdb_ids' => $tmdbIds, 'keep_days' => $keepDays];
    }
}
