<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setEnableCacheForEpisodeTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    setEnableCacheForEpisodeTest(true);
    Bus::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Build a Series + Season + Episode row owned by $user with the given
 * season/episode numbers. Returns the Episode model.
 *
 * The Episode factory defaults to Playlist::factory() for playlist_id;
 * we explicitly stamp the parent playlist + user so Episode::isCached()
 * can resolve the cache row by playlist_id (a Playlist created via the
 * factory default would have a fresh uuid and never match the Cached
 * ContentFile row's playlist_id).
 */
function makeCacheTestEpisode(User $user, Playlist $playlist, int $seasonNum, int $episodeNum, int $tmdbId, string $url): Episode
{
    $series = Series::factory()->for($user)->for($playlist)->create(['tmdb_id' => $tmdbId]);
    $season = Season::factory()->for($series)->create(['season_number' => $seasonNum]);
    $episode = Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'season_id' => $season->id,
        'season' => $seasonNum,
        'episode_num' => $episodeNum,
        'url' => $url,
    ]);

    return $episode;
}

it('renders the EpisodesRelationManager for the Series resource', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create();

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ListSeries::class,
    ])->assertOk();
});

it('shows the Cache Now row action on an Episode with a resolvable URL', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 1, 1, 1234, 'https://example.com/s1e1.mp4');

    $series = $episode->series;

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ListSeries::class,
    ])
        ->assertTableActionVisible('cache_now', $episode);
});

it('hides the Cache Now row action when enable_cache is off', function () {
    setEnableCacheForEpisodeTest(false);

    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 1, 2, 2345, 'https://example.com/s1e2.mp4');

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $episode->series,
        'pageClass' => ListSeries::class,
    ])
        ->assertTableActionHidden('cache_now', $episode);
});

it('hides the Cache Now row action when the episode has no resolvable URL', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 1, 3, 3456, '');

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $episode->series,
        'pageClass' => ListSeries::class,
    ])
        ->assertTableActionHidden('cache_now', $episode);
});

it('clicking Cache Now dispatches DownloadCachedContentFile for the episode', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 2, 5, 4567, 'https://example.com/s2e5.mp4');

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $episode->series,
        'pageClass' => ListSeries::class,
    ])
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Cache download queued');

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('Cache Now on an episode that already has a Completed cached file surfaces the "Already cached" notification', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create(['tmdb_id' => 5678]);
    $season = Season::factory()->for($series)->create(['season_number' => 3]);
    $episode = Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'season_id' => $season->id,
        'season' => 3,
        'episode_num' => 9,
        'url' => 'https://example.com/s3e9.mp4',
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '5678',
        'season_number' => 3,
        'episode_number' => 9,
    ]);

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ListSeries::class,
    ])
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Already cached');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('EpisodesRelationManager::canCacheNow returns false when enable_cache is off', function () {
    setEnableCacheForEpisodeTest(false);

    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 4, 4, 6789, 'https://example.com/s4e4.mp4');

    expect(EpisodesRelationManager::canCacheNow($episode))->toBeFalse();
});

it('EpisodesRelationManager::canCacheNow returns true for an episode with a resolvable URL', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 4, 5, 7890, 'https://example.com/s4e5.mp4');

    expect(EpisodesRelationManager::canCacheNow($episode))->toBeTrue();
});

it('cacheNowDescription falls back to "Episode :seasonx:episode" when the title is missing', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create(['tmdb_id' => 8901]);
    $season = Season::factory()->for($series)->create(['season_number' => 5]);
    $episode = Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'season_id' => $season->id,
        'season' => 5,
        'episode_num' => 11,
        'title' => null,
        'url' => 'https://example.com/s5e11.mp4',
    ]);

    $description = EpisodesRelationManager::cacheNowDescription($episode);

    expect($description)->toContain('Episode 5x11');
});

it('Episode::isCached returns false when no Completed row matches', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $episode = makeCacheTestEpisode($this->user, $playlist, 6, 6, 9012, 'https://example.com/s6e6.mp4');

    expect($episode->isCached())->toBeFalse();
});

it('Episode::isCached returns true when a Completed row matches the playlist + season + episode', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create(['tmdb_id' => 11_111]);
    $season = Season::factory()->for($series)->create(['season_number' => 7]);
    $episode = Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'season_id' => $season->id,
        'season' => 7,
        'episode_num' => 13,
        'url' => 'https://example.com/s7e13.mp4',
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '11111',
        'season_number' => 7,
        'episode_number' => 13,
    ]);

    expect($episode->isCached())->toBeTrue();
});

it('Episode::isCached returns false when an existing row is not in Completed status', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create(['tmdb_id' => 13_131]);
    $season = Season::factory()->for($series)->create(['season_number' => 8]);
    $episode = Episode::factory()->for($series)->create([
        'season_id' => $season->id,
        'season' => 8,
        'episode_num' => 14,
        'url' => 'https://example.com/s8e14.mp4',
    ]);

    CachedContentFile::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '13131',
        'season_number' => 8,
        'episode_number' => 14,
        'status' => CachedContentFileStatus::Failed,
    ]);

    expect($episode->isCached())->toBeFalse();
});

// --- PR #1524 review item 6: "Cache Now" on an already-queued item must
// not surface the red "Could not queue cache" failure ---

it('Cache Now on an episode with a Pending row surfaces "Already queued for caching" (not a red failure)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create(['tmdb_id' => 14_141]);
    $season = Season::factory()->for($series)->create(['season_number' => 9]);
    $episode = Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'season_id' => $season->id,
        'season' => 9,
        'episode_num' => 1,
        'url' => 'https://example.com/s9e1.mp4',
    ]);

    CachedContentFile::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '14141',
        'season_number' => 9,
        'episode_number' => 1,
        'status' => CachedContentFileStatus::Pending,
    ]);

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ListSeries::class,
    ])
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Already queued for caching');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('Cache Now on an episode with a Downloading row surfaces "Already queued for caching"', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($playlist)->create(['tmdb_id' => 14_242]);
    $season = Season::factory()->for($series)->create(['season_number' => 10]);
    $episode = Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'season_id' => $season->id,
        'season' => 10,
        'episode_num' => 2,
        'url' => 'https://example.com/s10e2.mp4',
    ]);

    CachedContentFile::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '14242',
        'season_number' => 10,
        'episode_number' => 2,
        'status' => CachedContentFileStatus::Downloading,
    ]);

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ListSeries::class,
    ])
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Already queued for caching');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});
