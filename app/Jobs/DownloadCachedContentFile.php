<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cache a single Channel or Episode's source URL onto the local `cache` disk
 * with full progress reporting, cancellation, and failure persistence.
 *
 * Production-ready upgrade of the PR B minimal skeleton. Adds:
 *
 *  - Byte-level progress reporting (1 MiB throttle) into
 *    `bytes_downloaded` / `bytes_per_second` / `last_progress_at`.
 *  - Atomic Failed -> Downloading reclaim for retries, gated by
 *    `DB::transaction(... lockForUpdate())` so two concurrent workers never
 *    both download the same row.
 *  - Pending-cancellation flag consumed at job start (covers the
 *    "operator cancelled before a worker ever picked up the job" window).
 *  - Mid-flight cancellation via `cancellationCacheKey` polled on every
 *    progress tick (time-throttled so a multi-GB download does not hammer
 *    the cache store on every 64 KiB read).
 *  - `last_error_message` persisted on the row by `markFailed()` so the
 *    activity widget can show WHY a download failed. PR #1500 silently
 *    dropped this; PR A put the column in `$fillable`, PR C makes sure
 *    the field actually gets written (and is regression-tested in
 *    `DownloadCachedContentFileTest`).
 *  - Three retries with exponential backoff via the framework's standard
 *    `tries` + `backoff()` mechanism.
 *
 * Queue assignment is fixed at construction time (`onQueue('cache')`) so the
 * Horizon supervisor `cache-queue` owns these jobs exclusively, isolated
 * from the `default` / `import` / `file_sync` traffic.
 *
 * The dispatch path lives in `CachedContentDispatchService::dispatchForChannel()`
 * and `dispatchForEpisode()`. That service creates the `cached_content_files`
 * row in `Pending` status before queueing this job, so `handle()` operates on
 * a row that is expected to exist and may be in any of Pending / Failed /
 * (rarely) Downloading state.
 */
class DownloadCachedContentFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Three attempts covers a single transient proxy hiccup + one genuine
     * upstream failure without flooding failed_jobs with retried spam.
     */
    public int $tries = 3;

    /**
     * Generous timeout - movie files can be multi-GB. Aligns with the existing
     * `dvr.playlist_download_timeout` knob so cache + DVR share a single
     * operator-facing ceiling.
     */
    public int $timeout = 3600;

    /**
     * Exponential backoff in seconds between retries ([10s, 60s, 300s]).
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * 1 MiB throttle boundary for byte-level progress writes. Progress is
     * advisory; updating the row on every 64 KiB chunk would hammer Postgres
     * and is not what the polling widget needs.
     */
    private int $progressThreshold = 1_048_576;

    /**
     * Time-based throttle for cancellation checks (separate from the
     * byte-based progress throttle). 2s means a sub-1-MiB download still
     * gets at least one cancellation check, while a multi-GB one does not
     * hit the cache store on every chunk.
     */
    private int $cancellationCheckIntervalSeconds = 2;

    /**
     * 64 KiB read chunk for the streamed response body. Larger than the
     * progress threshold divisor so we get a handful of progress writes
     * per MiB even on slow connections.
     */
    private int $readChunkSize = 65_536;

    /**
     * Transient per-handle() progress state. Horizon forks per job, so
     * instance state is safe across the lifetime of a single execution.
     */
    private ?int $bytesExpected = null;

    private int $lastReportedBytes = 0;

    private ?Carbon $lastProgressAt = null;

    private ?CachedContentFile $progressFile = null;

    private ?Carbon $lastCancellationCheckAt = null;

    /**
     * Set when `checkCancellation()` flips on. `streamResponseToFile()`'s
     * read loop breaks on this flag, closing the PSR-7 stream and
     * dropping the underlying connection immediately rather than
     * reading the rest of the transfer.
     */
    private bool $cancelled = false;

    public function __construct(
        public Channel|Episode $item,
        public int $cachedContentFileId,
    ) {
        $this->onQueue('cache');
    }

    /**
     * Orchestrate the download end to end:
     *
     *  1. Find the row (operator-deleted rows log + return without throw).
     *  2. Consume any pending-cancel flag for this fingerprint. Early-exit
     *     without an HTTP call if the operator cancelled the job before it
     *     ever started.
     *  3. Atomic Failed -> Downloading (or Pending -> Downloading) reclaim
     *     inside a `lockForUpdate()` transaction so two concurrent workers
     *     can never both proceed against the same row.
     *  4. Resolve the source URL + extension + storage path.
     *  5. Stream the GET to a temp file with 64 KiB chunks, throttling
     *     progress writes to the row at the 1 MiB boundary.
     *  6. Move temp file -> `cache` disk via Storage writeStream (bounded
     *     memory, same as `ProcessM3uImport.php`).
     *  7. Stamp Completed with `disk`, `file_path`, `file_size_bytes`,
     *     `bytes_downloaded`, `bytes_expected`, `bytes_per_second`,
     *     `last_progress_at`, `last_verified_at`.
     *
     * On any failure (HTTP client exception, ConnectionException,
     * RequestException, IO error) we move the row to Failed and persist
     * `last_error_message` via `markFailed()` (regression target for PR #1500
     * which silently dropped this field). The exception is re-thrown so
     * Horizon marks the attempt failed and applies `backoff()`.
     */
    public function handle(): void
    {
        $file = CachedContentFile::find($this->cachedContentFileId);
        if (! $file) {
            Log::warning("DownloadCachedContentFile: cached_content_files row {$this->cachedContentFileId} not found, nothing to download.");

            return;
        }

        $fingerprint = $file->content_fingerprint;

        // Step 2: consume a pre-pending cancel. Keyed by fingerprint because
        // the row itself is already gone by the time the worker arrives.
        // Cache::pull() so the flag is consumed here and a later, unrelated
        // legitimate dispatch for the same fingerprint is not suppressed.
        if (Cache::pull(CachedContentFile::pendingCancellationCacheKey($fingerprint))) {
            Log::info("DownloadCachedContentFile: fingerprint={$fingerprint} cancelled before start.");

            return;
        }

        // Step 3: atomic Failed -> Downloading reclaim. Two concurrent workers
        // for the same row would otherwise both download. lockForUpdate() is
        // portable across cache drivers (Cache::lock would have required the
        // configured cache store to support locks, which the array driver used
        // in some test setups does not).
        $file = $this->atomicReclaim($file);
        if ($file === null) {
            return;
        }

        // A queued job may be picked up after its row was cancelled but
        // before the worker observed the cancellation marker.
        $this->progressFile = $file;
        $this->checkCancellation();
        if ($this->cancelled) {
            Cache::forget(CachedContentFile::cancellationCacheKey($file->id));

            return;
        }

        $url = $this->resolveSourceUrl();
        if ($url === '') {
            // Empty URL is a configuration bug, not a transient failure -
            // don't throw, Horizon would only retry and spam failed_jobs.
            // markFailed() persists last_error_message so the activity
            // widget surfaces the misconfiguration.
            $this->markFailed($file, 'Source URL is empty.');

            return;
        }

        $extension = $this->resolveExtensionFromUrl($url);
        $relativePath = $this->resolveStorageRelativePath($fingerprint, $extension);
        $disk = 'cache';

        // Step 5/6: stream into a temp file (bounded memory), then move to
        // Storage via writeStream (also bounded memory). Multi-GB downloads
        // would OOM against `file_get_contents()` or a buffered Response body.
        $tempPath = tempnam(sys_get_temp_dir(), 'dccf_');
        $this->progressFile = $file;
        $this->bytesExpected = null;
        $this->lastReportedBytes = 0;
        $this->lastProgressAt = null;
        $this->lastCancellationCheckAt = null;
        $this->cancelled = false;

        try {
            $response = Http::withUserAgent('m3u-editor/'.config('app.version', '0.0'))
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->throw()
                ->get($url);

            $this->streamResponseToFile($response, $tempPath);
        } catch (RequestException|ConnectionException $e) {
            @unlink($tempPath);
            $this->markFailed($file, 'HTTP error: '.$e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            @unlink($tempPath);
            $this->markFailed($file, 'Unexpected error: '.$e->getMessage());

            throw $e;
        }

        $this->checkCancellation();

        // Cancellation landed between the last chunk read and Step 6's
        // Storage write. Real mid-transfer aborts already broke out of the
        // chunk loop in streamResponseToFile(); this catches the narrow
        // tail. Discard the bytes and let handle() return normally so
        // JobProcessed fires as usual.
        if ($this->cancelled) {
            Cache::forget(CachedContentFile::cancellationCacheKey($file->id));
            Log::info("DownloadCachedContentFile: fingerprint={$fingerprint} cancelled mid-download.");
            @unlink($tempPath);

            return;
        }

        // Step 6: move temp -> Storage via writeStream so peak memory stays
        // bounded regardless of file size.
        try {
            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to open temp file for reading: {$tempPath}");
            }
            Storage::disk($disk)->put($relativePath, $stream);
        } catch (Throwable $e) {
            @unlink($tempPath);
            $this->markFailed($file, 'Failed to move downloaded file: '.$e->getMessage());

            throw $e;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }

        @unlink($tempPath);

        // Step 7: stamp Completed. Use Storage::size() rather than filesize()
        // so the call works against Storage::fake() in tests. Re-stamp
        // `bytes_downloaded` from the same source in case the last 1 MiB
        // progress window never crossed the throttle boundary (a 200 KB
        // clip, for example).
        $size = null;
        try {
            $size = Storage::disk($disk)->size($relativePath) ?: null;
        } catch (Throwable) {
            // file_path no longer exists on disk - leave size null
        }

        try {
            $file->forceFill([
                'status' => CachedContentFileStatus::Completed,
                'disk' => $disk,
                'file_path' => $relativePath,
                'file_size_bytes' => $size,
                'bytes_downloaded' => $size,
                'bytes_expected' => $this->bytesExpected,
                'last_progress_at' => now(),
                'last_verified_at' => now(),
                'failure_count' => 0,
                'last_failed_at' => null,
                'last_error_message' => null,
            ])->save();
        } catch (Throwable $e) {
            // The bytes are on Storage but the row update failed - roll back
            // the storage write so retention can clean up later. Without
            // this the file would orphan because file_path is still null.
            try {
                Storage::disk($disk)->delete($relativePath);
            } catch (Throwable $deleteError) {
                Log::error("DownloadCachedContentFile: ORPHAN at {$relativePath} after Completed-update failure - Storage::delete also failed: {$deleteError->getMessage()}");
            }
            $this->markFailed($file, 'Step 7 update failed (Storage rolled back): '.$e->getMessage());

            throw $e;
        }

        // Cancellation landed after Step 7 stamped Completed but before
        // handle() returned. Restore Failed so the operator sees what
        // happened. The bytes are now correctly on disk, but the row
        // status reflects the operator's intent.
        if ($this->cancelled) {
            Cache::forget(CachedContentFile::cancellationCacheKey($file->id));
            $file->forceFill([
                'status' => CachedContentFileStatus::Failed,
                'last_failed_at' => now(),
                'last_error_message' => 'Cancelled after download completed.',
            ])->save();

            return;
        }
    }

    /**
     * Atomically flip the row to Downloading. Returns the freshly-loaded
     * model on success, or null if another worker has already claimed it
     * (or the row has been deleted).
     *
     * Allowed transitions:
     *  - Pending     -> Downloading   (common case)
     *  - Failed      -> Downloading   (retry after cooldown passed at dispatch)
     *
     * Rejected transitions (return null without throw):
     *  - Downloading -> another worker is plausibly on it
     *  - Completed   -> dispatcher should have skipped; defensive
     *  - missing row -> operator cleaned it up between dispatch and run
     */
    private function atomicReclaim(CachedContentFile $file): ?CachedContentFile
    {
        try {
            $reclaimed = DB::transaction(function () use ($file): ?CachedContentFile {
                $row = DB::table('cached_content_files')
                    ->where('id', $file->id)
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    return null;
                }

                $currentStatus = CachedContentFileStatus::from($row->status);

                if ($currentStatus === CachedContentFileStatus::Downloading || $currentStatus === CachedContentFileStatus::Completed) {
                    return null;
                }

                // Pending or Failed -> Downloading. Failed is a retry case -
                // reset failure_count and last_failed_at on the way through
                // so the new attempt starts from a clean slate.
                $update = [
                    'status' => CachedContentFileStatus::Downloading->value,
                    'updated_at' => now(),
                ];
                if ($currentStatus === CachedContentFileStatus::Failed) {
                    $update['failure_count'] = 0;
                    $update['last_failed_at'] = null;
                    $update['last_error_message'] = null;
                }

                $affected = DB::table('cached_content_files')
                    ->where('id', $file->id)
                    ->whereIn('status', [CachedContentFileStatus::Pending->value, CachedContentFileStatus::Failed->value])
                    ->update($update);

                if ($affected === 0) {
                    return null;
                }

                return $file->fresh();
            });
        } catch (Throwable $e) {
            Log::warning("DownloadCachedContentFile: atomic reclaim failed for row {$file->id}: {$e->getMessage()}");

            return null;
        }

        if ($reclaimed === null) {
            Log::info("DownloadCachedContentFile: row {$file->id} could not be reclaimed (already Downloading/Completed or deleted).");

            return null;
        }

        return $reclaimed;
    }

    /**
     * Read the response body in 64 KiB chunks into the temp file, reporting
     * progress and polling cancellation between chunks. This is what makes
     * mid-flight cancellation a real abort instead of a cooperative
     * discard-after-completion.
     *
     * The Laravel HTTP client + Guzzle stream transport gives us a PSR-7
     * body stream; breaking out of the read loop and closing it is ordinary
     * control flow that drops the underlying connection immediately. (An
     * earlier curl-based approach aborted downloads by throwing from inside
     * `CURLOPT_PROGRESSFUNCTION`, which doesn't unwind as a normal PHP
     * exception - confirmed via a real Horizon run that left the queue
     * reservation stuck.)
     */
    private function streamResponseToFile(Response $response, string $tempPath): void
    {
        $contentLength = (int) $response->header('Content-Length');
        if ($contentLength > 0) {
            $this->bytesExpected = $contentLength;
        }

        $body = $response->toPsrResponse()->getBody();
        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Unable to open temp file for writing: {$tempPath}");
        }

        $downloaded = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read($this->readChunkSize);
                if ($chunk === '') {
                    break;
                }

                fwrite($out, $chunk);
                $downloaded += strlen($chunk);

                $this->reportDownloadProgress($this->bytesExpected ?? -1, $downloaded);

                if ($this->cancelled) {
                    break;
                }
            }

            // Persist the final chunk even when the response is smaller than
            // the normal 1 MiB throttle boundary.
            $this->reportDownloadProgress($this->bytesExpected ?? -1, $downloaded, force: true);
        } finally {
            fclose($out);
            // Body is a PSR-7 Stream - close() drops the underlying
            // connection immediately instead of waiting for GC.
            if ($body instanceof Stream) {
                $body->close();
            }
        }
    }

    /**
     * Throttled progress reporter. Called on every chunk read by
     * streamResponseToFile(); writes to the row only when the 1 MiB
     * threshold is crossed (plus once more on the final chunk).
     *
     * Write failures are swallowed - progress is advisory; a missed
     * update must NEVER abort the actual download.
     */
    private function reportDownloadProgress(int $downloadSize, int $downloaded, bool $force = false): void
    {
        if ($this->progressFile === null) {
            return;
        }

        $this->checkCancellation();

        if ($downloadSize > 0 && $this->bytesExpected === null) {
            $this->bytesExpected = $downloadSize;
        }

        if ($downloaded <= 0) {
            return;
        }

        if (! $force && $this->lastReportedBytes > 0 && ($downloaded - $this->lastReportedBytes) < $this->progressThreshold) {
            return;
        }

        try {
            $now = now();
            $bytesPerSecond = $this->computeBytesPerSecond($downloaded, $now);
            $this->progressFile->forceFill([
                'bytes_downloaded' => $downloaded,
                'bytes_expected' => $this->bytesExpected,
                'bytes_per_second' => $bytesPerSecond,
                'last_progress_at' => $now,
            ])->save();
            $this->lastReportedBytes = $downloaded;
            $this->lastProgressAt = $now;
        } catch (Throwable) {
            // Swallow - progress is advisory.
        }
    }

    /**
     * Check whether an operator cancelled this row mid-flight and, if so,
     * set the cancelled flag. streamResponseToFile()'s read loop checks
     * the flag after every chunk and breaks immediately.
     *
     * Time-throttled (not byte-throttled like the progress threshold) so a
     * sub-64 KiB file still gets at least one check, while a multi-GB one
     * isn't hitting the cache store on every chunk.
     */
    private function checkCancellation(): void
    {
        if ($this->progressFile === null) {
            return;
        }

        $now = now();
        if ($this->lastCancellationCheckAt !== null
            && $this->lastCancellationCheckAt->diffInSeconds($now, absolute: true) < $this->cancellationCheckIntervalSeconds) {
            return;
        }
        $this->lastCancellationCheckAt = $now;

        if (Cache::has(CachedContentFile::cancellationCacheKey($this->progressFile->id))) {
            $this->cancelled = true;
        }
    }

    /**
     * Compute bytes/second for the just-completed progress window.
     *
     * Returns null on the first event (no previous timestamp to subtract
     * from) or when the rate collapses to zero (defensive - would
     * otherwise produce an "infinite" ETA).
     */
    private function computeBytesPerSecond(int $downloaded, Carbon $now): ?int
    {
        if ($this->lastProgressAt === null || $this->lastReportedBytes <= 0) {
            return null;
        }

        $bytesDelta = $downloaded - $this->lastReportedBytes;
        $seconds = max(1, (int) round($this->lastProgressAt->diffInSeconds($now, absolute: true)));

        $rate = (int) round($bytesDelta / $seconds);

        return $rate > 0 ? $rate : null;
    }

    /**
     * Move the row to Failed and bump `failure_count`. Persists
     * `last_error_message` so the activity widget can surface WHY a
     * download failed. PR #1500 silently dropped this column because it
     * was missing from `$fillable`; PR A put it in `$fillable` and this
     * method actually writes it. `forceFill()` here is defensive - the
     * column IS fillable now, but forceFill makes the regression
     * ("markFailed itself drops the message") fail loudly if a future
     * change removes it from $fillable by mistake.
     *
     * `last_error_message` is truncated to 8000 chars to fit reasonable
     * Postgres row-size budgets while still capturing the meaningful
     * prefix of long Guzzle / Symfony exception messages.
     */
    protected function markFailed(CachedContentFile $file, string $reason): void
    {
        try {
            $file->forceFill([
                'status' => CachedContentFileStatus::Failed,
                'failure_count' => ($file->failure_count ?? 0) + 1,
                'last_failed_at' => now(),
                'last_error_message' => mb_substr($reason, 0, 8000),
            ])->save();
            Log::warning("DownloadCachedContentFile failed for row {$file->id} (fp={$file->content_fingerprint}): {$reason}");
        } catch (Throwable $e) {
            // markFailed itself failing is the worst-case scenario - log and
            // continue so the outer throwForFailure() still runs (otherwise
            // Horizon would never see the failure and the job would silently
            // succeed with a stale row).
            Log::error("DownloadCachedContentFile: markFailed itself failed for {$file->id}: {$e->getMessage()}");
        }
    }

    /**
     * Resolve the source URL string from the constructor's union.
     *
     * Both `Channel` and `Episode` expose the stream URL via the top-level
     * `url` column (no `info['url']` indirection). Returns an empty string
     * when the row's URL is missing so `handle()` can fail-fast without
     * throwing.
     */
    private function resolveSourceUrl(): string
    {
        return (string) ($this->item->url ?? '');
    }

    /**
     * Sniff a container extension off the source URL so the cached file
     * keeps its type (`.mp4`, `.mkv`, `.ts`). Falls back to `.mp4` when
     * the URL has no extension - matches the default `resolveMimeType()`
     * contract on the model.
     */
    private function resolveExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'mkv', 'ts', 'm3u8'], true) ? ".{$ext}" : '.mp4';
    }

    /**
     * Build the relative storage path under the `cache` disk: the row's
     * fingerprint plus the sniffed extension. Mirrors the model's
     * `resolveStorageDisk()` / `resolveMimeType()` defaults.
     */
    private function resolveStorageRelativePath(string $fingerprint, string $extension): string
    {
        return 'cache/'.$fingerprint.$extension;
    }
}
