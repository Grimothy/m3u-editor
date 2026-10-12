<?php

use App\Filament\Resources\MergedPlaylists\Pages\EditMergedPlaylist;
use App\Filament\Resources\MergedPlaylists\RelationManagers\PlaylistsRelationManager;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->provider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $this->local = Playlist::factory()->for($this->user)->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'local',
        'playlist_id' => $this->local->id,
    ]);
    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
});

function matchMovie(Playlist $provider, Channel $providerChannel, Channel $localChannel, MediaServerIntegration $integration): void
{
    MediaSourceMatch::create([
        'playlist_id' => $provider->id,
        'media_server_integration_id' => $integration->id,
        'channel_id' => $providerChannel->id,
        'media_channel_id' => $localChannel->id,
        'match_key' => 'tmdb:'.$localChannel->id,
    ]);
}

it('keeps every local movie by default, even ones the provider already carries', function () {
    $this->merged->playlists()->attach([$this->provider->id, $this->local->id]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true]);
    $matchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $matchedLocal, $this->integration);

    expect($this->merged->channels()->count())->toBe(3);
});

it('hides local movies a provider in the merge already serves when only_unmatched is on', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true]);
    $matchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    $unmatchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $matchedLocal, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->sort()->values()->all())
        ->toBe(collect([$providerMovie->id, $unmatchedLocal->id])->sort()->values()->all());
});

it('only counts matches made by playlists attached to the same merge', function () {
    $otherProvider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $otherProviderMovie = Channel::factory()->create(['playlist_id' => $otherProvider->id, 'is_vod' => true]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($otherProvider, $otherProviderMovie, $local, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->all())->toBe([$local->id]);
});

it('picks the local movie back up once the match is gone', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $local, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->all())->toBe([$providerMovie->id]);

    MediaSourceMatch::query()->delete();

    expect($this->merged->channels()->count())->toBe(2);
});

it('drops a local movie from the merge when the media server removes it', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);

    expect($this->merged->channels()->count())->toBe(1);

    $local->delete();

    expect($this->merged->channels()->count())->toBe(0);
});

it('hides a whole local series once any of its episodes is provided by the merge', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id]);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);

    $matchedSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $matchedSeason = Season::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id]);
    $matchedEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id, 'season_id' => $matchedSeason->id]);
    $extraEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id, 'season_id' => $matchedSeason->id]);

    $unmatchedSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $unmatchedEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $unmatchedSeries->id]);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $matchedEpisode->id,
        'match_key' => 'tmdb:1:s1e1',
    ]);

    expect($this->merged->series()->pluck('series.id')->sort()->values()->all())
        ->toBe(collect([$providerSeries->id, $unmatchedSeries->id])->sort()->values()->all())
        ->and($this->merged->seasons()->where('seasons.id', $matchedSeason->id)->exists())->toBeFalse()
        ->and($this->merged->episodes()->pluck('episodes.id')->all())
        ->not->toContain($matchedEpisode->id)
        ->not->toContain($extraEpisode->id)
        ->toContain($unmatchedEpisode->id);
});

it('creates a merged playlist with the provider and each media source flagged only_unmatched', function () {
    $merged = app(MediaSourceMatchService::class)->createMergedWithLocalMedia($this->provider);

    expect($merged)->not->toBeNull()
        ->and($merged->user_id)->toBe($this->user->id)
        ->and($merged->uuid)->not->toBeEmpty();

    $sources = $merged->playlists()->get()->keyBy('id');
    expect($sources->keys()->sort()->values()->all())
        ->toBe(collect([$this->provider->id, $this->local->id])->sort()->values()->all())
        ->and($sources[$this->provider->id]->pivot->only_unmatched)->toBeFalse()
        ->and($sources[$this->local->id]->pivot->only_unmatched)->toBeTrue();
});

it('creates nothing when the owner has no eligible media integration', function () {
    $this->integration->update(['enabled' => false]);

    expect(app(MediaSourceMatchService::class)->createMergedWithLocalMedia($this->provider))->toBeNull()
        ->and(MergedPlaylist::where('name', 'like', '%Local Media%')->exists())->toBeFalse();
});

it('leaves the provider playlist and its auths untouched', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create();
    $auth->assignTo($this->provider);

    app(MediaSourceMatchService::class)->createMergedWithLocalMedia($this->provider);

    expect($this->provider->fresh()->playlistAuths()->pluck('playlist_auths.id')->all())->toBe([$auth->id]);
});

it('offers the action only on provider playlists with prefer-local on and creates the merge when run', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->provider->getRouteKey()])
        ->assertActionVisible('create-merged-with-local-media')
        ->callAction('create-merged-with-local-media')
        ->assertNotified(__('Merged playlist created'));

    expect(MergedPlaylist::where('name', "{$this->provider->name} + Local Media")->exists())->toBeTrue();

    $this->provider->update(['prefer_media_server_sources' => false]);

    Livewire::test(EditPlaylist::class, ['record' => $this->provider->getRouteKey()])
        ->assertActionHidden('create-merged-with-local-media');
});

it('saves the only_unmatched toggle from the merged playlist source editor', function () {
    $this->actingAs($this->user);
    $this->merged->playlists()->attach($this->local->id);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->callAction(TestAction::make('contentTypes')->table($this->local), data: [
            'include_live' => true,
            'include_vod' => true,
            'include_series' => true,
            'only_unmatched' => true,
        ]);

    expect($this->merged->playlists()->find($this->local->id)->pivot->only_unmatched)->toBeTrue();
});
