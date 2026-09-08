<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadCachedContentFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ProviderRequestDelay, Queueable;

    public int $tries = 1; // explicit in-handle retry; no queue-level retry (mirrors ProcessM3uImport.php:49)

    public int $timeout;

    public function __construct(
        public DynamicGroup $dynamicGroup,
        public string $contentType,
        public ?string $tmdbId,
        public ?string $tvdbId,
        public ?int $seasonNumber,
        public ?int $episodeNumber,
        public ?string $quality,
        public string $sourceUrl,
    ) {
        // Generous timeout — movie files can be multi-GB. Default 1h.
        // Future pass: add a dedicated config('dynamic_group_cache.download_timeout', ...).
        $this->timeout = (int) config('dvr.playlist_download_timeout', 3600);
        $this->onQueue('dynamic-group-cache');
    }

    public function handle(GeneralSettings $settings, M3uProxyService $proxy): void
    {
        // Step 1: Compute fingerprint
        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => $this->contentType,
            'tmdb_id' => $this->tmdbId,
            'tvdb_id' => $this->tvdbId,
            'season_number' => $this->seasonNumber,
            'episode_number' => $this->episodeNumber,
            'quality' => $this->quality,
        ]);

        // Step 2: Cross-playlist/cross-group dedup. If a Completed row already
        // exists for this fingerprint, attach this group and return.
        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        if ($existing && $existing->status === CachedContentFileStatus::Completed) {
            $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

            return;
        }

        // Step 3: Concurrency gate (whole-system, not per-playlist)
        $maxConcurrent = (int) ($settings->dynamic_group_cache_max_concurrent_downloads ?? 2);
        $active = CachedContentFile::where('status', CachedContentFileStatus::Downloading)->count();
        if ($active >= $maxConcurrent) {
            $this->release(30);

            return;
        }

        // Step 4: Connection-limit pre-flight against the proxy's active-stream count
        $playlist = $this->dynamicGroup->playlist;
        $availableStreams = $playlist ? (int) $playlist->available_streams : 0;
        if ($availableStreams > 0) {
            $activeStreams = $proxy::getCachedPlaylistActiveStreamsCount($playlist);
            if ($activeStreams >= $availableStreams) {
                $this->release(60);

                return;
            }
        }

        // Step 5: Create the row in Downloading status FIRST so the concurrency
        // gate in step 3 sees it. The catch block handles three cases:
        //  - Failed row past cooldown  → atomically reclaim (retry the download)
        //  - Stale Downloading row     → atomically reclaim (crashed worker recovery)
        //  - Fresh Downloading row     → attach this group, return (another worker
        //                                  is plausibly still on it)
        // The affected-row-count on the reclaim UPDATE acts as a race guard so two
        // workers reclaiming the same row simultaneously don't both proceed.
        try {
            $file = CachedContentFile::create([
                'content_type' => $this->contentType,
                'tmdb_id' => $this->tmdbId,
                'tvdb_id' => $this->tvdbId,
                'season_number' => $this->seasonNumber,
                'episode_number' => $this->episodeNumber,
                'quality' => $this->quality,
                'content_fingerprint' => $fingerprint,
                'status' => CachedContentFileStatus::Downloading,
            ]);
        } catch (QueryException $e) {
            $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
            if (! $existing) {
                // Race we lost AND no row found — permanent skip (defensive).
                return;
            }

            if ($existing->status === CachedContentFileStatus::Failed) {
                // Cooldown already expired (the dispatcher's shouldSkip() gates this
                // before dispatch). Atomically flip Failed → Downloading using
                // affected-row-count as the race guard.
                $reclaimed = CachedContentFile::where('id', $existing->id)
                    ->where('status', CachedContentFileStatus::Failed->value)
                    ->update([
                        'status' => CachedContentFileStatus::Downloading->value,
                        'failure_count' => 0,
                        'last_failed_at' => null,
                    ]);

                if ($reclaimed === 0) {
                    // Another worker won the reclaim race — attach and exit
                    $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                    return;
                }
                $file = $existing->fresh();
            } elseif ($existing->status === CachedContentFileStatus::Downloading) {
                // Stale = crashed worker. Threshold = job timeout + 5min safety margin.
                $staleThreshold = now()->subSeconds($this->timeout + 300);
                if ($existing->updated_at < $staleThreshold) {
                    // WHERE clause pins both status AND updated_at so a concurrent
                    // completion/failure can't be silently overwritten.
                    $reclaimed = CachedContentFile::where('id', $existing->id)
                        ->where('status', CachedContentFileStatus::Downloading->value)
                        ->where('updated_at', '<', $staleThreshold)
                        ->update([
                            'failure_count' => 0,
                            'last_failed_at' => null,
                        ]);

                    if ($reclaimed === 0) {
                        $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                        return;
                    }
                    $file = $existing->fresh();
                } else {
                    // Fresh Downloading — another worker is plausibly still on it
                    $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                    return;
                }
            } else {
                // Pending (or any unexpected state) — attach and exit
                $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                return;
            }
        }

        // Step 6: Download to temp file (mirrors ProcessM3uImport.php:460-470 shape)
        $tempPath = tempnam(sys_get_temp_dir(), 'dgc_');
        try {
            $this->withProviderThrottling(fn () => Http::withUserAgent('m3u-editor/'.config('app.version', '0.0'))
                ->sink($tempPath)
                ->timeout($this->timeout)
                ->throw()
                ->get($this->sourceUrl));
        } catch (RequestException|ConnectionException $e) {
            $this->markFailed($file, $e->getMessage());
            @unlink($tempPath);

            return;
        } catch (\Throwable $e) {
            $this->markFailed($file, $e->getMessage());
            @unlink($tempPath);

            return;
        }

        // Step 7: Resolve destination — default disk + path derived from fingerprint.
        // Per-group cache_location_override resolution is left for a later pass; for
        // Phase 2 we only honor the global setting + default disk root.
        $disk = $settings->dynamic_group_cache_location
            ? null // explicit location handling would go here in a later pass
            : config('filesystems.default');

        $extension = pathinfo((string) parse_url($this->sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp4';
        $path = 'cache/'.$fingerprint.'.'.$extension;

        try {
            // Write the downloaded temp file into storage (Storage::move() expects
            // both args relative to disk root — we have an absolute temp path).
            Storage::disk($disk)->put($path, file_get_contents($tempPath));
        } catch (\Throwable $e) {
            $this->markFailed($file, 'Failed to move downloaded file: '.$e->getMessage());
            @unlink($tempPath);

            return;
        }

        // Step 8: Mark Completed. Use Storage::size() rather than filesize(Storage::path())
        // so the call works against Storage::fake() in tests.
        $size = null;
        try {
            $size = Storage::disk($disk)->size($path) ?: null;
        } catch (\Throwable) {
            // file_path no longer exists on disk — leave size null
        }
        $file->update([
            'status' => CachedContentFileStatus::Completed,
            'disk' => $disk,
            'file_path' => $path,
            'file_size_bytes' => $size,
            'last_verified_at' => now(),
        ]);
        $file->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);
    }

    /**
     * Update the row to Failed, increment failure_count, stamp last_failed_at.
     * Never throws — wraps any exception so we don't bubble up and trigger Laravel's
     * queue retry (per $tries=1, the dispatcher controls retry timing via the
     * failure-cooldown settings).
     */
    private function markFailed(CachedContentFile $file, string $reason): void
    {
        try {
            $file->update([
                'status' => CachedContentFileStatus::Failed,
                'last_failed_at' => now(),
                'failure_count' => (int) ($file->failure_count ?? 0) + 1,
            ]);
            Log::warning("DownloadCachedContentFile: fingerprint={$file->content_fingerprint} failed: {$reason}");
        } catch (\Throwable $e) {
            Log::error("DownloadCachedContentFile: markFailed itself failed for {$file->content_fingerprint}: {$e->getMessage()}");
        }
    }
}
