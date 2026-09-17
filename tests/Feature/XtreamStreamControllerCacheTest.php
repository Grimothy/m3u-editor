<?php

use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Per project memory: Bus::fake() (NOT Queue::fake) catches dispatch()
    // calls from model event listeners like Playlist::factory -> SyncPipelineService.
    Bus::fake();
    // The cache-hit gate may consult the Xtream redirect path; prevent accidental
    // outbound HTTP during tests.
    Http::preventStrayRequests();
});

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 * Mockery avoids the missing-properties error you'd get from `->save()` when other
 * required fields aren't set - mirrors the pattern in ProviderRequestDelayTest.
 */
function setEnableCache(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

it('redirects to the cache stream when enable_cache is on and a Completed row matches (VOD)', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '550',
        'tvdb_id' => null,
        'enabled' => true,
    ]);

    $cached = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'tvdb_id' => null,
    ]);

    $response = $this->get(
        "/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirectContains("/cached-content/{$user->name}/{$playlist->uuid}/{$cached->uuid}.mp4");
});

it('falls through to the existing redirect when enable_cache is on but no Completed row matches (VOD)', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '999',
        'enabled' => true,
    ]);

    // No CachedContentFile for this channel.

    $response = $this->get(
        "/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4"
    );

    // Falls through to the existing PlaylistUrlService path - a redirect to some
    // external URL. Just assert it IS a redirect and the path is NOT the cache route.
    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('does NOT serve cache when enable_cache is off, even with a Completed row present (PR #1500 regression)', function () {
    setEnableCache(false);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    // Completed row exists - but enable_cache is OFF. PR #1500's gap was gating
    // only lazy-dispatch; PR C gates serve too so disabling stops serving.
    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    $response = $this->get(
        "/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('does not dispatch DownloadCachedContentFile when enable_cache is off', function () {
    setEnableCache(false);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    $this->get("/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4");

    // Lazy-dispatch is intentionally off in PR C; this asserts the gate doesn't
    // accidentally fire a dispatch on cache miss when enable_cache is off.
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('redirects to the cache stream when enable_cache is on and a Completed episode row matches', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->create(['playlist_id' => $playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '60625',
        'season' => 1,
        'episode_num' => 5,
    ]);

    $cached = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'tvdb_id' => null,
        'season_number' => 1,
        'episode_number' => 5,
        'quality' => null,
    ]);

    $response = $this->get(
        "/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4"
    );

    $response->assertRedirectContains("/cached-content/{$user->name}/{$playlist->uuid}/{$cached->uuid}.mp4");
});

it('does NOT serve cache for episodes when enable_cache is off (PR #1500 regression)', function () {
    setEnableCache(false);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->create(['playlist_id' => $playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '60625',
        'season' => 1,
        'episode_num' => 5,
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'season_number' => 1,
        'episode_number' => 5,
    ]);

    $response = $this->get(
        "/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('falls through to the existing redirect when no Completed row matches and enable_cache is on (episode)', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->create(['playlist_id' => $playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '60625',
        'season' => 1,
        'episode_num' => 5,
    ]);

    // No cache row for this episode.

    $response = $this->get(
        "/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});
