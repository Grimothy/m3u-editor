<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Settings\GeneralSettings;
use App\Support\PrivateNetworkGuard;
use App\Traits\ProviderRequestDelay;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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
use InvalidArgumentException;
use Throwable;

/**
 * Cache a single Channel or Episode's source URL onto the local `cache` disk
 * with full progress reporting, cancellation, SSRF protection, and failure
 * persistence.
 *
 * Behavior:
 *  - Byte-level progress reporting (1 MiB throttle) into
 *    `bytes_downloaded` / `bytes_per_second` / `last_progress_at`.
 *  - Atomic Failed -> Downloading reclaim for retries, gated by
 *    `DB::transaction(... lockForUpdate())`.
 *  - Cancellation: the widget's `deleteCachedFile()` writes the row-id
 *    `cancellationCacheKey` and then deletes the row. For Pending rows
 *    the worker's `find()` returns null and it exits cleanly. For
 *    already-Downloading rows the flag is polled from
 *    `checkCancellation()` (time-throttled so a multi-GB download does
 *    not hammer the cache store on every 64 KiB read). `markCancelled()`
 *    handles the race where the row is still alive when the worker
 *    observes the flag, flipping it to Failed with a "Cancelled" error
 *    message instead of leaving it stuck on Downloading.
 *  - `last_error_message` persisted on the row by `markFailed()`.
 *  - Three retries with exponential backoff via the framework's standard
 *    `tries` + `backoff()` mechanism.
 *  - SSRF protection: validates the initial URL scheme/host via
 *    `PrivateNetworkGuard::assertUrlSafe()`, then disables Guzzle's
 *    automatic redirect following and re-validates every `Location`
 *    header so a malicious upstream can't redirect to internal
 *    services.
 *  - Provider throttling: `withProviderThrottling()` wraps ONLY the
 *    connection-establishment step (`fetchWithSafeRedirects`). The slot
 *    has a 5-minute TTL and is shared across M3U/EPG import jobs, so
 *    wrapping the whole download would starve the import pipeline of
 *    slots for the full multi-GB download window. The body streaming
 *    happens outside the slot; overall download concurrency is bounded
 *    by the Horizon `cache-queue` supervisor's maxProcesses.
 *  - Storage write is validated (`put()` return + post-write `exists()`)
 *    before stamping `Completed`, so a failed disk write doesn't mark a
 *    row as ready when the bytes never made it.
 *
 * Queue: `cache` (Horizon supervisor `cache-queue`).
 */
class DownloadCachedContentFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ProviderRequestDelay, Queueable;

    /**
     * Three attempts covers a single transient proxy hiccup + one genuine
     * upstream failure without flooding failed_jobs with retried spam.
     */
    public int $tries = 3;

    /**
     * Generous timeout - movie files can be multi-GB. Aligns with the
     * existing `dvr.playlist_download_timeout` knob so cache + DVR share
     * a single operator-facing ceiling.
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
     * advisory; updating the row on every 64 KiB chunk would hammer
     * Postgres and is not what the polling widget needs.
     */
    private int $progressThreshold = 1_048_576;

    /**
     * Time-based throttle for cancellation checks (separate from the
     * byte-based progress throttle). 2s means a sub-1-MiB download still
     * gets at least one cancellation check, while a multi-GB one does
     * not hit the cache store on every chunk.
     */
    private int $cancellationCheckIntervalSeconds = 2;

    /**
     * 64 KiB read chunk for the streamed response body.
     */
    private int $readChunkSize = 65_536;

    /**
     * Hard cap on how many HTTP redirects we'll follow. Real upstream
     * mirrors rarely need more than 2-3; anything beyond that is suspect.
     */
    private int $maxRedirects = 5;

    /**
     * Transient per-handle() progress state.
     */
    private ?int $bytesExpected = null;

    private int $lastReportedBytes = 0;

    private ?Carbon $lastProgressAt = null;

    private ?CachedContentFile $progressFile = null;

    private ?Carbon $lastCancellationCheckAt = null;

    /**
     * Set when `checkCancellation()` flips on. `streamResponseToFile()`'s
     * read loop breaks on this flag, closing the PSR-7 stream and
     * dropping the underlying connection immediately.
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
     *  2. Atomic Failed -> Downloading (or Pending -> Downloading) reclaim
     *     inside a `lockForUpdate()` transaction.
     *  3. Validate the source URL against PrivateNetworkGuard (SSRF guard).
     *  4. Stream the GET with redirect-disabled Guzzle, manually following
     *     each redirect through the guard (wrapped in
     *     `withProviderThrottling()` so the provider-request-delay
     *     concurrency slot is held only across connection-establishment,
     *     not the full body stream).
     *  5. Move temp file -> `cache` disk via Storage::put and validate
     *     the write succeeded.
     *  6. Stamp Completed with file size + progress metadata.
     *
     * On any failure (HTTP exception, ConnectionException, RequestException,
     * SSRF rejection, IO error) the row moves to Failed via `markFailed()`
     * and the exception is re-thrown so Horizon applies backoff.
     */
    public function handle(): void
    {
        // Kill switch: if the operator turned `enable_cache` off while
        // jobs were already queued, short-circuit cleanly without
        // throwing. The row is marked Failed via the same path other
        // validation errors use so a re-dispatch (after the operator
        // re-enables caching) can reclaim it.
        if (! (bool) (app(GeneralSettings::class)->enable_cache ?? false)) {
            $file = CachedContentFile::find($this->cachedContentFileId);
            if ($file) {
                $this->markFailed($file, 'Caching disabled');
            } else {
                Log::info("DownloadCachedContentFile: caching disabled, row {$this->cachedContentFileId} not found - nothing to mark.");
            }

            return;
        }

        $file = CachedContentFile::find($this->cachedContentFileId);
        if (! $file) {
            Log::warning("DownloadCachedContentFile: cached_content_files row {$this->cachedContentFileId} not found, nothing to download.");

            return;
        }

        $fingerprint = $file->content_fingerprint;

        $file = $this->atomicReclaim($file);
        if ($file === null) {
            return;
        }

        $this->progressFile = $file;
        $this->checkCancellation();
        if ($this->cancelled) {
            $this->markCancelled($file, 'Cancelled before download started.');

            return;
        }

        $url = $this->resolveSourceUrl();
        if ($url === '') {
            $this->markFailed($file, 'Source URL is empty.');

            return;
        }

        // SSRF guard: validate the initial URL (scheme + private/reserved
        // destination) before issuing any HTTP request. A rejection here
        // fails the row without throwing so Horizon doesn't retry a known-
        // to-be-bad URL.
        try {
            PrivateNetworkGuard::assertUrlSafe($url);
        } catch (InvalidArgumentException $e) {
            $this->markFailed($file, 'Source URL rejected (SSRF guard): '.$e->getMessage());

            return;
        }

        $extension = $this->resolveExtensionFromUrl($url);
        $relativePath = $this->resolveStorageRelativePath($fingerprint, $extension);
        $disk = 'cache';

        $tempPath = tempnam(sys_get_temp_dir(), 'dccf_');
        $this->progressFile = $file;
        $this->bytesExpected = null;
        $this->lastReportedBytes = 0;
        $this->lastProgressAt = null;
        $this->lastCancellationCheckAt = null;
        $this->cancelled = false;

        try {
            // withProviderThrottling() holds the shared provider-request
            // slot only across the connection-establishment + redirect
            // walk. The body stream below runs outside the slot; overall
            // download concurrency is bounded by the Horizon
            // `cache-queue` maxProcesses. enable_provider_request_delay
            // off => the trait is a no-op so existing Http::fake tests
            // are unaffected.
            $response = $this->withProviderThrottling(
                fn (): Response => $this->fetchWithSafeRedirects($url)
            );
            $this->streamResponseToFile($response, $tempPath);
        } catch (RequestException|ConnectionException $e) {
            @unlink($tempPath);
            $this->markFailed($file, 'HTTP error: '.$e->getMessage());

            throw $e;
        } catch (InvalidArgumentException $e) {
            // A redirect target violated the SSRF guard.
            @unlink($tempPath);
            $this->markFailed($file, 'Redirect rejected (SSRF guard): '.$e->getMessage());

            return;
        } catch (Throwable $e) {
            @unlink($tempPath);
            $this->markFailed($file, 'Unexpected error: '.$e->getMessage());

            throw $e;
        }

        $this->checkCancellation();

        if ($this->cancelled) {
            Log::info("DownloadCachedContentFile: fingerprint={$fingerprint} cancelled mid-download.");
            @unlink($tempPath);
            $this->markCancelled($file, 'Cancelled mid-download.');

            return;
        }

        // Validate the Storage write BEFORE stamping Completed. Storage::put
        // returns bool but a disk-full / permission failure may surface as
        // an exception; either way we want the row to reflect the truth.
        try {
            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to open temp file for reading: {$tempPath}");
            }
            $putResult = Storage::disk($disk)->put($relativePath, $stream);
        } catch (Throwable $e) {
            @unlink($tempPath);
            $this->markFailed($file, 'Failed to move downloaded file: '.$e->getMessage());

            throw $e;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($putResult !== true || ! Storage::disk($disk)->exists($relativePath)) {
            @unlink($tempPath);
            $this->markFailed($file, 'Storage write returned false or file missing on disk.');

            return;
        }

        @unlink($tempPath);

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
            try {
                Storage::disk($disk)->delete($relativePath);
            } catch (Throwable $deleteError) {
                Log::error("DownloadCachedContentFile: ORPHAN at {$relativePath} after Completed-update failure - Storage::delete also failed: {$deleteError->getMessage()}");
            }
            $this->markFailed($file, 'Step 7 update failed (Storage rolled back): '.$e->getMessage());

            throw $e;
        }

        if ($this->cancelled) {
            $this->markCancelled($file, 'Cancelled after download completed.');

            return;
        }
    }

    /**
     * Atomically flip the row to Downloading. Returns the freshly-loaded
     * model on success, or null if another worker has already claimed it
     * (or the row has been deleted).
     *
     * Allowed transitions:
     *  - Pending -> Downloading (common case)
     *  - Failed -> Downloading (retry after cooldown passed at dispatch)
     *
     * Rejected transitions (return null without throw):
     *  - Downloading -> another worker is plausibly on it
     *  - Completed -> dispatcher should have skipped; defensive
     *
     * DB-specific failures (QueryException) propagate so an actual outage
     * is visible to Horizon. We only swallow the narrow "row missing"
     * case where the operator deleted the row between dispatch and run.
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
        } catch (QueryException $e) {
            // Genuine DB failure - rethrow so Horizon marks the attempt
            // failed and applies backoff(). Swallowing this would let
            // a transient outage look like a successful no-op.
            Log::error("DownloadCachedContentFile: atomic reclaim DB failure for row {$file->id}: {$e->getMessage()}");

            throw $e;
        }

        if ($reclaimed === null) {
            Log::info("DownloadCachedContentFile: row {$file->id} could not be reclaimed (already Downloading/Completed or deleted).");

            return null;
        }

        return $reclaimed;
    }

    /**
     * Issue the GET with Guzzle's redirect-following disabled, then
     * manually walk the Location header chain with each hop re-validated
     * by the SSRF guard. A redirect to a private/reserved destination
     * throws so the caller's catch marks the row Failed without
     * downloading bytes from an unintended host.
     */
    private function fetchWithSafeRedirects(string $url): Response
    {
        $current = $url;
        for ($i = 0; $i <= $this->maxRedirects; $i++) {
            $resolvedIp = PrivateNetworkGuard::assertUrlSafe($current);
            $parts = parse_url($current);
            $host = (string) $parts['host'];
            $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
            $resolveHost = str_contains($host, ':') ? "[{$host}]" : $host;

            $response = Http::withUserAgent('m3u-editor/'.config('app.version', '0.0'))
                ->timeout($this->timeout)
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$resolveHost}:{$port}:{$resolvedIp}"],
                    ],
                ])
                ->throw()
                ->get($current);

            $status = $response->status();
            if ($status < 300 || $status >= 400) {
                return $response;
            }

            $location = $response->header('Location');
            if (! $location) {
                return $response;
            }

            // Resolve relative redirects against the current URL.
            $current = (string) UriResolver::resolve(
                Utils::uriFor($current),
                Utils::uriFor($location),
            );
        }

        throw new \RuntimeException("Exceeded {$this->maxRedirects} redirects while fetching {$url}.");
    }

    /**
     * Read the response body in 64 KiB chunks into the temp file, reporting
     * progress and polling cancellation between chunks.
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

                $written = fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new \RuntimeException("Temp file write failed at {$downloaded} bytes (returned ".var_export($written, true).').');
                }
                $downloaded += $written;

                $this->reportDownloadProgress($this->bytesExpected ?? -1, $downloaded);

                if ($this->cancelled) {
                    break;
                }
            }

            $this->reportDownloadProgress($this->bytesExpected ?? -1, $downloaded, force: true);
        } finally {
            fclose($out);
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
     * set the cancelled flag. Time-throttled.
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
     * download failed.
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
            Log::error("DownloadCachedContentFile: markFailed itself failed for {$file->id}: {$e->getMessage()}");
        }
    }

    /**
     * Apply a cancellation flag: forget the row-id cancellation key and,
     * if the row is still alive, flip it to Failed with the given reason.
     *
     * Without this helper, every cancel exit in `handle()` would either
     * forget the key and return (leaving the row stuck on Downloading)
     * or open-code the row update and skip the no-row case. Centralising
     * the exit makes both halves of the invariant explicit:
     *
     *  - The row-id key is ALWAYS cleared so a future dispatch (new row,
     *    new id) cannot be blocked by a stale flag.
     *  - The status update is GUARDED by `whereKey($id)->update()` so a
     *    row that was deleted between the worker's `find()` and this
     *    call is NOT re-inserted. A mass update affecting 0 rows is
     *    harmless.
     *
     * Cancellation does NOT bump `failure_count` (cancellation is an
     * operator-initiated abort, not a transient failure), so the user
     * can retry without a cooldown penalty.
     */
    private function markCancelled(CachedContentFile $file, string $reason): void
    {
        Cache::forget(CachedContentFile::cancellationCacheKey($file->id));

        $affected = CachedContentFile::whereKey($file->id)->update([
            'status' => CachedContentFileStatus::Failed->value,
            'last_failed_at' => now(),
            'last_error_message' => mb_substr($reason, 0, 8000),
        ]);

        if ($affected > 0) {
            Log::info("DownloadCachedContentFile: row {$file->id} marked cancelled ({$reason}).");
        }
    }

    /**
     * Resolve the source URL string from the constructor's union.
     */
    private function resolveSourceUrl(): string
    {
        return (string) ($this->item->url ?? '');
    }

    /**
     * Sniff a container extension off the source URL so the cached file
     * keeps its type. Falls back to `.mp4` when the URL has no extension.
     */
    private function resolveExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'mkv', 'ts', 'm3u8'], true) ? ".{$ext}" : '.mp4';
    }

    /**
     * Build the relative storage path under the `cache` disk.
     */
    private function resolveStorageRelativePath(string $fingerprint, string $extension): string
    {
        return 'cache/'.$fingerprint.$extension;
    }
}
