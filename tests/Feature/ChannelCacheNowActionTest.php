<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\VodResource;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 */
function setEnableCacheForChannelTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    setEnableCacheForChannelTest(true);
    Bus::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the VOD list page when enable_cache is on', function () {
    Livewire::test(ListVod::class)->assertOk();
});

it('exposes the is_cached IconColumn with a label', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 1,
    ]);

    Livewire::test(ListVod::class)
        ->assertOk()
        ->loadTable();
});

it('shows the Cache Now row action on a VOD channel with a resolvable URL', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'title' => 'Cacheable',
        'url' => 'https://example.com/cacheable.mp4',
    ]);

    Livewire::test(ListVod::class)
        ->assertTableActionVisible('cache_now', $channel);
});

it('hides the Cache Now row action when enable_cache is off', function () {
    setEnableCacheForChannelTest(false);

    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 67890,
        'url' => 'https://example.com/x.mp4',
    ]);

    Livewire::test(ListVod::class)
        ->assertTableActionHidden('cache_now', $channel);
});

it('hides the Cache Now row action when the channel has no resolvable URL', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 11111,
        'url' => '',
    ]);

    Livewire::test(ListVod::class)
        ->assertTableActionHidden('cache_now', $channel);
});

it('clicking Cache Now dispatches DownloadCachedContentFile', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 22222,
        'title' => 'Click me',
        'url' => 'https://example.com/click.mp4',
    ]);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Cache download queued');

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('Cache Now on a VOD channel that already has a Completed cached file surfaces the "Already cached" notification', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 33333,
        'url' => 'https://example.com/already.mp4',
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '33333',
    ]);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Already cached');

    // No new dispatch should fire for an already-cached row.
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('Cache Now modal description includes the channel title', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 44444,
        'title' => 'Distinctive Title',
        'url' => 'https://example.com/y.mp4',
    ]);

    $description = VodResource::cacheNowDescription($channel);

    expect($description)->toContain('Distinctive Title');
});

it('canCacheNow returns false when enable_cache is off', function () {
    setEnableCacheForChannelTest(false);

    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 55555,
        'url' => 'https://example.com/z.mp4',
    ]);

    expect(VodResource::canCacheNow($channel))->toBeFalse();
});

it('canCacheNow returns true for a VOD channel with a resolvable URL when the feature is on', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 66666,
        'url' => 'https://example.com/ok.mp4',
    ]);

    expect(VodResource::canCacheNow($channel))->toBeTrue();
});

it('Channel::isCached returns false when no Completed row matches', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 77777,
        'url' => 'https://example.com/uncached.mp4',
    ]);

    expect($channel->isCached())->toBeFalse();
});

it('Channel::isCached returns true when a Completed row matches the playlist + fingerprint', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 88888,
        'tvdb_id' => null,
        'url' => 'https://example.com/cached.mp4',
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '88888',
        'tvdb_id' => null,
    ]);

    $channel->refresh();

    expect($channel->isCached())->toBeTrue();
});

it('Channel::isCached returns false when an existing row is not in Completed status', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 99999,
        'url' => 'https://example.com/pending.mp4',
    ]);

    CachedContentFile::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '99999',
        'status' => CachedContentFileStatus::Downloading,
    ]);

    expect($channel->isCached())->toBeFalse();
});

// --- PR #1524 review item 6: "Cache Now" on an already-queued item must
// not surface the red "Could not queue cache" failure ---

it('Cache Now on a VOD channel with a Pending row surfaces "Already queued for caching" (not a red failure)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 10_100,
        'url' => 'https://example.com/queued.mp4',
    ]);

    CachedContentFile::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '10100',
        'status' => CachedContentFileStatus::Pending,
    ]);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Already queued for caching');

    // The dispatcher must NOT have queued another job - the row already
    // exists, so re-dispatching is a no-op.
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('Cache Now on a VOD channel with a Downloading row surfaces "Already queued for caching"', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 10_200,
        'url' => 'https://example.com/downloading.mp4',
    ]);

    CachedContentFile::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '10200',
        'status' => CachedContentFileStatus::Downloading,
    ]);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Already queued for caching');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});
// --- PR #1524 review efficiency fix: Channel::isCached() must be single-query + no relation load ---

/**
 * Count queries against `cached_content_files` only. Other tables
 * (e.g. `playlists` via the nested subquery path, `episodes.series`)
 * are not the hot-path concern this PR review flagged - this helper
 * pins down the existence lookup the memoization must eliminate.
 */
function countCachedContentFileQueries(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains(strtolower($entry['query']), 'from "cached_content_files"'))
        ->count();
}

it('Channel::isCached() runs one cached_content_files query and does not load the playlist relation on repeated calls', function () {
    // Per review: isCached() is called twice per row on the VOD table
    // (getStateUsing + tooltip closures), so a 50-row page would do
    // 100 queries + 100 relation loads without memoization + int scope.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/memo-channel.mp4',
    ]);
    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    DB::enableQueryLog();
    $first = $channel->isCached();
    $queriesAfterFirst = countCachedContentFileQueries();
    $relationLoadedAfterFirst = $channel->relationLoaded('playlist');

    $second = $channel->isCached();
    $queriesAfterSecond = countCachedContentFileQueries();
    $relationLoadedAfterSecond = $channel->relationLoaded('playlist');
    DB::disableQueryLog();

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        // Exactly one cached_content_files query for the first call.
        ->and($queriesAfterFirst)->toBe(1)
        // Second call must not issue any new cached_content_files query.
        ->and($queriesAfterSecond)->toBe(1)
        // The playlist relation must NOT be loaded by isCached() - the
        // scope resolves playlists.user_id via a nested subquery.
        ->and($relationLoadedAfterFirst)->toBeFalse()
        ->and($relationLoadedAfterSecond)->toBeFalse();
});

it('Channel::isCached() memoizes a false result without issuing a second cached_content_files query', function () {
    // The false branch (no Completed row, no playlist_id) must also
    // memoize so repeated false calls do not re-query.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 551,
        'url' => 'https://example.com/no-cache.mp4',
    ]);
    // No CachedContentFile row at all.

    DB::enableQueryLog();
    $first = $channel->isCached();
    $queriesAfterFirst = countCachedContentFileQueries();
    $second = $channel->isCached();
    $queriesAfterSecond = countCachedContentFileQueries();
    DB::disableQueryLog();

    expect($first)->toBeFalse()
        ->and($second)->toBeFalse()
        ->and($queriesAfterFirst)->toBe(1)
        ->and($queriesAfterSecond)->toBe(1)
        ->and($channel->relationLoaded('playlist'))->toBeFalse();
});

it('Channel::isCached() does not load the playlist relation even when playlist_id is null', function () {
    // Short-circuit branch: no playlist_id means the answer is false
    // without touching the DB or the relation.
    $channel = new Channel;
    $channel->playlist_id = null;

    expect($channel->isCached())->toBeFalse()
        ->and($channel->relationLoaded('playlist'))->toBeFalse();
});
