<?php

use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

// --- evaluateForDynamicGroup: returns IDs of files no longer in live membership ---

it('evaluateForDynamicGroup returns IDs of files no longer in the group\'s live membership', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    // Live channel for tmdb=550 — its fingerprint stays wanted.
    $channel = Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 550]);
    $channel->dynamicGroups()->attach($group->id);

    // Cached file for tmdb=550 (alive — fingerprint matches live channel).
    $alive = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $alive->dynamicGroups()->attach($group->id);

    // Cached file for tmdb=999 (orphan — no live channel).
    $orphan = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $orphan->dynamicGroups()->attach($group->id);

    $ids = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($group->id);

    expect($ids->all())->toContain($orphan->id)
        ->and($ids->all())->not->toContain($alive->id);
});

it('evaluateForDynamicGroup evaluates each group independently', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $groupA = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);
    $groupB = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    $channelA = Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 100]);
    $channelA->dynamicGroups()->attach($groupA->id);

    $channelB = Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 200]);
    $channelB->dynamicGroups()->attach($groupB->id);

    $fileA = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '200',
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
    ]);
    $fileA->dynamicGroups()->attach($groupA->id); // orphan in groupA

    $fileB = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '100',
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
    ]);
    $fileB->dynamicGroups()->attach($groupB->id); // orphan in groupB

    $idsA = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($groupA->id);
    $idsB = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($groupB->id);

    expect($idsA->all())->toContain($fileA->id)
        ->and($idsA->all())->not->toContain($fileB->id)
        ->and($idsB->all())->toContain($fileB->id)
        ->and($idsB->all())->not->toContain($fileA->id);
});

// --- dropped_at IS NOT NULL: excluded from the live-scope evaluation ---

it('evaluateForDynamicGroup ignores pivot rows with dropped_at IS NOT NULL', function () {
    // A pivot row that was soft-unshared by SyncDynamicGroups must not
    // count as a "live" reference. The retention sweep only evaluates
    // pivot rows with dropped_at IS NULL — so a file whose only pivot
    // row is soft-unshared is OUT OF SCOPE for the DG sweep (it gets
    // evaluated through the standalone (user, playlist) sweep instead).
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    $channel = Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 550]);
    $channel->dynamicGroups()->attach($group->id);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $file->dynamicGroups()->attach($group->id);

    // Soft-unshare the pivot row.
    DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->update(['dropped_at' => now()]);

    // The file is OUT of scope for the DG sweep (its only pivot is
    // soft-unshared, so scopeOwnedByDynamicGroup filters it out).
    $ids = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($group->id);

    expect($ids->all())->not->toContain($file->id);
});

// --- never_expire short-circuit (Constraint 5) ---

it('evaluateForDynamicGroup skips files with never_expire = true', function () {
    // Constraint 5 (PR #1500 review): never_expire survives even when
    // the live membership no longer references the file. The pivot state
    // is irrelevant — never_expire lives on the CachedContentFile row.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    // No live channel for tmdb=999 — the cached file IS orphaned, but
    // never_expire=true pins it.
    $pinned = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'never_expire' => true,
    ]);
    $pinned->dynamicGroups()->attach($group->id);

    $ids = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($group->id);

    expect($ids->all())->not->toContain($pinned->id);
});

// --- Constraint 12: O(group size + files), NOT O(group size x files) ---

it('evaluateForDynamicGroup rebuilds the live fingerprint set ONCE per group, not per file', function () {
    // PR #1500 rebuilt the live fingerprint set PER cached file, walking
    // the group's channels for every file — O(group_size x files) work.
    // PR E's evaluateForDynamicGroup walks the channels ONCE, builds the
    // fingerprint set, then iterates the files in memory.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    // 10 live channels (each produces a fingerprint) + 10 cached files.
    for ($i = 0; $i < 10; $i++) {
        $channel = Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 1000 + $i]);
        $channel->dynamicGroups()->attach($group->id);

        $file = CachedContentFile::factory()->completed()->create([
            'content_type' => 'movie',
            'tmdb_id' => (string) (2000 + $i), // none match the channels — all orphan
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);
        $file->dynamicGroups()->attach($group->id);
    }

    $channelsSelects = 0;
    DB::listen(function ($query) use (&$channelsSelects): void {
        if (preg_match('/from "channels"/i', $query->sql)) {
            $channelsSelects++;
        }
    });

    $ids = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($group->id);

    expect($ids->count())->toBe(10)
        ->and($channelsSelects)->toBeLessThanOrEqual(
            3,
            "channels SELECTs should be bounded (1 rebuild per group), got {$channelsSelects}"
        );
});

// --- Series-type groups: episode fingerprint uses parent Series identity ---

it('evaluateForDynamicGroup matches series-type membership via parent Series identity', function () {
    // Episodes share the Series' tmdb_id/tvdb_id in their fingerprint.
    // The live fingerprint builder walks series->episodes and produces
    // fingerprints with the parent's identity.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'series']);

    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 60625,
        'tvdb_id' => 888,
    ]);
    Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 5,
    ]);
    // Attach the series to the group so the live fingerprint builder
    // walks it via $group->series.
    $series->dynamicGroups()->attach($group->id);

    $alive = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'tvdb_id' => '888',
        'season_number' => 1,
        'episode_number' => 5,
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $alive->dynamicGroups()->attach($group->id);

    $ids = app(CachedContentRetentionService::class)->evaluateForDynamicGroup($group->id);

    expect($ids->all())->not->toContain($alive->id);
});

it('evaluateForDynamicGroup returns empty for a non-existent group id', function () {
    $ids = app(CachedContentRetentionService::class)->evaluateForDynamicGroup(999_999);

    expect($ids->all())->toBeEmpty();
});
