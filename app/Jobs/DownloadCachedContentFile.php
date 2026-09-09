<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Services\M3uProxyService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use GuzzleHttp\RequestOptions;
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

    /**
     * Transient per-job progress state (set in handle(), read by reportDownloadProgress()
     * and Step 8). Horizon workers fork per job, so instance state is safe across the
     * lifetime of a single handle() invocation.
     */
    private ?int $bytesExpected = null;

    private int $lastReportedBytes = 0;

    private int $progressThreshold = 1_048_576; // 1 MiB

    private ?CachedContentFile $progressFile = null;

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

    public function handle(GeneralSettings $settings, M3uProxyService $proxy, TmdbService $tmdb): void
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

        // Step 5.5: Resolve and persist the TMDB title for the activity widget.
        // - Synchronous (one TMDB HTTP call, ~200ms with built-in rate limiting)
        //   because we already know tmdb_id here and the row is freshly created.
        // - Wrapped in a try/catch — TMDB outage must NEVER block a download.
        // - Skipped if title is already populated (reclaim / Step 2 dedup path).
        $this->resolveAndStoreTitle($file, $tmdb);

        // Step 6: Download to temp file (mirrors ProcessM3uImport.php:460-470 shape)
        // and stream byte-level progress into the row for the Filament progress UI.
        // - PROGRESS callback fires from Guzzle on each chunk (~every 64KB); we throttle
        //   to a 1 MiB boundary so the DB doesn't get hammered on multi-GB downloads.
        // - bytes_expected is set from Content-Length the first time Guzzle reports a
        //   positive downloadSize (-1 means chunked / no Content-Length header).
        // - Progress-write failures are swallowed — a missed update must NEVER abort the
        //   actual download. Step 8 stamps the final tally from Storage::size().
        $tempPath = tempnam(sys_get_temp_dir(), 'dgc_');
        $this->progressFile = $file;
        $this->bytesExpected = null;
        $this->lastReportedBytes = 0;

        try {
            $this->withProviderThrottling(function () use ($tempPath) {
                Http::withUserAgent('m3u-editor/'.config('app.version', '0.0'))
                    ->withOptions([
                        RequestOptions::PROGRESS => fn ($downloadSize, $downloaded) => $this->reportDownloadProgress(
                            (int) $downloadSize,
                            (int) $downloaded,
                        ),
                    ])
                    ->sink($tempPath)
                    ->timeout($this->timeout)
                    ->throw()
                    ->get($this->sourceUrl);
            });
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
            // Stream the temp file into storage instead of loading it into memory.
            // Multi-GB downloads would OOM against file_get_contents() (we hit a 2GB
            // worker memory_limit exactly this way). Laravel's put() accepts a
            // resource and writes it via Flysystem's writeStream, which streams in
            // chunks — peak memory stays bounded regardless of file size.
            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to open temp file for reading: {$tempPath}");
            }
            Storage::disk($disk)->put($path, $stream);
        } catch (\Throwable $e) {
            $this->markFailed($file, 'Failed to move downloaded file: '.$e->getMessage());
            @unlink($tempPath);

            return;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }

        // Step 8: Mark Completed. Use Storage::size() rather than filesize(Storage::path())
        // so the call works against Storage::fake() in tests. bytes_downloaded is also
        // re-stamped from Storage::size() in case the last 1-MiB progress window never
        // crossed the throttle boundary (e.g. a 200 KB clip).
        //
        // The final $file->update() can throw (DB connection lost, constraint
        // violation, etc). Step 7 already wrote the bytes to Storage, so a thrown
        // update leaves a multi-GB orphan: file_path stays null on the row, and
        // DynamicGroupCacheRetentionService::hardDelete() gates Storage cleanup
        // on hasFilePath() (which checks file_path is set). Roll back via
        // rollbackStorageWrite() on any Throwable so a future dispatch re-downloads
        // cleanly instead of leaking the file forever.
        $size = null;
        try {
            $size = Storage::disk($disk)->size($path) ?: null;
        } catch (\Throwable) {
            // file_path no longer exists on disk — leave size null
        }

        try {
            $file->update([
                'status' => CachedContentFileStatus::Completed,
                'disk' => $disk,
                'file_path' => $path,
                'file_size_bytes' => $size,
                'bytes_downloaded' => $size,
                'bytes_expected' => $this->bytesExpected,
                'last_progress_at' => now(),
                'last_verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->rollbackStorageWrite($disk, $path, $file, $e);
            // Temp file was already consumed by Step 7's storage stream — no
            // unlink needed, but kept the safety net in case Step 7 short-circuited
            // before reading the whole file.
            @unlink($tempPath);

            return;
        }

        // Sync the pivot AFTER the row update succeeded. If syncWithoutDetaching
        // throws here, the row is still Completed with file_path set, so the
        // retention service can clean it up later. No Storage leak risk.
        $file->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);
    }

    /**
     * Roll back a Step 7 storage write when the Step 8 row update fails.
     *
     * Deletes the file from Storage (best-effort, logs on failure) and marks
     * the row Failed so the dispatcher's failure-cooldown logic governs when
     * the next attempt happens. Without this, the multi-GB file sits in
     * Storage forever — hasFilePath() returns false (file_path is still null
     * because the failed update never wrote it), so the retention service
     * can't find or clean it up.
     */
    private function rollbackStorageWrite(?string $disk, string $path, CachedContentFile $file, \Throwable $cause): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $deleteError) {
            Log::error("DownloadCachedContentFile: ORPHAN at {$path} after Step 8 update failure — Storage::delete also failed: {$deleteError->getMessage()}");
        }

        $this->markFailed($file, 'Step 8 update failed (Storage rolled back): '.$cause->getMessage());
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

    /**
     * Resolve the TMDB title for a freshly-created cached_content_files row and
     * persist it on the row itself. The Filament activity widget reads this
     * column to show "Wicked" instead of "movie: tmdb 860508".
     *
     * Format (matches the widget's getContentLabel() expectations):
     *   movie        → "Wicked"
     *   episode      → "Breaking Bad — I.F.T." (em-dash, space, episode name)
     *                 or "Breaking Bad S01E03" if the season has no episode title
     *   series       → "Breaking Bad"
     *
     * Failures are swallowed + logged at warning level — TMDB being down or
     * rate-limited must never block a download. The widget falls back to the
     * "type: tmdb N" label when title is null.
     */
    private function resolveAndStoreTitle(CachedContentFile $file, TmdbService $tmdb): void
    {
        if ($file->title !== null) {
            return;
        }

        $tmdbId = $file->tmdb_id !== null ? (int) $file->tmdb_id : 0;
        if ($tmdbId <= 0) {
            return;
        }

        $title = null;
        try {
            $title = match ($file->content_type) {
                'movie' => $this->resolveMovieTitle($tmdb, $tmdbId),
                'episode' => $this->resolveEpisodeTitle($tmdb, $tmdbId, $file),
                'series' => $this->resolveSeriesTitle($tmdb, $tmdbId),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning("DownloadCachedContentFile: TMDB title lookup failed for fingerprint={$file->content_fingerprint}: ".$e->getMessage());

            return;
        }

        if ($title !== null && $title !== '') {
            $file->update(['title' => $title]);
        }
    }

    private function resolveMovieTitle(TmdbService $tmdb, int $tmdbId): ?string
    {
        $details = $tmdb->getMovieDetails($tmdbId);

        return $details['title'] ?? $details['original_title'] ?? null;
    }

    private function resolveSeriesTitle(TmdbService $tmdb, int $tmdbId): ?string
    {
        $details = $tmdb->getTvSeriesDetails($tmdbId);

        return $details['name'] ?? $details['original_name'] ?? null;
    }

    private function resolveEpisodeTitle(TmdbService $tmdb, int $tmdbId, CachedContentFile $file): ?string
    {
        $seriesName = $this->resolveSeriesTitle($tmdb, $tmdbId);
        if ($seriesName === null) {
            return null;
        }

        $seasonNumber = $file->season_number;
        $episodeNumber = $file->episode_number;
        if ($seasonNumber === null || $episodeNumber === null) {
            return $seriesName;
        }

        $season = $tmdb->getSeasonDetails($tmdbId, $seasonNumber);
        $episodes = $season['episodes'] ?? [];
        $episode = collect($episodes)->firstWhere('episode_number', $episodeNumber);
        $episodeName = is_array($episode) ? ($episode['name'] ?? null) : null;

        if ($episodeName !== null && $episodeName !== '') {
            return "{$seriesName} — {$episodeName}";
        }

        return sprintf('%s S%02dE%02d', $seriesName, $seasonNumber, $episodeNumber);
    }

    /**
     * Throttled progress reporter wired to Guzzle's PROGRESS event.
     *
     * Called from the RequestOptions::PROGRESS closure during Step 6's HTTP GET.
     * Guzzle fires this on every chunk (every ~64 KB on average); we update the DB
     * only when the 1 MiB threshold is crossed (plus once more on the final chunk).
     * bytes_expected is captured from the first non-zero downloadSize Guzzle reports
     * (it sends -1 for chunked / no-Content-Length responses).
     *
     * Update failures are swallowed — progress is advisory; a missed write must NEVER
     * abort the actual download.
     */
    public function reportDownloadProgress(int $downloadSize, int $downloaded): void
    {
        if ($this->progressFile === null) {
            return;
        }

        if ($downloadSize > 0 && $this->bytesExpected === null) {
            $this->bytesExpected = $downloadSize;
        }

        if ($downloaded <= 0) {
            return;
        }

        if (($downloaded - $this->lastReportedBytes) < $this->progressThreshold) {
            return;
        }

        try {
            $this->progressFile->update([
                'bytes_downloaded' => $downloaded,
                'bytes_expected' => $this->bytesExpected,
                'last_progress_at' => now(),
            ]);
            $this->lastReportedBytes = $downloaded;
        } catch (\Throwable) {
            // Swallow: progress is advisory; don't kill the download.
        }
    }
}
