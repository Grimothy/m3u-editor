<?php

use App\Jobs\SyncDynamicGroups;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Playlist::factory fires SyncPipelineService -> dispatch(ProcessM3uImport)
// via a listener; Bus::fake() catches it.
beforeEach(function () {
    Bus::fake();
});

/**
 * Run SyncDynamicGroups without configuring TMDB. With no TMDB api key,
 * the rule-iteration loop in runSync() is skipped, leaving $validKeys
 * empty — so every pre-existing DynamicGroup for the playlist is
 * identified as "stale" by the cleanup pass and the new soft-unshare
 * logic kicks in.
 */
function runSyncWithoutTmdb(int $playlistId): void
{
    $job = new SyncDynamicGroups($playlistId);
    $job->handle();
}

// --- Soft-unshare on rename / disable ---

it('soft-unshares pivot rows when a rule is renamed (stale DG falls out of validKeys)', function () {
    // Setup: a playlist whose dynamic_groups_config has rule "Renamed".
    // A pre-existing DynamicGroup row "Original" simulates the previous
    // sync that produced the pivot rows. TMDB is not configured, so the
    // rule-iteration loop is skipped — "Original" never lands in validKeys
    // and the cleanup pass treats it as stale.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dynamic_groups_config' => [
            ['name' => 'Renamed', 'enabled' => true, 'type' => 'vod', 'source' => 'trending'],
        ],
    ]);

    $staleGroup = DynamicGroup::factory()->for($playlist)->for($user)->create([
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Original',
        'enabled' => true,
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $file->dynamicGroups()->attach($staleGroup->id);

    runSyncWithoutTmdb($playlist->id);

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $staleGroup->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->dropped_at)->not->toBeNull();

    // The DynamicGroup row itself is tombstoned (enabled=false), NOT deleted.
    $staleGroup->refresh();
    expect($staleGroup->enabled)->toBeFalse()
        ->and(DynamicGroup::find($staleGroup->id))->not->toBeNull();
});

it('soft-unshares pivot rows when a rule is disabled', function () {
    // Setup: the rule that previously produced the DG has been
    // disabled in dynamic_groups_config. TMDB unconfigured → $validKeys
    // is empty → cleanup identifies the DG as stale.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dynamic_groups_config' => [
            ['name' => 'Original', 'enabled' => false, 'type' => 'vod', 'source' => 'trending'],
        ],
    ]);

    $staleGroup = DynamicGroup::factory()->for($playlist)->for($user)->create([
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Original',
        'enabled' => true,
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $file->dynamicGroups()->attach($staleGroup->id);

    runSyncWithoutTmdb($playlist->id);

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $staleGroup->id)
        ->first();

    expect($pivot->dropped_at)->not->toBeNull();
});

it('does NOT stamp dropped_at on already-soft-unshared pivot rows (idempotent)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $staleGroup = DynamicGroup::factory()->for($playlist)->for($user)->create([
        'name' => 'Original',
        'enabled' => true,
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $file->dynamicGroups()->attach($staleGroup->id);

    // Pre-stamp dropped_at to simulate a previous soft-unshare pass.
    $firstStamp = now()->subHour();
    DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $staleGroup->id)
        ->update(['dropped_at' => $firstStamp]);

    runSyncWithoutTmdb($playlist->id);

    // dropped_at should NOT have been overwritten — the WHERE NULL guard
    // in SyncDynamicGroups prevents overwriting an already-stamped row.
    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $staleGroup->id)
        ->first();

    expect($pivot->dropped_at)->toBe($firstStamp->toDateTimeString());
});

// --- never_expire: Constraint 5 — never_expire files survive soft-unshare ---

it('never_expire files linked via a soft-unshared pivot are still retained (independent of pivot state)', function () {
    // The retention check looks at the CachedContentFile row's
    // never_expire, not the pivot state. So even after a pivot row gets
    // soft-unshared by SyncDynamicGroups, the file's never_expire=true
    // flag continues to pin the file. The pivot state is irrelevant for
    // never_expire's retention decision.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $staleGroup = DynamicGroup::factory()->for($playlist)->for($user)->create([
        'name' => 'Original',
        'enabled' => true,
    ]);

    $pinnedFile = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'never_expire' => true,
    ]);
    $pinnedFile->dynamicGroups()->attach($staleGroup->id);

    runSyncWithoutTmdb($playlist->id);

    // Pivot is soft-unshared (stale rule cleanup).
    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $pinnedFile->id)
        ->where('dynamic_group_id', $staleGroup->id)
        ->first();
    expect($pivot->dropped_at)->not->toBeNull();

    // But the file is still in the DB, with never_expire=true intact.
    $pinnedFile->refresh();
    expect($pinnedFile->never_expire)->toBeTrue()
        ->and(CachedContentFile::find($pinnedFile->id))->not->toBeNull();
});
