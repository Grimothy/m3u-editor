<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| cache:cleanup-orphans contract
|--------------------------------------------------------------------------
|
| The command deletes `cached_content_files` rows where:
|   file_path IS NULL  AND  created_at < now()->subDays(--days)   (default 7)
|
| Per the command's docstring the intent is the "abandoned download"
| case: Pending/Downloading rows that were never picked up by a worker,
| or Failed rows that never produced a file. file_path is set on
| Completed by `DownloadCachedContentFile::handle()`, so the
| `whereNull('file_path')` filter naturally selects exactly those
| rows that never made it to disk.
|
| Tests in this file:
|  - cutoff boundary (older / newer than --days)
|  - Completed rows that DO have a file_path stay untouched regardless of age
|  - Completed row whose file vanished from disk (no on-disk cleanup contract)
|  - Pending / Downloading rows are KEPT while still inside the --days window
|    (ABANDONED Pending / Downloading rows older than --days ARE deleted -
|    this is the documented intent and a single test pins that behaviour)
|  - --dry-run reports without deleting
|  - custom --days value
|  - empty result returns SUCCESS without printing the "Deleted" line
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Disk fake - the command never touches files directly, but several
    // tests assert that on-disk files for surviving rows are untouched.
    Storage::fake('cache');
});

it('deletes a row with file_path IS NULL that is older than --days', function () {
    $user = User::factory()->create();
    // 10 days old, no file_path, Pending - the canonical orphan.
    $orphan = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(10),
        'updated_at' => Carbon::now()->subDays(10),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($orphan->id))->toBeNull();
});

it('keeps a row with file_path IS NULL that is newer than --days', function () {
    $user = User::factory()->create();
    // 3 days old, no file_path, Failed - inside the 7-day window.
    $fresh = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(3),
        'updated_at' => Carbon::now()->subDays(3),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($fresh->id))->not->toBeNull();
});

it('keeps a Completed row whose file_path is set (regardless of age)', function () {
    // A Completed row always has file_path populated by the downloader.
    // The whereNull('file_path') filter must exclude it from the
    // orphan sweep even if the row is very old.
    $user = User::factory()->create();
    Storage::disk('cache')->put('cache/stale-on-disk.mp4', 'still here');

    $completed = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Completed,
        'disk' => 'cache',
        'file_path' => 'cache/stale-on-disk.mp4',
        'file_size_bytes' => 14,
        'created_at' => Carbon::now()->subDays(60),
        'updated_at' => Carbon::now()->subDays(60),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    // Row stays put.
    expect(CachedContentFile::find($completed->id))->not->toBeNull()
        // The on-disk file is NOT touched by this command - that is the
        // retention service's job (different file_path resolution +
        // different disk delete).
        ->and(Storage::disk('cache')->exists('cache/stale-on-disk.mp4'))->toBeTrue();
});

it('does NOT touch a Completed row whose file has vanished from disk', function () {
    // The orphan cleanup command only deletes ROWS where file_path
    // IS NULL. A Completed row with a missing on-disk file still has
    // a file_path - it falls outside the orphan filter entirely. The
    // command must neither delete the row nor attempt to "reconcile"
    // the missing file; the row stays as-is for the retention service
    // or operator follow-up to handle.
    $user = User::factory()->create();
    // Deliberately do NOT put the file on disk - the row points
    // somewhere that no longer exists.
    $completed = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Completed,
        'disk' => 'cache',
        'file_path' => 'cache/vanished.mp4',
        'file_size_bytes' => 42,
        'created_at' => Carbon::now()->subDays(30),
        'updated_at' => Carbon::now()->subDays(30),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($completed->id))->not->toBeNull()
        ->and($completed->fresh()->file_path)->toBe('cache/vanished.mp4');
});

it('keeps a Pending row newer than --days (still inside the worker pickup window)', function () {
    // A Pending row created a few hours ago has not had time for a
    // worker to pick it up - the cutoff must keep it.
    $user = User::factory()->create();
    $pending = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'created_at' => Carbon::now()->subHours(6),
        'updated_at' => Carbon::now()->subHours(6),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($pending->id))->not->toBeNull();
});

it('DELETES an abandoned Pending row older than --days (documented intent)', function () {
    // This pins the contract per the command's docstring: a Pending row
    // that no worker ever picked up is an abandoned download and IS in
    // scope for the orphan sweep once it crosses the cutoff. The test
    // exists so a future change that adds `whereNotIn('status', [...])`
    // (which would deviate from the documented intent) is caught here.
    $user = User::factory()->create();
    $abandonedPending = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(15),
        'updated_at' => Carbon::now()->subDays(15),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($abandonedPending->id))->toBeNull();
});

it('keeps a Downloading row newer than --days (active download in flight)', function () {
    $user = User::factory()->create();
    $downloading = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Downloading,
        'file_path' => null,
        'created_at' => Carbon::now()->subHours(2),
        'updated_at' => Carbon::now()->subHours(2),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($downloading->id))->not->toBeNull();
});

it('--dry-run reports the count but performs no deletion', function () {
    $user = User::factory()->create();
    $orphan1 = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(10),
        'updated_at' => Carbon::now()->subDays(10),
    ]);
    $orphan2 = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(12),
        'updated_at' => Carbon::now()->subDays(12),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans --dry-run')
            ->expectsOutputToContain('[DRY RUN] Identified 2 orphan cached_content_files rows older than 7 day(s).')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    // Both rows must still be present - --dry-run is a no-op for deletes.
    expect(CachedContentFile::find($orphan1->id))->not->toBeNull()
        ->and(CachedContentFile::find($orphan2->id))->not->toBeNull();
});

it('returns SUCCESS and prints the identified line (no "Deleted" line) when there are zero orphans', function () {
    // The command short-circuits before the chunkById loop when the
    // count is 0, so the "Deleted N" line must NOT appear. The
    // "Identified 0" line still prints for operator visibility.
    $user = User::factory()->create();
    // A fresh row with file_path IS NULL but inside the window -
    // nothing for the command to find.
    CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'created_at' => Carbon::now()->subHours(1),
        'updated_at' => Carbon::now()->subHours(1),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')
            ->expectsOutputToContain('Identified 0 orphan cached_content_files rows older than 7 day(s).')
            ->doesntExpectOutputToContain('Deleted')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('respects a custom --days value', function () {
    // Pin: --days=30 widens the cutoff so a 10-day-old row that the
    // default 7-day sweep would have deleted survives.
    $user = User::factory()->create();
    $middleAged = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(10),
        'updated_at' => Carbon::now()->subDays(10),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans --days=30')
            ->expectsOutputToContain('older than 30 day(s)')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($middleAged->id))->not->toBeNull();
});

it('clamps --days below 1 up to 1 (no retention window shorter than 1 day)', function () {
    // The command uses `max(1, (int) $this->option('days'))` so an
    // operator passing --days=0 gets a 1-day window, not a wall-clock
    // deletion of every file_path-IS-NULL row. The test exercises
    // that clamp by pairing two rows: one 6 hours old (must survive -
    // inside the clamped 1-day window) and one 2 days old (correctly
    // deleted - outside the clamped 1-day window). The readout text
    // pins the surfaced "older than 1 day(s)" message.
    $user = User::factory()->create();
    $hoursOld = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'created_at' => Carbon::now()->subHours(6),
        'updated_at' => Carbon::now()->subHours(6),
    ]);
    $daysOld = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(2),
        'updated_at' => Carbon::now()->subDays(2),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans --days=0')
            ->expectsOutputToContain('older than 1 day(s)')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($hoursOld->id))->not->toBeNull()
        // The 2-day-old row sits outside the clamped 1-day window, so
        // it IS deleted - the clamp only prevents operator error from
        // shrinking the window below 1 day.
        ->and(CachedContentFile::find($daysOld->id))->toBeNull();
});

it('deletes multiple orphans in one chunk and prints the count', function () {
    // Pin the multi-row happy path: 3 old orphans in, 3 out, the count
    // message reflects the deletion total. Uses a tiny chunk size via
    // a second invocation wouldn't actually matter because chunkById
    // defaults to 1000 and we only have 3 - this asserts END-TO-END.
    $user = User::factory()->create();
    $orphans = collect(range(1, 3))->map(fn () => CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'created_at' => Carbon::now()->subDays(20),
        'updated_at' => Carbon::now()->subDays(20),
    ]));

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')
            ->expectsOutputToContain('Identified 3 orphan cached_content_files rows older than 7 day(s).')
            ->expectsOutputToContain('Deleted 3 orphan cached_content_files rows.')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    foreach ($orphans as $orphan) {
        expect(CachedContentFile::find($orphan->id))->toBeNull();
    }
});
