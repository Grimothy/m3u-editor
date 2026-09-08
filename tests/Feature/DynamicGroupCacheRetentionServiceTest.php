<?php

use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DynamicGroupCacheRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);

    $this->service = app(DynamicGroupCacheRetentionService::class);
});

// Note: factory->completed() sets file_path/disk columns but doesn't actually write
// a file to storage — so DB-level assertions (row exists, pivot count) are the
// reliable signal here. The Storage::delete call in the service is a no-op when
// the file doesn't exist, so production behavior is unaffected.

it('detaches the pivot row when match_group_lifetime content falls out of membership', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'match_group_lifetime',
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Top Movies',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    $this->service->runAll();

    // Row hard-deleted (no referencing groups after retention ran)
    expect($file->fresh())->toBeNull();
});

it('does not delete file when any group uses never_expire mode', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Forever Group',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'never_expire',
            ],
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Disposable Group',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'match_group_lifetime',
            ],
        ],
    ]);

    $foreverGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Forever Group',
    ]);
    $disposableGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Disposable Group',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach([$foreverGroup->id, $disposableGroup->id]);

    $this->service->runAll();

    // File survives — never_expire rule pinned it
    expect($file->fresh())->not->toBeNull();

    // Disposable group should be detached, forever group stays
    expect($foreverGroup->fresh()->cachedContentFiles)->toHaveCount(1)
        ->and($disposableGroup->fresh()->cachedContentFiles)->toHaveCount(0);
});

it('stamps dropped_at on first sight and only detaches after extra_days for lifetime_plus_days', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'lifetime_plus_days',
                'cache_retention_extra_days' => 7,
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Top Movies',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    // First run: dropped_at gets stamped, no detach yet
    $this->service->runAll();

    $pivotRow = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->first();
    expect($pivotRow)->not->toBeNull()
        ->and($pivotRow->dropped_at)->not->toBeNull();
    expect($file->fresh())->not->toBeNull();

    // Second run within grace period: still no detach
    $this->service->runAll();
    expect(DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->exists())->toBeTrue();

    // Backdate dropped_at past the 7-day grace, run again: detach + hard-delete
    DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->update(['dropped_at' => now()->subDays(8)]);

    $this->service->runAll();

    expect($file->fresh())->toBeNull();
});

it('keeps files that are still in the group\'s live membership', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'match_group_lifetime',
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Top Movies',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    $this->service->runAll();

    expect($file->fresh())->not->toBeNull()
        ->and($file->dynamicGroups)->toHaveCount(1);
});

it('does nothing when there are no CachedContentFiles', function () {
    $this->service->runAll();

    expect(CachedContentFile::count())->toBe(0);
});
