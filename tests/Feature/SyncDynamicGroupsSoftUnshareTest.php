<?php

use App\Jobs\SyncDynamicGroups;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
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

// --- PR #1524 review (item 10a): dynamic_group_items pivot is hard-deleted
//     for stale groups so the Xtream category filter stops serving them. ---

it('hard-deletes dynamic_group_items for stale groups so Xtream stops serving them', function () {
    // Same stale-rule setup as the soft-unshare tests above. The CachedContentFile
    // pivot is soft-unshared (dropped_at stamped); the dynamic_group_items
    // membership pivot is hard-deleted. Without this, a client requesting the
    // old xtreamCategoryId() would keep receiving the stale list.
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

    // Membership pivot that should be wiped on the next sync (the user wants
    // the stale rule OUT of validKeys, so its members must not leak to Xtream).
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $staleGroup->id,
        'item_type' => Channel::class,
        'item_id' => 99999,
    ]);

    runSyncWithoutTmdb($playlist->id);

    expect(DB::table('dynamic_group_items')
        ->where('dynamic_group_id', $staleGroup->id)
        ->exists())->toBeFalse()
        // Soft-unshare on the cache pivot is unchanged — see the tests above.
        ->and(DB::table('cached_content_file_dynamic_groups')
            ->where('dynamic_group_id', $staleGroup->id)
            ->whereNull('dropped_at')
            ->exists())->toBeFalse();
});

// --- PR #1524 review (item 10d): a stale rule re-created with the same
//     (type, source, name) triple re-enables the tombstoned DG and rebuilds
//     membership, instead of leaving it disabled. ---

it('re-enables a tombstoned DynamicGroup row when the rule is re-created with the same triple', function () {
    // TMDB is unconfigured in this helper, so the "what does the rule want"
    // pass is bypassed and $validKeys ends up empty. We instead exercise the
    // other branch of SyncDynamicGroups::runSync(): when a rule IS processed
    // successfully, updateOrCreate() re-enables the tombstoned DG row and
    // rebuilds its membership. Stub TmdbService to return ids for the rule so
    // the membership path runs.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dynamic_groups_config' => [
            ['name' => 'Trending', 'enabled' => true, 'type' => 'vod', 'source' => 'trending'],
        ],
    ]);

    // Tombstoned DG row from a previous soft-unshare pass.
    $staleGroup = DynamicGroup::factory()->for($playlist)->for($user)->create([
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending',
        'enabled' => false,
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '42',
    ]);

    // Fake TmdbService so the rule-iteration loop finds a hit. We can't use
    // Http::fake() alone — TmdbService is bound in the SyncDynamicGroupsTest
    // beforeEach but this test file doesn't go through it. Instead, mock the
    // underlying method that collectTmdbIds() calls.
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldReceive('collectDynamicGroupResults')
        ->andReturn([['tmdb_id' => '42']]);
    app()->instance(TmdbService::class, $tmdb);

    // Re-running sync with the same (type, source, name) triple must re-enable
    // the existing row (no duplicate), not create a new tombstone.
    (new SyncDynamicGroups($playlist->id))->handle();

    $staleGroup->refresh();
    expect(DynamicGroup::where('playlist_id', $playlist->id)->count())->toBe(1)
        ->and($staleGroup->enabled)->toBeTrue()
        ->and(DB::table('dynamic_group_items')->where('dynamic_group_id', $staleGroup->id)->count())->toBe(1);
});

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
