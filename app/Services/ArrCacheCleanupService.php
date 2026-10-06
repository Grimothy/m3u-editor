<?php

namespace App\Services;

use App\Models\ArrCacheMovie;
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
 * Only movies recorded in `arr_cache_movies` are considered, and a movie is
 * only recorded when auto-cache added it for a rule with the option on, so
 * titles added by hand or already in the library are never touched. Cache
 * Now on a movie, or a Never expire rule holding it, stops tracking it.
 *
 * A movie stays while any of the owner's cache-enabled movie groups holds
 * it, cleanup or not. `left_at` keeps the keep days across runs, with a
 * one-day minimum so a single bad membership refresh can't delete anything.
 */
class ArrCacheCleanupService
{
    /**
     * Run cleanup for every enabled Radarr with tracked movies. With
     * `$dryRun`, nothing is changed.
     *
     * @return array<int, array{integration: string, movie_id: int, tmdb_id: int, title: string}> movies removed (or that would be)
     */
    public function sweep(bool $dryRun = false): array
    {
        $removed = [];

        $integrations = ArrIntegration::query()
            ->whereIn('id', ArrCacheMovie::query()->select('arr_integration_id'))
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
        if ($scope === null) {
            // No rule asks for cleanup: turning the option off stops it.
            return [];
        }

        $removed = [];
        $removeLeftBefore = now()->subDays(max(1, $scope['keep_days']));

        /** @var RadarrService $radarr */
        $radarr = ArrService::make($integration);

        try {
            $tracked = ArrCacheMovie::query()
                ->where('arr_integration_id', $integration->id)
                ->lazyById();

            foreach ($tracked as $movie) {
                if (isset($scope['kept'][$movie->tmdb_id])) {
                    // A Never expire rule holds it, so it's kept for good.
                    if (! $dryRun) {
                        $movie->delete();
                    }

                    continue;
                }

                if (isset($scope['held'][$movie->tmdb_id])) {
                    if (! $dryRun) {
                        $movie->update(['left_at' => null]);
                    }

                    continue;
                }

                if ($movie->left_at === null) {
                    if (! $dryRun) {
                        $movie->update(['left_at' => now()]);
                    }

                    continue;
                }

                if ($movie->left_at->gt($removeLeftBefore)) {
                    continue;
                }

                // Gone from Radarr, or the id now belongs to another movie.
                $library = $radarr->fetchMovie($movie->arr_movie_id);
                if ((int) ($library['tmdbId'] ?? 0) !== $movie->tmdb_id) {
                    if (! $dryRun) {
                        $movie->delete();
                    }

                    continue;
                }

                if (! $dryRun) {
                    if (! $radarr->deleteMovie($movie->arr_movie_id)['ok']) {
                        // Kept; the next run tries again.
                        continue;
                    }

                    $movie->delete();
                }

                $removed[] = [
                    'integration' => $integration->name,
                    'movie_id' => $movie->arr_movie_id,
                    'tmdb_id' => $movie->tmdb_id,
                    'title' => (string) ($library['title'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // Unreachable Radarr: try again next run.
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
     * TMDB ids the user's cache-enabled movie groups hold, and those a
     * Never expire rule holds (as sets), with the longest keep days among
     * its rules with cleanup on. Null when no rule has cleanup on.
     *
     * @return array{held: array<int, true>, kept: array<int, true>, keep_days: int}|null
     */
    private function ownerScope(int $userId): ?array
    {
        $groups = DynamicGroup::query()
            ->where('user_id', $userId)
            ->where('type', 'vod')
            ->with('playlist')
            ->get()
            ->map(fn (DynamicGroup $group): array => [$group, $group->cacheSettings()])
            ->filter(fn (array $pair): bool => $pair[1]['enabled'] ?? false);

        $cleanupRules = $groups->filter(fn (array $pair): bool => $pair[1]['arr_cleanup']);
        if ($cleanupRules->isEmpty()) {
            return null;
        }

        $held = [];
        $kept = [];

        foreach ($groups as [$group, $settings]) {
            // getTmdbId() is how the movie was looked up when it was added.
            foreach ($group->cacheMembers($settings['max_items'])->cursor() as $member) {
                /** @var Channel $member */
                $tmdbId = (int) $member->getTmdbId();
                if ($tmdbId <= 0) {
                    continue;
                }

                $held[$tmdbId] = true;
                if ($settings['never_expire']) {
                    $kept[$tmdbId] = true;
                }
            }
        }

        return [
            'held' => $held,
            'kept' => $kept,
            'keep_days' => $cleanupRules->max(fn (array $pair): int => $pair[1]['keep_days']),
        ];
    }
}
