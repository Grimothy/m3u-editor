<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\User;
use App\Services\Arr\ArrService;
use App\Services\Arr\RadarrService;
use App\Services\Arr\SonarrService;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Hands arr-sourced cache requests back to the provider when the arr can't
 * deliver, and drops rows the arr did deliver.
 *
 * Rows are created by CachedContentArrService when an integration with
 * "Fail back to the provider" sends a movie or single episode to the arr
 * (source='arr'). The sweep — scheduled every 10 minutes — health-checks
 * each such integration, polls it once for its queue and recent history,
 * then decides per row:
 *
 * - terminal download failure (queue `status: failed`, or a
 *   `downloadFailed` history event after the request) -> dispatch the
 *   provider download and unmonitor the title in the arr;
 * - never grabbed within the deadline (DEADLINE_HOURS: not in queue, no
 *   grab/import history, no file) -> same;
 * - in queue / grabbed / waiting -> left alone;
 * - import failed (queue trackedDownloadState) or manual interaction
 *   required (ArrQueueEvent) -> one notification per title, no failback —
 *   the arr may still recover;
 * - the arr has the file -> the tracking row is dropped; playback picks
 *   the title up from the media server exactly like #1585's InArrLibrary
 *   result, which also never kept a Cached Downloads row.
 *
 * The provider dispatch is an atomic conditional update
 * (`whereNull('fallback_dispatched_at')`), so a row can never be dispatched
 * twice — not across concurrent sweeps, and not while the arr keeps
 * working on its own copy.
 */
class ArrCacheFailbackService
{
    /**
     * How long an arr has to grab a title before the provider is used.
     */
    public const DEADLINE_HOURS = 24;

    /**
     * Library snapshots fetched at most once per integration per sweep, so
     * the number of arr calls doesn't grow with the number of tracked rows.
     *
     * @var array<int, array{id: int, tmdbId: int, hasFile: bool, monitored: bool}>|null
     */
    private ?array $movieLibrary = null;

    /**
     * @var array<int, int>|null series id keyed by TVDB id
     */
    private ?array $seriesLibrary = null;

    /**
     * @var array<int, array<int, array{id: int, seasonNumber: int, episodeNumber: int, hasFile: bool}>>
     */
    private array $episodeLibrary = [];

    /**
     * Sweep every integration that opted in and has open arr rows. One
     * health check + one queue + one (paged) history call per integration;
     * rows are then matched against those records.
     */
    public function sweep(): void
    {
        ArrIntegration::query()
            ->enabled()
            ->cacheFailback()
            ->whereHas('cachedContentFiles', fn ($query) => $query->arrTracked())
            ->orderBy('id')
            ->get()
            ->each(function (ArrIntegration $integration): void {
                try {
                    $this->sweepIntegration($integration);
                } catch (\Throwable $e) {
                    // One bad integration or row must never stop the sweep.
                    Log::warning('ArrCacheFailback: integration sweep failed', [
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Drop an arr request without touching the arr's library files. Used
     * when a user cancels an arr-sourced row.
     */
    public function cancelArrRequest(CachedContentFile $row): void
    {
        $this->unmonitor($row);
    }

    private function sweepIntegration(ArrIntegration $integration): void
    {
        $rows = $integration->cachedContentFiles()
            ->arrTracked()
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->movieLibrary = null;
        $this->seriesLibrary = null;
        $this->episodeLibrary = [];

        $service = ArrService::make($integration);

        // fetchQueue()/history swallow HTTP failures into empty results, so
        // an explicit health gate is what keeps a broken arr from ever
        // being read as "never grabbed".
        $health = $service->testConnection();

        if (! ($health['ok'] ?? false)) {
            Log::warning('ArrCacheFailback: arr unreachable, skipping sweep', [
                'integration_id' => $integration->id,
                'error' => $health['error'] ?? null,
            ]);

            return;
        }

        $queue = $service->fetchQueue();
        $history = $service->fetchHistoryPages(
            $rows->min('arr_requested_at')?->subMinute(),
        );

        foreach ($rows as $row) {
            try {
                if ($row->cacheable_type === (new Channel)->getMorphClass()) {
                    /** @var RadarrService $service */
                    $this->sweepMovieRow($integration, $service, $row, $queue, $history);
                } else {
                    /** @var SonarrService $service */
                    $this->sweepEpisodeRow($integration, $service, $row, $queue, $history);
                }
            } catch (\Throwable $e) {
                Log::warning('ArrCacheFailback: row evaluation failed', [
                    'row_id' => $row->getKey(),
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $queue
     * @param  array<int, array<string, mixed>>  $history
     */
    private function sweepMovieRow(ArrIntegration $integration, RadarrService $service, CachedContentFile $row, array $queue, array $history): void
    {
        $tmdbId = (int) ($row->tmdb_id ?? 0);

        if ($this->manualInteractionRequired($integration, (string) $tmdbId, $row) && $this->deadlinePassed($row)) {
            $this->dispatchFailback($row);

            return;
        }

        $queueRecord = collect($queue)->first(
            fn (array $record): bool => (int) ($record['externalId'] ?? 0) === $tmdbId,
        );

        if ($queueRecord !== null) {
            $this->handleQueueRecord($row, $queueRecord);

            return;
        }

        $movie = $this->movieLibrary($service)[$tmdbId] ?? null;

        if ($movie !== null && $movie['hasFile']) {
            $this->dropDeliveredRow($row);

            return;
        }

        $events = $movie === null
            ? []
            : array_values(array_filter(
                $history,
                fn (array $record): bool => $record['movieId'] === $movie['id'],
            ));

        $this->decideFromHistory($row, $events, missingFromLibrary: $movie === null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $queue
     * @param  array<int, array<string, mixed>>  $history
     */
    private function sweepEpisodeRow(ArrIntegration $integration, SonarrService $service, CachedContentFile $row, array $queue, array $history): void
    {
        $tvdbId = (int) ($row->tvdb_id ?? 0);
        $season = (int) ($row->season_number ?? 0);
        $episodeNumber = (int) ($row->episode_number ?? 0);

        if ($this->manualInteractionRequired($integration, (string) $tvdbId, $row) && $this->deadlinePassed($row)) {
            $this->dispatchFailback($row);

            return;
        }

        $queueRecord = collect($queue)->first(
            fn (array $record): bool => (int) ($record['externalId'] ?? 0) === $tvdbId
                && (int) ($record['seasonNumber'] ?? -1) === $season
                && (int) ($record['episodeNumber'] ?? -1) === $episodeNumber,
        );

        if ($queueRecord !== null) {
            $this->handleQueueRecord($row, $queueRecord);

            return;
        }

        $seriesId = $this->seriesLibrary($service)[$tvdbId] ?? null;

        if ($seriesId === null) {
            $this->decideFromHistory($row, [], missingFromLibrary: true);

            return;
        }

        $episode = collect($this->seriesEpisodes($service, $seriesId))
            ->first(fn (array $episode): bool => $episode['seasonNumber'] === $season
                && $episode['episodeNumber'] === $episodeNumber);

        if ($episode !== null && $episode['hasFile']) {
            $this->dropDeliveredRow($row);

            return;
        }

        $episodeId = $episode['id'] ?? null;
        $events = $episodeId === null
            ? []
            : array_values(array_filter(
                $history,
                fn (array $record): bool => $record['episodeId'] === $episodeId,
            ));

        $this->decideFromHistory($row, $events, missingFromLibrary: $episode === null);
    }

    /**
     * The Radarr library keyed by TMDB id, fetched once per sweep.
     *
     * @return array<int, array{id: int, tmdbId: int, hasFile: bool, monitored: bool}>
     */
    private function movieLibrary(RadarrService $service): array
    {
        return $this->movieLibrary ??= collect($service->fetchAllMovies())
            ->keyBy('tmdbId')
            ->all();
    }

    /**
     * Sonarr series ids keyed by TVDB id, fetched once per sweep.
     *
     * @return array<int, int>
     */
    private function seriesLibrary(SonarrService $service): array
    {
        return $this->seriesLibrary ??= collect($service->fetchAllSeries())
            ->pluck('id', 'tvdbId')
            ->all();
    }

    /**
     * A series' episodes, fetched once per series per sweep.
     *
     * @return array<int, array{id: int, seasonNumber: int, episodeNumber: int, hasFile: bool}>
     */
    private function seriesEpisodes(SonarrService $service, int $seriesId): array
    {
        return $this->episodeLibrary[$seriesId] ??= $service->fetchSeriesEpisodes($seriesId);
    }

    /**
     * Whether the request is old enough that the provider should take over.
     */
    private function deadlinePassed(CachedContentFile $row): bool
    {
        return $row->arr_requested_at !== null
            && $row->arr_requested_at->lt(now()->subHours(self::DEADLINE_HOURS));
    }

    /**
     * A ManualInteractionRequired webhook for this title (recorded on the
     * Download Queue surface after the request was sent) warns the user
     * once. It doesn't fall back by itself — the arr may still recover with
     * help — but the deadline still applies if it's never resolved.
     */
    private function manualInteractionRequired(ArrIntegration $integration, string $externalId, CachedContentFile $row): bool
    {
        if ($row->arr_requested_at === null) {
            return false;
        }

        $needsHelp = ArrQueueEvent::query()
            ->where('arr_integration_id', $integration->id)
            ->where('external_id', $externalId)
            ->where('status', 'manual_required')
            ->where('last_event_at', '>', $row->arr_requested_at)
            ->exists();

        if ($needsHelp) {
            $this->notifyArrStalled($row);
        }

        return $needsHelp;
    }

    /**
     * Queue verdicts, in order of specificity: a terminal client failure
     * falls back, an import failure asks the user once, everything else
     * means the arr is still working.
     *
     * @param  array<string, mixed>  $queueRecord
     */
    private function handleQueueRecord(CachedContentFile $row, array $queueRecord): void
    {
        if (($queueRecord['status'] ?? '') === 'failed') {
            $this->dispatchFailback($row);

            return;
        }

        if (($queueRecord['trackedDownloadState'] ?? '') === 'importFailed') {
            $this->notifyArrStalled($row);

            if ($this->deadlinePassed($row)) {
                $this->dispatchFailback($row);
            }
        }
    }

    /**
     * History verdicts for a title that is not in the arr's queue:
     * downloadFailed after the request falls back; a grab or import after
     * the request means the arr is working; silence past the deadline
     * (never grabbed) falls back.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function decideFromHistory(CachedContentFile $row, array $events, bool $missingFromLibrary): void
    {
        $requested = $row->arr_requested_at;

        if ($requested === null) {
            // Defensive: a row without a request timestamp can't be judged.
            return;
        }

        $relevant = array_values(array_filter(
            $events,
            fn (array $record): bool => $record['occurred_at'] !== ''
                && Carbon::parse($record['occurred_at'])->gt($requested),
        ));

        if (collect($relevant)->contains(fn (array $record): bool => $record['eventType'] === 'downloadFailed')) {
            $this->dispatchFailback($row);

            return;
        }

        if (collect($relevant)->contains(fn (array $record): bool => in_array($record['eventType'], ['grabbed', 'downloadFolderImported'], true))) {
            return; // Grabbed or imported after the request: the arr is on it.
        }

        if (($missingFromLibrary || $relevant === []) && $requested->lt(now()->subHours(self::DEADLINE_HOURS))) {
            $this->dispatchFailback($row);
        }
    }

    /**
     * Atomically flip one row to a provider download. The conditional
     * update is the double-download guard: exactly one sweep wins, and a
     * later arr import can never overwrite a provider row. The arr-side
     * request is unmonitored (never deleted) after the switch.
     */
    private function dispatchFailback(CachedContentFile $row): void
    {
        $dispatched = CachedContentFile::query()
            ->whereKey($row->getKey())
            ->arrTracked()
            ->update([
                'source' => 'provider',
                'status' => CachedContentFileStatus::Pending->value,
                'fallback_dispatched_at' => now(),
                'last_error_message' => null,
            ]);

        if ($dispatched !== 1) {
            return; // Another sweep (or a cancel) got here first.
        }

        dispatch(new DownloadCachedContentFile((int) $row->getKey()));

        $this->unmonitor($row);
    }

    /**
     * Mark the arr title unmonitored so it stops consuming the arr's search
     * budget after the provider took over. Failures are logged, never
     * fatal: the provider download is already on its way.
     */
    private function unmonitor(CachedContentFile $row): void
    {
        try {
            $integration = $row->arrIntegration;

            if (! $integration instanceof ArrIntegration) {
                return;
            }

            $item = $row->cacheable;

            if ($item instanceof Channel) {
                /** @var RadarrService $service */
                $service = ArrService::make($integration);
                $movie = $service->fetchMovieByTmdbId((int) ($row->tmdb_id ?? 0));

                if ($movie !== null) {
                    $service->unmonitorMovie((int) $movie['id']);
                }

                return;
            }

            if ($item instanceof Episode) {
                /** @var SonarrService $service */
                $service = ArrService::make($integration);
                $series = $service->fetchSeriesByTvdbId((int) ($row->tvdb_id ?? 0));

                if ($series !== null) {
                    $service->unmonitorEpisode(
                        (int) $series['id'],
                        (int) ($row->season_number ?? 0),
                        (int) ($row->episode_number ?? 0),
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ArrCacheFailback: unmonitor failed (provider download continues)', [
                'row_id' => $row->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The arr delivered: drop our tracking row. Playback picks the title up
     * from the media server exactly like #1585's InArrLibrary result —
     * which also never kept a Cached Downloads row.
     */
    private function dropDeliveredRow(CachedContentFile $row): void
    {
        CachedContentFile::query()
            ->whereKey($row->getKey())
            ->where('source', 'arr')
            ->delete();
    }

    /**
     * One warning per title when the arr is stuck on an import failure or
     * needs manual interaction — repeated sweeps don't re-notify.
     */
    private function notifyArrStalled(CachedContentFile $row): void
    {
        $notified = CachedContentFile::query()
            ->whereKey($row->getKey())
            ->whereNull('fallback_notified_at')
            ->update(['fallback_notified_at' => now()]);

        if ($notified !== 1) {
            return;
        }

        $user = User::find($row->user_id);

        if (! $user) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('Cache download needs attention'))
            ->body(__('":title" needs manual attention in :arr. If it isn\'t resolved within :hours hours it will fall back to the provider.', [
                'title' => $row->title ?? (string) $row->getKey(),
                'arr' => $row->arrIntegration?->isRadarr() ? 'Radarr' : 'Sonarr',
                'hours' => self::DEADLINE_HOURS,
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
