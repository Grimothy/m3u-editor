<?php

use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Playlist::factory fires SyncPipelineService -> dispatch(ProcessM3uImport)
// via a listener; Bus::fake() catches it so pivot tests don't trigger it.
beforeEach(function () {
    Bus::fake();
});

/**
 * Test fixtures: a user, a playlist, a DynamicGroup, and a CachedContentFile
 * row stamped with that playlist's ownership so the pivot rows make sense
 * from an FK perspective.
 */
function pivotFixtures(): array
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);
    $file = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    return [$user, $playlist, $group, $file];
}

// --- Pivot row creation via the relation ---

it('CachedContentFile::dynamicGroups() attaches a pivot row with dropped_at = null', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();

    $file->dynamicGroups()->attach($group->id);

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->dropped_at)->toBeNull();
});

it('CachedContentFile::dynamicGroups() round-trips via the inverse relation', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();

    $file->dynamicGroups()->attach($group->id);

    $group->load('cachedContentFiles');

    expect($group->cachedContentFiles)->toHaveCount(1)
        ->and($group->cachedContentFiles->first()->id)->toBe($file->id);
});

it('pivot composite unique key rejects duplicate (file_id, group_id) inserts', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();

    $file->dynamicGroups()->attach($group->id);

    // Re-attaching the same pair must throw — unique key protects
    // retention from accidentally counting the same (file, group)
    // membership twice.
    expect(fn () => $file->dynamicGroups()->attach($group->id))
        ->toThrow(QueryException::class);
});

// --- dropped_at soft-unshare semantics ---

it('stamping dropped_at on a pivot row keeps the row but marks it as soft-unshared', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();

    $file->dynamicGroups()->attach($group->id);

    $now = now();
    DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->update(['dropped_at' => $now]);

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->dropped_at)->not->toBeNull();
});

it('scopeOwnedByDynamicGroup excludes rows where dropped_at IS NOT NULL', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();
    [$user2, $playlist2, $group2, $file2] = pivotFixtures();

    $file->dynamicGroups()->attach($group->id);
    $file2->dynamicGroups()->attach($group2->id);

    // Soft-unshare the first pivot only.
    DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->update(['dropped_at' => now()]);

    // group 1: only the soft-unshared pivot exists; scopeOwnedByDynamicGroup(1) returns 0
    expect(CachedContentFile::ownedByDynamicGroup($group->id)->count())->toBe(0);

    // group 2: live pivot; scopeOwnedByDynamicGroup(2) returns 1
    expect(CachedContentFile::ownedByDynamicGroup($group2->id)->count())->toBe(1);
});

it('scopeOwnedByDynamicGroup returns multiple files when several share the group', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();
    $file2 = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $file3 = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $file->dynamicGroups()->attach($group->id);
    $file2->dynamicGroups()->attach($group->id);
    $file3->dynamicGroups()->attach($group->id);

    expect(CachedContentFile::ownedByDynamicGroup($group->id)->count())->toBe(3);
});

// --- FK cascade behavior ---

it('hard-deleting the CachedContentFile cascade-deletes its pivot rows', function () {
    [$user, $playlist, $group, $file] = pivotFixtures();
    $file->dynamicGroups()->attach($group->id);

    expect(DB::table('cached_content_file_dynamic_groups')->count())->toBe(1);

    $file->delete();

    expect(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0);
});
