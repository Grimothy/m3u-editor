<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentSource;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Series;
use App\Services\Arr\ArrService;
use App\Services\Arr\RadarrService;
use App\Services\CachedContentDispatchService;
use App\Services\ContentRequestService;
use App\Services\MediaSourcePreferenceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Mirror Radarr/Sonarr queue progress into arr Cached Downloads rows, and
 * fall back to the provider when arr reports a problem. Triggered by arr
 * webhooks and a every-minute schedule sweep; unique per integration.
 */
class SyncArrCachedDownloads implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    /**
     * Set when a row imports this run, so the media server is asked to
     * rescan once after the sweep instead of once per title.
     */
    private bool $mediaServerRefreshNeeded = false;

    /**
     * Radarr's tmdbId => file/availability map, fetched lazily once per sweep.
     *
     * @var array<int, array{has_file: bool, available: bool}>|null
     */
    private ?array $radarrLibrary = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $arrIntegrationId,
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-arr-cached-'.$this->arrIntegrationId;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ContentRequestService $requests,
        CachedContentDispatchService $dispatcher,
        MediaSourcePreferenceService $media,
    ): void {
        $integration = ArrIntegration::find($this->arrIntegrationId);
        if (! $integration || ! $integration->enabled) {
            return;
        }

        $rows = CachedContentFile::query()
            ->arr()
            ->where('arr_integration_id', $integration->id)
            ->whereIn('status', [
                CachedContentFileStatus::Requested,
                CachedContentFileStatus::Pending,
                CachedContentFileStatus::Downloading,
                CachedContentFileStatus::Imported,
            ]);

        if (! (clone $rows)->exists()) {
            return;
        }

        $queue = rescue(fn (): array => ArrService::make($integration)->fetchQueue(), null, report: false);
        if ($queue === null) {
            Log::warning('Content request status lookup failed', [
                'integration_id' => $integration->id,
            ]);

            return;
        }

        // cursor() can't eager load, so this iterates by id chunks instead.
        foreach ($rows->with('mediaRequest')->lazyById(100) as $row) {
            if ($row->status === CachedContentFileStatus::Imported) {
                $this->handleImportedRow($row, $media);

                continue;
            }

            $progress = $row->mediaRequest
                ? $requests->resolveProgress($row->mediaRequest, $queue, aggregateEpisodes: $row->source === CachedContentSource::Sonarr)
                : null;
            $progress ??= $this->libraryImportProgress($row, $integration);
            if ($progress === null) {
                if ($this->radarrWaitingForRelease($row, $integration)) {
                    $row->forceFill([
                        'status' => CachedContentFileStatus::Failed,
                        'last_failed_at' => now(),
                        'failure_count' => $row->failure_count + 1,
                        'last_error_message' => __(':arr won\'t download this until it is released. Downloading from the provider instead.', [
                            'arr' => $row->source->getLabel(),
                        ]),
                    ])->save();

                    $dispatcher->fallbackToProvider($row);
                }

                continue;
            }

            // Error first: any failed/warning/manual state means arr can't
            // deliver — fail the row and queue the provider copy instead.
            $errorState = in_array($progress['status'], ['failed', 'warning', 'manual_required'], true)
                || in_array($progress['tracked_state'], ['importBlocked', 'importFailed', 'failed', 'failedPending'], true);

            if ($errorState) {
                $row->forceFill([
                    'status' => CachedContentFileStatus::Failed,
                    'last_failed_at' => now(),
                    'failure_count' => $row->failure_count + 1,
                    'last_error_message' => __(':arr reported a problem (:state). Downloading from the provider instead.', [
                        'arr' => $row->source->getLabel(),
                        'state' => $progress['tracked_state'] ?? $progress['status'],
                    ]),
                ])->save();

                $dispatcher->fallbackToProvider($row);

                continue;
            }

            $attributes = [];

            match ($progress['status']) {
                'imported', 'completed' => $this->applyImported($row, $progress, $attributes, $requests, $integration, $media),
                'downloading', 'importing', 'import_pending' => $attributes['status'] = CachedContentFileStatus::Downloading,
                'queued', 'delay', 'paused', 'grabbing' => $attributes['status'] = CachedContentFileStatus::Pending,
                'monitored' => $attributes['status'] = CachedContentFileStatus::Requested,
                default => null,
            };

            if (in_array($attributes['status'] ?? null, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
                $attributes['bytes_expected'] = $progress['size'];
                $newDownloaded = max(0, $progress['size'] - $progress['size_left']);
                $attributes['bytes_downloaded'] = $newDownloaded;

                // Speed needs a previous sample; without one it stays unset.
                if ($row->bytes_downloaded !== null && $row->last_progress_at !== null) {
                    $seconds = $row->last_progress_at->diffInSeconds(now());
                    if ($seconds > 0) {
                        $attributes['bytes_per_second'] = max(0, (int) (($newDownloaded - $row->bytes_downloaded) / $seconds));
                    }
                }

                $attributes['last_progress_at'] = now();
            }

            if ($attributes !== []) {
                $row->forceFill($attributes)->save();
            }
        }

        // One rescan per sweep, however many titles imported.
        if ($this->mediaServerRefreshNeeded) {
            $integration->requestMediaServerRefresh();
        }
    }

    /**
     * A Pending/Downloading row that left arr's queue with no webhook event
     * to say why: arr drops a title from its queue once it imports it, so
     * without a webhook the queue alone would leave the row stuck at
     * Downloading. Ask the library instead, and report an import when the
     * title's files are there. Null means "no news" (still unknown).
     *
     * @return array{status: string, tracked_state: ?string, progress: int, size: int, size_left: int, quality: ?string, protocol: ?string, time_left: ?string, can_persist_completed: bool}|null
     */
    private function libraryImportProgress(CachedContentFile $row, ArrIntegration $integration): ?array
    {
        if (! in_array($row->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
            return null;
        }

        $imported = $row->source === CachedContentSource::Radarr
            ? $this->radarrHasFile($row, $integration)
            : $this->sonarrHasTrackedSeasons($row, $integration);

        if (! $imported) {
            return null;
        }

        $size = (int) ($row->bytes_expected ?? 0);

        return [
            'status' => 'imported',
            'tracked_state' => null,
            'progress' => 100,
            'size' => $size,
            'size_left' => 0,
            'quality' => null,
            'protocol' => null,
            'time_left' => null,
            'can_persist_completed' => true,
        ];
    }

    /**
     * Whether Radarr has a file for the row's movie.
     */
    private function radarrHasFile(CachedContentFile $row, ArrIntegration $integration): bool
    {
        return $this->radarrMovieStatus($row, $integration)['has_file'] ?? false;
    }

    /**
     * A Requested Radarr row whose movie hasn't reached its minimum
     * availability (e.g. still in cinemas): Radarr won't search until it
     * does, which can be weeks, so the provider should take it now.
     */
    private function radarrWaitingForRelease(CachedContentFile $row, ArrIntegration $integration): bool
    {
        if ($row->source !== CachedContentSource::Radarr || $row->status !== CachedContentFileStatus::Requested) {
            return false;
        }

        $status = $this->radarrMovieStatus($row, $integration);

        return $status !== null && ! $status['has_file'] && ! $status['available'];
    }

    /**
     * The row's movie in Radarr's library, or null when it isn't there (or
     * Radarr couldn't be reached). The library is fetched at most once per
     * sweep, and only when a row needs it.
     *
     * @return array{has_file: bool, available: bool}|null
     */
    private function radarrMovieStatus(CachedContentFile $row, ArrIntegration $integration): ?array
    {
        if ($this->radarrLibrary === null) {
            $service = ArrService::make($integration);
            $this->radarrLibrary = $service instanceof RadarrService
                ? rescue(fn (): array => $service->fetchLibraryStatus(), [], report: false)
                : [];
        }

        return $this->radarrLibrary[(int) $row->tmdb_id] ?? null;
    }

    /**
     * Whether every season the row tracks (all but specials when it tracks
     * the whole series) has at least one episode file in Sonarr.
     */
    private function sonarrHasTrackedSeasons(CachedContentFile $row, ArrIntegration $integration): bool
    {
        if ((int) $row->arr_library_id <= 0) {
            return false;
        }

        $status = rescue(fn (): array => ArrService::make($integration)->fetchEpisodeData((int) $row->arr_library_id)['status'], [], report: false);
        $seasons = $row->arr_seasons ?? array_values(array_diff(array_keys($status), [0]));

        if ($seasons === []) {
            return false;
        }

        foreach ($seasons as $season) {
            if (! in_array(true, $status[$season] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An Imported row either became playable on the media server
     * (Completed) or has been sitting unwatched for a day (surface why).
     */
    private function handleImportedRow(CachedContentFile $row, MediaSourcePreferenceService $media): void
    {
        $probe = $this->playableProbe($row);

        if ($probe !== null && $media->hasEligibleMatch($probe)) {
            $row->forceFill([
                'status' => CachedContentFileStatus::Completed,
                'last_error_message' => null,
            ])->save();

            return;
        }

        if ($row->last_progress_at?->lt(now()->subDay()) && blank($row->last_error_message)) {
            $row->forceFill([
                'last_error_message' => __('Imported by :arr but not found on any media server. Check that its root folder is in a Plex, Jellyfin or Emby library.', [
                    'arr' => $row->source->getLabel(),
                ]),
            ])->save();
        }
    }

    /**
     * Arr imported the title: record its size, complete the underlying
     * request, ask the media server to rescan, then check playability.
     *
     * @param  array{status: string, tracked_state: ?string, progress: int, size: int, size_left: int, quality: ?string, protocol: ?string, time_left: ?string, can_persist_completed: bool}  $progress
     * @param  array<string, mixed>  $attributes
     */
    private function applyImported(
        CachedContentFile $row,
        array $progress,
        array &$attributes,
        ContentRequestService $requests,
        ArrIntegration $integration,
        MediaSourcePreferenceService $media,
    ): void {
        $attributes['status'] = CachedContentFileStatus::Imported;
        $attributes['bytes_downloaded'] = $progress['size'];
        $attributes['bytes_expected'] = $progress['size'];
        $attributes['last_progress_at'] = now();

        if ($progress['can_persist_completed']) {
            $requests->completeRequest($row->mediaRequest);
        }

        $this->mediaServerRefreshNeeded = true;

        $probe = $this->playableProbe($row);
        if ($probe !== null && $media->hasEligibleMatch($probe)) {
            $attributes['status'] = CachedContentFileStatus::Completed;
            $attributes['last_error_message'] = null;
        }
    }

    /**
     * The item whose media-server match decides playability: the Channel
     * itself, or a series' first episode of the row's seasons.
     */
    private function playableProbe(CachedContentFile $row): Channel|Episode|null
    {
        $cacheable = $row->cacheable;

        if ($cacheable instanceof Channel) {
            return $cacheable;
        }

        return $cacheable instanceof Series
            ? $cacheable->episodes()
                ->when($row->arr_seasons !== null, fn ($q) => $q->whereIn('season', $row->arr_seasons))
                ->orderBy('episode_num')
                ->first()
            : null;
    }
}
