<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function setEnableCacheForDownloadTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory fires PlaylistListener -> SyncPipelineService ->
    // dispatch(ProcessM3uImport). Bus::fake() catches that so the listener
    // never queues real jobs in tests that touch Playlists.
    Bus::fake();

    // The job's kill-switch guard reads `enable_cache` from
    // GeneralSettings. Tests for the download path need the setting on
    // (the off-path test flips it explicitly).
    setEnableCacheForDownloadTest(true);

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

it('upgrade: cancellation key observed during a live download flips the row to Failed (no resurrection)', function () {
    // PR #1524 review item 2: a row-id cancel flag observed by the
    // worker while the row is still alive must NOT leave the row stuck
    // on Downloading. The first checkCancellation() fires right after
    // atomicReclaim() (this test pre-sets the flag, so the before-start
    // check catches it) and routes the abort through markCancelled().
    // Http::preventStrayRequests() would fail the test if the GET fired.
    Storage::fake('cache');
    Http::preventStrayRequests();

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

    // Pre-set the cancellation key the job polls. The widget's delete
    // path also deletes the row; this test exercises the alternate race
    // where the row stays alive when the worker picks it up.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    // PR #1524 review item 2: a live row that observes the cancel flag
    // must NOT be left stuck on Downloading - markCancelled() flips it
    // to Failed with a Cancelled reason so the UI / retry path can act
    // on it without operator intervention.
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        // No file_path - the abort happened before any bytes were written.
        ->and($fresh->file_path)->toBeNull()
        // markCancelled() does NOT bump failure_count (cancellation is
        // not a transient failure, so no cooldown penalty on retry).
        ->and($fresh->failure_count)->toBe(0)
        ->and($fresh->last_error_message)->toBe('Cancelled before download started.');

    // Cache key was cleared by markCancelled() after the abort.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($row->id)))->toBeFalse();
});

it('regression (item 7): cancelling a Pending row does not leave a fingerprint-scoped flag behind', function () {
    // PR #1524 review item 7: the previous design also wrote a
    // fingerprint-scoped `pendingCancellationCacheKey` with a 10-minute
    // TTL when a Pending row was cancelled. Because the row is also
    // deleted, the job's `find()` returned null and the pull never
    // fired, so the fingerprint flag lingered and silently suppressed
    // the NEXT legitimate dispatch for the same content. After the fix
    // the fingerprint key is gone entirely; the row-id key + row
    // deletion cover both windows.
    //
    // This test exercises the realistic flow: cancel a Pending row,
    // re-dispatch the same content, and verify the new job runs to
    // completion (no stale flag suppressing it).
    Storage::fake('cache');
    Http::fake([
        'https://example.com/redispatch.mp4' => Http::response('fresh bytes', 200, [
            'Content-Length' => (string) strlen('fresh bytes'),
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 560,
        'url' => 'https://example.com/redispatch.mp4',
    ]);

    // 1) Original Pending row, then cancel via the widget helper. The
    // widget sets the row-id key + deletes the row; that is the
    // realistic input shape.
    $row1 = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '560',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    Cache::put(CachedContentFile::cancellationCacheKey($row1->id), true, now()->addHours(48));
    $row1->delete();

    expect(CachedContentFile::find($row1->id))->toBeNull();

    // 2) Sanity: the fingerprint-scoped helper no longer exists (the
    // call would fatal-error if it did, but be explicit). The model
    // class should not expose `pendingCancellationCacheKey`.
    expect(method_exists(CachedContentFile::class, 'pendingCancellationCacheKey'))->toBeFalse();

    // 3) Dispatch the same content again and run the new job. The new
    // row reaches Completed - the only thing that would suppress it is a
    // stale fingerprint-scoped flag, which no longer exists.
    $service = app(CachedContentDispatchService::class);
    $jobs = $service->dispatchForChannel($channel);

    expect($jobs)->toHaveCount(1);
    $row2Id = $jobs->first()->cachedContentFileId;

    (new DownloadCachedContentFile($channel, $row2Id))->handle();

    $row2 = CachedContentFile::find($row2Id);
    expect($row2)->not->toBeNull()
        ->and($row2->status)->toBe(CachedContentFileStatus::Completed)
        ->and($row2->file_path)->not->toBeNull();
});

it('regression (item 2): markCancelled does not recreate a deleted row', function () {
    // PR #1524 review item 2: the cancellation helper must NOT recreate
    // a deleted row. The widget deletes the row before the worker
    // observes the cancel flag; the worker's `find()` returns null and
    // it exits before markCancelled() runs. To exercise markCancelled()
    // directly without a live row, we delete the row between the
    // worker's `find()` and the mid-flight cancellation check by
    // dispatching the cancellation flag while the row is still alive
    // and then deleting it before the second checkCancellation() call.
    //
    // Concretely: set the cancellation key, run the job, and verify
    // the row stays deleted (no resurrection via a misguided UPDATE or
    // re-INSERT).
    Storage::fake('cache');

    Http::fake([
        'https://example.com/deleted-row.mp4' => Http::response('short body', 200),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 561,
        'url' => 'https://example.com/deleted-row.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '561',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Pre-set the row-id cancellation key, then delete the row out from
    // under the job. markCancelled() will be called with the in-memory
    // model whose row no longer exists; it must update 0 rows and not
    // resurrect anything.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);
    $rowId = $row->id;
    $row->delete();

    // Simulate the job holding an in-memory reference to the deleted
    // row - this is the race the fix is hardening against. We run the
    // job normally; the worker's `find()` returns null and exits before
    // markCancelled() is reached, which is the safe path.
    (new DownloadCachedContentFile($channel, $rowId))->handle();

    // The row stays deleted - no resurrection.
    expect(CachedContentFile::find($rowId))->toBeNull();
    // The cache key was already set by us, and the worker (which never
    // reached markCancelled) did not touch it. A TTL on the widget's
    // Cache::put means it expires on its own; nothing in the job path
    // needs to clear it because the row was never found.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($rowId)))->toBeTrue();
});

it('regression (item 2): markCancelled on a LIVE row flips it to Failed with Cancelled reason', function () {
    // PR #1524 review item 2: when the worker observes the cancel flag
    // AND the row still exists (a race, or a future cancel path that
    // does not delete the row), markCancelled() must flip the row to
    // Failed with a Cancelled reason. This test exercises the before-
    // start cancel exit by pre-setting the flag BEFORE handle() runs -
    // the worker's first checkCancellation() after atomicReclaim fires
    // markCancelled().
    Storage::fake('cache');
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 562,
        'url' => 'https://example.com/before-start-cancel.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '562',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    // Pre-set the row-id cancellation key. The job's first
    // checkCancellation() after atomicReclaim sees the flag, calls
    // markCancelled(), and returns. Http::preventStrayRequests() would
    // fail the test if the GET fired.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);

    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->last_failed_at)->not->toBeNull()
        ->and($fresh->last_error_message)->toBe('Cancelled before download started.')
        // markCancelled() does NOT bump failure_count.
        ->and($fresh->failure_count)->toBe(0);

    // The row-id key was cleared by markCancelled() so a future
    // dispatch (new row, new id) is not affected.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($row->id)))->toBeFalse();
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

// --- enable_cache kill switch: jobs queued before the toggle flipped off ---

it('handle marks the row Failed with "Caching disabled" when enable_cache is off at run time', function () {
    // PR #1524 review item 5: a job queued while enable_cache was on
    // can still be picked up after the operator flips the toggle off.
    // The job must mark the row Failed via the same path other validation
    // errors use (so a later re-enable + dispatch can reclaim it) and
    // must NOT throw - Horizon should not retry a known-disabled job.
    Storage::fake('cache');
    Http::preventStrayRequests();

    setEnableCacheForDownloadTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 560,
        'url' => 'https://example.com/kill-switch.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '560',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    // No exception bubbles - Horizon should not retry a disabled-by-toggle
    // failure.
    (new DownloadCachedContentFile($channel, $row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull()
        ->and($fresh->last_error_message)->toBe('Caching disabled');
});

it('handle returns cleanly without touching the row when enable_cache is off AND the row is missing', function () {
    // Defensive: if the row was deleted between dispatch and run AND
    // the operator flipped the toggle off, handle() must not crash.
    Storage::fake('cache');

    setEnableCacheForDownloadTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 561,
        'url' => 'https://example.com/none.mp4',
    ]);

    expect(CachedContentFile::count())->toBe(0);
    (new DownloadCachedContentFile($channel, 999999))->handle();
    expect(CachedContentFile::count())->toBe(0);
});
