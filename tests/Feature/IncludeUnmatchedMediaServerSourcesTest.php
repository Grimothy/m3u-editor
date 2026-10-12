<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaSourceOutputService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->provider = Playlist::factory()->for($this->user)->create([
        'prefer_media_server_sources' => true,
        'include_unmatched_media_server_sources' => true,
    ]);
    $this->local = Playlist::factory()->for($this->user)->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'local',
        'playlist_id' => $this->local->id,
    ]);
    $this->providerGroup = Group::factory()->for($this->provider)->for($this->user)->create(['type' => 'vod', 'name' => 'Provider Movies']);
    $this->localGroup = Group::factory()->for($this->local)->for($this->user)->create(['type' => 'vod', 'name' => 'Local Movies']);
});

function xtreamUrl(Playlist $playlist, string $action, array $params = []): string
{
    return '/player_api.php?'.http_build_query([
        'username' => $playlist->user->name,
        'password' => $playlist->uuid,
        'action' => $action,
    ] + $params);
}

function movie(Playlist $playlist, Group $group, string $title): Channel
{
    return Channel::factory()->for($playlist->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => $title,
        'name' => $title,
        'url' => 'http://example.com/movie/'.str($title)->slug().'.mkv',
        'container_extension' => 'mkv',
        'last_metadata_fetch' => now(),
    ]);
}

function matchMovieTo(Playlist $provider, Channel $providerMovie, Channel $localMovie, MediaServerIntegration $integration): void
{
    MediaSourceMatch::create([
        'playlist_id' => $provider->id,
        'media_server_integration_id' => $integration->id,
        'channel_id' => $providerMovie->id,
        'media_channel_id' => $localMovie->id,
        'match_key' => 'tmdb:'.$localMovie->id,
    ]);
}

/**
 * @return array{series: Series, episode: Episode}
 */
function seriesWithEpisode(Playlist $playlist, string $name): array
{
    $series = Series::factory()->for($playlist)->for($playlist->user)->create(['enabled' => true, 'name' => $name]);
    $season = Season::factory()->for($series)->for($playlist)->for($playlist->user)->create();
    $episode = Episode::factory()->for($series)->for($season)->for($playlist)->for($playlist->user)->create(['enabled' => true]);

    return ['series' => $series, 'episode' => $episode];
}

it('lists provider movies and unmatched local movies, never a matched local copy', function () {
    $providerMovie = movie($this->provider, $this->providerGroup, 'Provider Only');
    $matchedProvider = movie($this->provider, $this->providerGroup, 'Both Places');
    $matchedLocal = movie($this->local, $this->localGroup, 'Both Places');
    $localOnly = movie($this->local, $this->localGroup, 'Local Only');
    matchMovieTo($this->provider, $matchedProvider, $matchedLocal, $this->integration);

    $ids = collect($this->getJson(xtreamUrl($this->provider, 'get_vod_streams'))->assertOk()->json())
        ->pluck('stream_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($ids)->toBe(collect([$providerMovie->id, $matchedProvider->id, $localOnly->id])->sort()->values()->all());
});

it('does not list local movies while the toggle is off or prefer media server sources is off', function () {
    movie($this->provider, $this->providerGroup, 'Provider Only');
    movie($this->local, $this->localGroup, 'Local Only');

    $this->provider->update(['include_unmatched_media_server_sources' => false]);
    expect($this->getJson(xtreamUrl($this->provider->fresh(), 'get_vod_streams'))->assertOk()->json())->toHaveCount(1);

    $this->provider->update(['include_unmatched_media_server_sources' => true, 'prefer_media_server_sources' => false]);
    expect($this->getJson(xtreamUrl($this->provider->fresh(), 'get_vod_streams'))->assertOk()->json())->toHaveCount(1);
});

it('offers the local group as a category and filters by it', function () {
    movie($this->provider, $this->providerGroup, 'Provider Only');
    $localOnly = movie($this->local, $this->localGroup, 'Local Only');

    $names = collect($this->getJson(xtreamUrl($this->provider, 'get_vod_categories'))->assertOk()->json())->pluck('category_name');
    expect($names->all())->toContain('Provider Movies', 'Local Movies');

    $filtered = $this->getJson(xtreamUrl($this->provider, 'get_vod_streams', ['category_id' => $this->localGroup->id]))->assertOk()->json();
    expect(collect($filtered)->pluck('stream_id')->map(fn ($id) => (int) $id)->all())->toBe([$localOnly->id]);
});

it('does not show a local group as a category once every title in it is matched', function () {
    $providerMovie = movie($this->provider, $this->providerGroup, 'Both Places');
    $localMovie = movie($this->local, $this->localGroup, 'Both Places');
    matchMovieTo($this->provider, $providerMovie, $localMovie, $this->integration);

    $names = collect($this->getJson(xtreamUrl($this->provider, 'get_vod_categories'))->assertOk()->json())->pluck('category_name');

    expect($names->all())->toContain('Provider Movies')->not->toContain('Local Movies');
});

it('opens and plays an unmatched local movie through the provider login', function () {
    $localOnly = movie($this->local, $this->localGroup, 'Local Only');
    $matchedProvider = movie($this->provider, $this->providerGroup, 'Both Places');
    $matchedLocal = movie($this->local, $this->localGroup, 'Both Places');
    matchMovieTo($this->provider, $matchedProvider, $matchedLocal, $this->integration);

    $this->getJson(xtreamUrl($this->provider, 'get_vod_info', ['vod_id' => $localOnly->id]))
        ->assertOk()
        ->assertJsonPath('movie_data.stream_id', $localOnly->id);

    // Plays from the media server's own URL, not through the provider's settings.
    $this->provider->update(['enable_proxy' => true]);
    $this->get('/movie/'.rawurlencode($this->user->name).'/'.$this->provider->uuid.'/'.$localOnly->id.'.mkv')
        ->assertRedirect($localOnly->url);

    // The matched local copy is not a listed item, so it cannot be opened directly.
    $this->getJson(xtreamUrl($this->provider, 'get_vod_info', ['vod_id' => $matchedLocal->id]))
        ->assertJsonMissingPath('movie_data');
});

it('lists, opens and plays an unmatched local series and hides one with a matched episode', function () {
    ['series' => $localOnlySeries, 'episode' => $localOnlyEpisode] = seriesWithEpisode($this->local, 'Local Only Show');
    ['series' => $sharedLocalSeries, 'episode' => $sharedLocalEpisode] = seriesWithEpisode($this->local, 'Shared Show');
    ['episode' => $sharedProviderEpisode] = seriesWithEpisode($this->provider, 'Shared Show');
    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $sharedProviderEpisode->id,
        'media_episode_id' => $sharedLocalEpisode->id,
        'match_key' => 'tmdb:shared',
    ]);

    $listed = collect($this->getJson(xtreamUrl($this->provider, 'get_series'))->assertOk()->json())
        ->pluck('series_id')->map(fn ($id) => (int) $id);

    expect($listed->all())->toContain($localOnlySeries->id)->not->toContain($sharedLocalSeries->id);

    $this->getJson(xtreamUrl($this->provider, 'get_series_info', ['series_id' => $localOnlySeries->id]))
        ->assertOk()
        ->assertJsonPath('info.name', 'Local Only Show');

    $this->get('/series/'.rawurlencode($this->user->name).'/'.$this->provider->uuid.'/'.$localOnlyEpisode->id.'.mkv')
        ->assertRedirect();
});

it('lets an alias of the provider inherit the extra items unless it filters groups', function () {
    $localOnly = movie($this->local, $this->localGroup, 'Local Only');
    $alias = PlaylistAlias::create([
        'playlist_id' => $this->provider->id,
        'user_id' => $this->user->id,
        'name' => 'Alias',
        'uuid' => Str::uuid()->toString(),
    ]);

    expect(MediaSourceOutputService::channels($alias)->pluck('channels.id')->all())->toBe([$localOnly->id]);

    $alias->update(['group_filter' => ['selected_vod_groups' => ['Provider Movies']]]);
    expect(MediaSourceOutputService::channels($alias->fresh())->pluck('channels.id')->all())->toBe([]);
});

it('adds unmatched local movies to the M3U output when the playlist includes VOD', function () {
    $this->provider->update(['include_vod_in_m3u' => true]);
    movie($this->provider, $this->providerGroup, 'Provider Only');
    $matchedProvider = movie($this->provider, $this->providerGroup, 'Both Places');
    $matchedLocal = movie($this->local, $this->localGroup, 'Both Places');
    movie($this->local, $this->localGroup, 'Local Only');
    matchMovieTo($this->provider, $matchedProvider, $matchedLocal, $this->integration);

    $m3u = $this->get(route('playlist.generate', ['uuid' => $this->provider->uuid]))->assertOk()->streamedContent();

    expect(substr_count($m3u, '#EXTINF'))->toBe(3)
        ->and($m3u)->toContain('Local Only');
});

it('offers the local series category and filters the series list by it', function () {
    $providerCategory = Category::factory()->for($this->provider)->for($this->user)->create(['name' => 'Provider Shows']);
    $localCategory = Category::factory()->for($this->local)->for($this->user)->create(['name' => 'Local Shows']);
    ['series' => $providerSeries] = seriesWithEpisode($this->provider, 'Provider Show');
    ['series' => $localSeries] = seriesWithEpisode($this->local, 'Local Only Show');
    $providerSeries->update(['category_id' => $providerCategory->id]);
    $localSeries->update(['category_id' => $localCategory->id]);

    $names = collect($this->getJson(xtreamUrl($this->provider, 'get_series_categories'))->assertOk()->json())->pluck('category_name');
    expect($names->all())->toContain('Provider Shows', 'Local Shows');

    $filtered = $this->getJson(xtreamUrl($this->provider, 'get_series', ['category_id' => $localCategory->id]))->assertOk()->json();
    expect(collect($filtered)->pluck('series_id')->map(fn ($id) => (int) $id)->all())->toBe([$localSeries->id]);
});

it('adds unmatched local series episodes to the M3U output when the playlist includes series', function () {
    $this->provider->update(['include_series_in_m3u' => true]);
    ['episode' => $providerEpisode] = seriesWithEpisode($this->provider, 'Provider Show');
    ['episode' => $localEpisode] = seriesWithEpisode($this->local, 'Local Only Show');

    $m3u = $this->get(route('playlist.generate', ['uuid' => $this->provider->uuid]))->assertOk()->streamedContent();

    expect($m3u)->toContain('/series/')
        ->and($m3u)->toContain((string) $localEpisode->id)
        ->and($m3u)->toContain((string) $providerEpisode->id);
});
