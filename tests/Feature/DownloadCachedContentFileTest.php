<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory fires PlaylistListener -> SyncPipelineService ->
    // dispatch(ProcessM3uImport). Bus::fake() catches that so the listener
    // never queues real jobs in tests that touch Playlists.
    Bus::fake();

    // Per-test Http::fake is set INSIDE each test rather than as a `*`
    // wildcard here. A wildcard registered in beforeEach is matched
    // BEFORE any test-specific stub (Laravel iterates stubCallbacks in
    // registration order), so the failure-path test's 404 stub would be
    // shadowed by the 200 wildcard. Each test sets its own Http::fake()
    // with the exact URL pattern it needs.
});

// --- happy path ---

it('happy path: Channel with a real URL lands the file on disk and the row is Completed', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);

    Http::fake([
        'https://example.com/movie.mp4' => Http::response('binary file bytes', 200),
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Run the job synchronously (no queue). RefreshDatabase has wrapped
    // each test in a transaction, but the job's Storage::disk('cache')
    // call goes through Laravel's Storage fake, which is in-memory.
    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->disk)->toBe('cache')
        ->and($fresh->file_path)->not->toBeNull()
        ->and($fresh->file_size_bytes)->toBe(strlen('binary file bytes'))
        ->and($fresh->last_verified_at)->not->toBeNull()
        ->and(Storage::disk('cache')->exists($fresh->file_path))->toBeTrue();
});

it('happy path: Episode with a real URL lands the file on disk and the row is Completed', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 60625,
        'tvdb_id' => 888,
    ]);
    $episode = Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 5,
        'url' => 'https://example.com/episode.mp4',
    ]);

    Http::fake([
        'https://example.com/episode.mp4' => Http::response('binary file bytes', 200),
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'tvdb_id' => '888',
        'season_number' => 1,
        'episode_number' => 5,
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($episode, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->file_path)->not->toBeNull()
        ->and(Storage::disk('cache')->exists($fresh->file_path))->toBeTrue();
});

// --- failure path ---

it('failure path: HTTP error -> row is Failed and failure_count increments', function () {
    Storage::fake('cache');

    Http::fake([
        'https://example.com/broken.mp4' => Http::response('not found', 404),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 551,
        'url' => 'https://example.com/broken.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    try {
        (new DownloadCachedContentFile($channel, $row->id))->handle();
    } catch (RequestException) {
        // Expected - the job re-throws so Horizon marks it failed. PR B's
        // minimal job lets the exception bubble (PR C adds
        // try/catch-and-stay-alive behavior).
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull()
        // file_path remains null - nothing was written
        ->and($fresh->file_path)->toBeNull();
});

it('failure path: empty URL -> row is Failed without throwing', function () {
    // The job's resolveSourceUrl returns '' for an empty URL and handle()
    // marks Failed via the early return path. No HTTP call, no throw.
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 552,
        'url' => null, // empty source URL
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '552',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'failure_count' => 0,
    ]);

    // Should NOT throw - the empty-URL path is an early-return.
    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull();
});

it('failure path: missing cached_content_files row -> job logs and returns cleanly (no throw)', function () {
    // If the row has been deleted out from under the job (e.g. operator
    // cleanup between dispatch and run), the job must NOT crash - log and
    // exit cleanly so Horizon doesn't pollute failed_jobs with this.
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 553,
        'url' => 'https://example.com/movie.mp4',
    ]);

    // Pass a non-existent row ID.
    (new DownloadCachedContentFile($channel, 999999))->handle();

    expect(CachedContentFile::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// PR C upgrade tests - byte-level progress, cancellation, reclaim, backoff
// ---------------------------------------------------------------------------

it('regression: HTTP error -> last_error_message is persisted on the row', function () {
    // This is the most important new test. PR #1500 silently dropped
    // `last_error_message` because the column was missing from
    // CachedContentFile::$fillable. PR A added it to $fillable; PR C's
    // `markFailed()` writes it via `forceFill()` (regression-proof even if
    // a future change removes the column from $fillable). Without this
    // test we cannot tell whether markFailed is doing its job - the row
    // is "Failed" either way.
    Storage::fake('cache');

    Http::fake([
        'https://example.com/broken.mp4' => Http::response('not found', 404),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 554,
        'url' => 'https://example.com/broken.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '554',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    try {
        (new DownloadCachedContentFile($channel, $row->id))->handle();
    } catch (RequestException) {
        // Expected - the job re-throws so Horizon applies backoff.
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        // THE regression assertion: PR #1500 callers saw this as null.
        ->and($fresh->last_error_message)->not->toBeNull()
        ->and($fresh->last_error_message)->toContain('HTTP error')
        ->and($fresh->last_error_message)->toContain('404');
});

it('upgrade: byte-level progress reporting writes bytes_downloaded and bytes_per_second', function () {
    // Stream a body large enough to cross the 1 MiB progress threshold at
    // least twice, so the job writes a progress update on the row. Then
    // assert the row's bytes_downloaded / bytes_per_second / last_progress_at
    // reflect the simulated throughput.
    Storage::fake('cache');

    // 3 MiB of body - two throttle boundaries crossed (at 1 MiB and 2 MiB).
    // The third chunk (~1 MiB) sits below the threshold so it is the final
    // tally, written by Step 7 from Storage::size().
    $body = str_repeat('A', 3 * 1_048_576);
    Http::fake([
        'https://example.com/big.mp4' => Http::response($body, 200, [
            'Content-Length' => (string) strlen($body),
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 555,
        'url' => 'https://example.com/big.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '555',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->bytes_downloaded)->toBe(strlen($body))
        ->and($fresh->bytes_expected)->toBe(strlen($body))
        ->and($fresh->bytes_per_second)->not->toBeNull()
        ->and($fresh->bytes_per_second)->toBeGreaterThan(0)
        ->and($fresh->last_progress_at)->not->toBeNull();
});

it('upgrade: final progress reporting writes small downloads before completion', function () {
    Storage::fake('cache');
    $body = 'small download body';
    Http::fake(['https://example.com/small.mp4' => Http::response($body, 200, [
        'Content-Length' => (string) strlen($body),
    ])]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 556,
        'url' => 'https://example.com/small.mp4',
    ]);
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '556',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    expect($row->fresh()->bytes_downloaded)->toBe(strlen($body));
});

it('upgrade: cancellation key mid-flight aborts the download and the row stays Downloading', function () {
    // Pre-set the cancellation key (operator clicked "Cancel" in the
    // activity widget while the download was running). The job's progress
    // poll sees the flag, sets `$cancelled = true`, the chunk loop breaks
    // out of streamResponseToFile(), and handle() returns early without
    // stamping Completed or Failed.
    Storage::fake('cache');

    Http::fake([
        'https://example.com/cancel-me.mp4' => Http::response('short body', 200),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 556,
        'url' => 'https://example.com/cancel-me.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '556',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Pre-set the cancellation key the job polls mid-flight.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Downloading)
        // No file_path - the aborted bytes never made it to Storage.
        ->and($fresh->file_path)->toBeNull()
        // No last_error_message - cancellation is not a failure.
        ->and($fresh->last_error_message)->toBeNull();

    // Cache key was cleared by handle() after the abort.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($row->id)))->toBeFalse();
});

it('upgrade: pending-cancellation flag at job start aborts before any HTTP request', function () {
    // Operator cancelled the row between dispatch and worker pickup. The
    // job consumes the pending flag at Step 2 and returns without making
    // an HTTP request. Http::preventStrayRequests() would fail the test
    // if the GET fired.
    Storage::fake('cache');
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 557,
        'url' => 'https://example.com/pending-cancel.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '557',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Pending-cancel key is keyed by content_fingerprint (the row is
    // still alive at this point, but the flag is keyed by fingerprint so
    // it survives even when the row is deleted before the worker picks
    // up the job).
    Cache::put(CachedContentFile::pendingCancellationCacheKey($row->content_fingerprint), true, 60);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    // The pending-cancel check fires BEFORE the atomic reclaim, so the
    // row stays in its original status (Pending here).
    expect($fresh->status)->toBe(CachedContentFileStatus::Pending)
        ->and($fresh->file_path)->toBeNull();

    // Pending-cancel flag was consumed (Cache::pull semantics) - not still
    // present after the job ran.
    expect(Cache::has(CachedContentFile::pendingCancellationCacheKey($row->content_fingerprint)))->toBeFalse();
});

it('upgrade: Failed -> Downloading atomic reclaim transitions through Downloading to Completed', function () {
    // A retry case: the row is in Failed (a previous attempt failed and
    // the cooldown has elapsed). handle() must atomically flip it to
    // Downloading, then run the download to Completed, resetting
    // failure_count and last_failed_at.
    Storage::fake('cache');

    Http::fake([
        'https://example.com/retry.mp4' => Http::response('retry bytes', 200),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 558,
        'url' => 'https://example.com/retry.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '558',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'failure_count' => 2,
        'last_failed_at' => now()->subMinutes(5),
        'last_error_message' => 'Previous attempt died.',
    ]);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->failure_count)->toBe(0)
        ->and($fresh->last_failed_at)->toBeNull()
        ->and($fresh->last_error_message)->toBeNull()
        ->and($fresh->file_path)->not->toBeNull();
});

it('upgrade: row already Downloading (another worker claimed it) returns early without HTTP', function () {
    // Defensive path: another worker beat this one to the atomic reclaim.
    // handle() must not issue a duplicate GET. Http::preventStrayRequests()
    // fails the test if it does.
    Storage::fake('cache');
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 559,
        'url' => 'https://example.com/already-claimed.mp4',
    ]);

    // Row already in Downloading state - simulate another worker having
    // claimed it first.
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '559',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Downloading,
        'bytes_downloaded' => 524288,
    ]);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    // Status unchanged - the second worker returned early.
    expect($row->fresh()->status)->toBe(CachedContentFileStatus::Downloading);
});

it('upgrade: tries=3 and backoff()=[10,60,300] are configured for transient retry', function () {
    // We don't simulate a true retry in this test (that would require
    // queue worker integration); we verify the configuration is what the
    // framework will use when a transient failure occurs.
    $job = new DownloadCachedContentFile(
        Channel::factory()->make(['url' => 'https://example.com/x.mp4']),
        999999,
    );

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60, 300]);
});
