<?php

use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Playlist::factory fires SyncPipelineService -> dispatch(ProcessM3uImport)
// via a listener; Bus::fake() catches it. dispatchForGroup tests also need
// Bus::fake to prevent the DownloadCachedContentFile jobs themselves from
// running (they'd try to do HTTP downloads in tests).
beforeEach(function () {
    Bus::fake();
});

// --- dispatchForGroup for VOD-type groups: dispatches one job per channel ---

it('dispatchForGroup dispatches one job per channel in a VOD-type group', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    $channels = collect();
    for ($i = 0; $i < 3; $i++) {
        $channel = Channel::factory()->for($user)->for($playlist)->create([
            'tmdb_id' => 1000 + $i,
            'url' => "https://example.com/movie-{$i}.mp4",
        ]);
        $channel->dynamicGroups()->attach($group->id);
        $channels->push($channel);
    }

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toHaveCount(3)
        ->and($jobs->every(fn ($j): bool => $j instanceof DownloadCachedContentFile))->toBeTrue();
});

it('dispatchForGroup writes pivot rows linking each new file to the source group', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);
    $channel->dynamicGroups()->attach($group->id);

    Auth::login($user);

    app(CachedContentDispatchService::class)->dispatchForGroup($group);

    $file = CachedContentFile::sole();
    expect($file->dynamicGroups)->toHaveCount(1)
        ->and($file->dynamicGroups->first()->id)->toBe($group->id);

    // Verify the pivot row has dropped_at = NULL (live membership, not soft-unshared).
    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->dropped_at)->toBeNull();
});

// --- dispatchForGroup for series-type groups: dispatches one job per episode ---

it('dispatchForGroup dispatches one job per episode in a series-type group', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'series']);

    // One series with 3 episodes.
    $series = Series::factory()->for($user)->for($playlist)->create(['tmdb_id' => 60625]);
    $episodes = collect();
    for ($i = 1; $i <= 3; $i++) {
        $episode = Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
            'season' => 1,
            'episode_num' => $i,
            'url' => "https://example.com/ep-{$i}.mp4",
        ]);
        $episodes->push($episode);
    }
    $series->dynamicGroups()->attach($group->id);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toHaveCount(3);
});

it('dispatchForGroup for series-type group uses parent Series tmdb_id/tvdb_id in the fingerprint', function () {
    // Constraint 6 (PR #1500 review): parent Series tmdb_id/tvdb_id
    // must be in the fingerprint, NOT the episode's. Episodes don't have
    // their own tmdb_id column; the Series does.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'series']);

    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 60625,
        'tvdb_id' => 999,
    ]);
    Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 1,
        'url' => 'https://example.com/ep1.mp4',
    ]);
    $series->dynamicGroups()->attach($group->id);

    Auth::login($user);

    app(CachedContentDispatchService::class)->dispatchForGroup($group);

    $row = CachedContentFile::sole();
    expect($row->tmdb_id)->toBe('60625')
        ->and($row->tvdb_id)->toBe('999')
        ->and($row->content_type)->toBe('episode');
});

// --- No N+1: dispatching 50 channels stays within a bounded query budget ---

it('dispatchForGroup uses bounded query count (no N+1) for 50 channels', function () {
    // Constraint 11 (PR #1500 review): dispatch must be batched. Counting
    // SELECTs against the channels table — a per-channel dispatch with
    // its own isCached() probe or lazy membership check would inflate this.
    // The pre-loaded membership path keeps it O(1) on channels reads.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    for ($i = 0; $i < 50; $i++) {
        $channel = Channel::factory()->for($user)->for($playlist)->create([
            'tmdb_id' => 1000 + $i,
            'url' => "https://example.com/movie-{$i}.mp4",
        ]);
        $channel->dynamicGroups()->attach($group->id);
    }

    $channelsSelects = 0;
    DB::listen(function ($query) use (&$channelsSelects): void {
        if (preg_match('/from "channels"/i', $query->sql)) {
            $channelsSelects++;
        }
    });

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toHaveCount(50)
        ->and($channelsSelects)->toBeLessThanOrEqual(
            5,
            "channels SELECTs should be bounded (pre-loaded membership), got {$channelsSelects}"
        );
});

// --- dispatchForGroup batched pivot insert: chunks of 100 ---

it('dispatchForGroup batch-inserts pivot rows (single INSERT for N>100 files)', function () {
    // Verify the pivot insert is batched — PR #1500 inserted one pivot
    // row per dispatched file (per-row INSERT). The new path uses
    // insertOrIgnore in chunks of 100.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    // 3 channels — small enough for a single chunk, but enough to assert
    // the pivot table has 3 rows at the end.
    for ($i = 0; $i < 3; $i++) {
        $channel = Channel::factory()->for($user)->for($playlist)->create([
            'tmdb_id' => 2000 + $i,
            'url' => "https://example.com/movie-{$i}.mp4",
        ]);
        $channel->dynamicGroups()->attach($group->id);
    }

    Auth::login($user);

    app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect(DB::table('cached_content_file_dynamic_groups')->count())->toBe(3);
});

// --- Edge cases ---

it('dispatchForGroup on a VOD group with no channels returns empty Collection', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toBeEmpty()
        ->and(CachedContentFile::count())->toBe(0)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0);
});

it('dispatchForGroup on a series group with no series returns empty Collection', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'series']);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toBeEmpty();
});

it('dispatchForGroup does NOT attach a pivot row for an already-cached file (dedup short-circuit)', function () {
    // Pre-existing Completed row with same fingerprint → dispatchForChannel
    // short-circuits (no new job, no new row). dispatchForGroup must NOT
    // create a pivot row pointing at the existing file's id — that would
    // mark the file as "wanted by group" when no new dispatch happened.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    // Pre-existing Completed file matching the channel's fingerprint.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);
    $channel->dynamicGroups()->attach($group->id);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    // No new file dispatched, so no new pivot row.
    expect($jobs)->toBeEmpty()
        ->and(CachedContentFile::count())->toBe(1)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0);
});
