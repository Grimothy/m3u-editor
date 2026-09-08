<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Bus::fake() is mandatory — Playlist::factory()->create() fires
    // PlaylistCreated → SyncPipelineService → dispatch(ProcessM3uImport)
    // which hits Redis in real env. The dispatch-service tests themselves
    // never trigger DownloadCachedContentFile::dispatch (that's covered by
    // the Phase 2 command tests with their own Bus::fake()), so this only
    // needs to swallow the model-event-triggered side effect.
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->service = app(DynamicGroupCacheDispatchService::class);
});

it('findCompletedCache returns the Completed file for matching identity', function () {
    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    expect($this->service->findCompletedCache('movie', '550', null, null, null))->toBeInstanceOf(CachedContentFile::class)
        ->and($this->service->findCompletedCache('movie', '550', null, null, null)->id)->toBe($file->id);
});

it('findCompletedCache ignores quality when looking up (returns first match regardless of quality)', function () {
    $hdFile = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);
    $fourKFile = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '4K',
    ]);

    // No quality filter on the lookup — either match is acceptable.
    $found = $this->service->findCompletedCache('movie', '550', null, null, null);

    expect($found)->not->toBeNull()
        ->and(collect([$hdFile->id, $fourKFile->id])->contains($found->id))->toBeTrue();
});

it('findCompletedCache returns null when no Completed row matches', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'quality' => null,
    ]);

    expect($this->service->findCompletedCache('movie', '550', null, null, null))->toBeNull();
});

it('findCompletedCache returns null for non-Completed rows (Pending, Downloading, Failed)', function () {
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'status' => CachedContentFileStatus::Pending,
    ]);
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'status' => CachedContentFileStatus::Downloading,
    ]);
    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '552',
    ]);

    expect($this->service->findCompletedCache('movie', '550', null, null, null))->toBeNull()
        ->and($this->service->findCompletedCache('movie', '551', null, null, null))->toBeNull()
        ->and($this->service->findCompletedCache('movie', '552', null, null, null))->toBeNull();
});

it('findCompletedCache requires season + episode to match for episode content', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode',
        'tmdb_id' => '1399',
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    // Same tmdb, different season/episode → no match
    expect($this->service->findCompletedCache('episode', '1399', null, 1, 2))->toBeNull()
        ->and($this->service->findCompletedCache('episode', '1399', null, 2, 1))->toBeNull();

    // Exact match
    expect($this->service->findCompletedCache('episode', '1399', null, 1, 1))->not->toBeNull();
});

it('findCacheableRuleForChannel returns the matching cache-enabled rule', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'tmdb_id' => 550,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);
    $channel->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_prefer_quality_keyword' => '4K',
            ],
        ],
    ]);

    $found = $this->service->findCacheableRuleForChannel($channel->fresh());

    expect($found)->not->toBeNull()
        ->and($found['group']->id)->toBe($group->id)
        ->and($found['rule']['cache_enabled'])->toBeTrue()
        ->and($found['rule']['cache_prefer_quality_keyword'])->toBe('4K');
});

it('findCacheableRuleForChannel returns null when no rule has cache_enabled', function () {
    $channel = Channel::factory()->for($this->playlist)->create();
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);
    $channel->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'name' => 'Top Movies',
                'cache_enabled' => false,
            ],
        ],
    ]);

    expect($this->service->findCacheableRuleForChannel($channel->fresh()))->toBeNull();
});

it('findCacheableRuleForChannel returns null when channel has no dynamic groups', function () {
    $channel = Channel::factory()->for($this->playlist)->create();

    expect($this->service->findCacheableRuleForChannel($channel))->toBeNull();
});

it('findCacheableRuleForChannel returns null when playlist has no rule matching the group name', function () {
    $channel = Channel::factory()->for($this->playlist)->create();
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);
    $channel->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Different Name', 'cache_enabled' => true],
        ],
    ]);

    expect($this->service->findCacheableRuleForChannel($channel->fresh()))->toBeNull();
});

it('findCacheableRuleForChannel returns the first cache-enabled rule across multiple groups', function () {
    $channel = Channel::factory()->for($this->playlist)->create();
    $disabledGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Disabled Group',
    ]);
    $enabledGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Enabled Group',
    ]);
    $channel->dynamicGroups()->attach($disabledGroup);
    $channel->dynamicGroups()->attach($enabledGroup);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Disabled Group', 'cache_enabled' => false],
            ['name' => 'Enabled Group', 'cache_enabled' => true, 'cache_prefer_quality_keyword' => '4K'],
        ],
    ]);

    $found = $this->service->findCacheableRuleForChannel($channel->fresh());

    expect($found)->not->toBeNull()
        ->and($found['group']->id)->toBe($enabledGroup->id)
        ->and($found['rule']['cache_prefer_quality_keyword'])->toBe('4K');
});

it('findCacheableRuleForEpisode goes through the parent series', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'tmdb_id' => 1399,
    ]);
    $episode = Episode::factory()->for($this->playlist)->for($series)->create([
        'season' => 1,
        'episode_num' => 1,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'tmdb',
        'name' => 'Top Series',
    ]);
    $series->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Top Series', 'cache_enabled' => true],
        ],
    ]);

    $found = $this->service->findCacheableRuleForEpisode($episode->fresh());

    expect($found)->not->toBeNull()
        ->and($found['group']->id)->toBe($group->id);
});

it('findCacheableRuleForEpisode returns null when series has no dynamic groups', function () {
    $series = Series::factory()->for($this->playlist)->create();
    $episode = Episode::factory()->for($this->playlist)->for($series)->create();

    expect($this->service->findCacheableRuleForEpisode($episode))->toBeNull();
});

it('resolveQuality returns the rule cache_prefer_quality_keyword when set', function () {
    expect($this->service->resolveQuality(['cache_prefer_quality_keyword' => '4K']))->toBe('4K')
        ->and($this->service->resolveQuality(['cache_prefer_quality_keyword' => '1080p']))->toBe('1080p');
});

it('resolveQuality returns null when the rule does not set the keyword', function () {
    expect($this->service->resolveQuality([]))->toBeNull()
        ->and($this->service->resolveQuality(['cache_enabled' => true]))->toBeNull();
});

it('shouldSkip returns false when no existing row matches the fingerprint', function () {
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();
});

it('shouldSkip returns true when an existing row is Completed (cross-playlist dedup)', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeTrue();
});

it('shouldSkip returns true when a Failed row is within the retry cooldown window', function () {
    app(GeneralSettings::class)->refresh();

    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 0,
        'last_failed_at' => now()->subMinutes(30),
    ]);

    // Default retry_cooldown_minutes = 360 (6h) → 30 min ago is still in cooldown
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeTrue();
});

it('shouldSkip returns false when a Failed row is past the retry cooldown window', function () {
    app(GeneralSettings::class)->refresh();

    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 0,
        'last_failed_at' => now()->subHours(7),
    ]);

    // Past the 360-minute (6h) retry window
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();
});

it('shouldSkip uses the failure_cooldown_hours for high failure_count rows', function () {
    app(GeneralSettings::class)->refresh();

    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 5,
        'last_failed_at' => now()->subHours(12),
    ]);

    // Default failure_cooldown_hours = 24, so 12h ago is still in cooldown
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeTrue();
});

it('shouldSkip returns false for Pending and Downloading rows', function () {
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'status' => CachedContentFileStatus::Pending,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();

    CachedContentFile::query()->update(['status' => CachedContentFileStatus::Downloading]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();
});
