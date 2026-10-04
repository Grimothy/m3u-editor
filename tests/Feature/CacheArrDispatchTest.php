<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\MonitorArrSearch;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Services\CachedContentRetentionService;
use App\Services\MediaSourceMatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Method routing and the arr dispatch (manual Cache Now)
|--------------------------------------------------------------------------
|
| dispatch() is a router: with `cache_primary_method = provider` nothing
| changes (case 1); with `arr` and the playlist's "Prefer media server
| sources" on, the title goes to Radarr (Channel) / Sonarr (Series or
| Episode's series) first, and the provider only runs when arr can't
| deliver. Rows dedup across the user's playlists by external ID.
*/

uses(RefreshDatabase::class);

/**
 * Bind GeneralSettings with the requested cache method / integration ids.
 */
function cadSettings(bool $enableCache = true, string $method = 'provider', ?int $radarrId = null, ?int $sonarrId = null): GeneralSettings
{
    $settings = new GeneralSettings;
    $settings->enable_cache = $enableCache;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->cache_primary_method = $method;
    $settings->cache_radarr_integration_id = $radarrId;
    $settings->cache_sonarr_integration_id = $sonarrId;
    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

/**
 * A VOD channel with a cacheable URL and a tmdb_id on $playlist.
 */
function cadChannel(Playlist $playlist, array $overrides = []): Channel
{
    return Channel::factory()->create(array_merge([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => '550',
        'url' => 'https://provider.example.com/movie/fight-club.mkv',
    ], $overrides));
}

/**
 * A playlist (media-server preference on, owned by $user) + DynamicGroup
 * whose dynamic_groups_config holds the matching rule. Cache rule keys go
 * in $ruleOverrides.
 *
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: Playlist, 1: DynamicGroup}
 */
function cadPlaylistWithGroup(User $user, string $type = 'vod', array $ruleOverrides = []): array
{
    $rule = array_merge([
        'enabled' => true,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => [],
    ], $ruleOverrides);

    $playlist = Playlist::factory()->for($user)->create(['prefer_media_server_sources' => true]);
    $playlist->update(['dynamic_groups_config' => [$rule]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
    ]);

    return [$playlist, $group];
}

/**
 * Attach a Channel or Series member with an explicit TMDB-rank position.
 */
function cadAttachMember(DynamicGroup $group, Channel|Series $item, int $position): void
{
    $relation = $item instanceof Channel ? 'channels' : 'series';
    $group->{$relation}()->attach($item->id, ['position' => $position]);
}

/**
 * A VOD group member with a cacheable URL; tmdb_id defaults to the id the
 * Radarr fakes return.
 */
function cadGroupChannel(Playlist $playlist, int $n, array $overrides = []): Channel
{
    return cadChannel($playlist, array_merge([
        'url' => "https://provider.example.com/movie/group-{$n}.mkv",
    ], $overrides));
}

/**
 * Give $channel an eligible media-server match: a media playlist with an
 * enabled emby integration and an enabled media channel sharing the
 * provider channel's tmdb_id (mirrors dgacMatchToMedia).
 */
function cadMatchToMedia(Playlist $provider, Channel $channel): Channel
{
    $media = Playlist::factory()->for($provider->user)->create();
    $mediaChannel = Channel::factory()->for($media)->for($provider->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => $channel->tmdb_id,
        'url' => 'https://media.example.com/local/'.$channel->id.'.mkv',
    ]);
    MediaServerIntegration::factory()->for($provider->user)->create([
        'type' => 'emby',
        'enabled' => true,
        'playlist_id' => $media->id,
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($provider->refresh());

    return $mediaChannel;
}

/**
 * A series with tvdb/tmdb ids and cacheable episodes on $playlist.
 */
function cadSeriesWithEpisodes(Playlist $playlist, array $seriesOverrides = [], int $episodeCount = 1): Series
{
    $series = Series::factory()->create(array_merge([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => 1399,
        'tvdb_id' => 81189,
    ], $seriesOverrides));

    $owner = ['user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'series_id' => $series->id];
    foreach (range(1, $episodeCount) as $n) {
        Episode::factory()->create($owner + [
            'season' => 1,
            'episode_num' => $n,
            'url' => "https://provider.example.com/show/s1e{$n}.mkv",
        ]);
    }

    return $series;
}

/**
 * Radarr fakes for a successful add: not in library, found by tmdbId, added as id 77.
 *
 * @return array<string, mixed>
 */
function cadRadarrSuccessFakes(): array
{
    return [
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
        ], 200),
        '*/api/v3/movie' => Http::response(['id' => 77], 201),
    ];
}

/**
 * Sonarr fakes for a successful add: not in library (lookup result carries
 * no Sonarr id), found by tvdbId, added as id 9.
 *
 * @return array<string, mixed>
 */
function cadSonarrSuccessFakes(): array
{
    return [
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tmdbId' => 1399,
                'tvdbId' => 81189,
                'title' => 'Game of Thrones',
                'titleSlug' => 'game-of-thrones',
                'seasons' => [
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 9], 201),
    ];
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
    ]);
    $this->sonarr = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);

    cadSettings();
});

// ── 1. The provider default is unchanged ──────────────────────────────────

it('queues through the provider when the cache method is provider', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::Queued);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('provider')
        ->and($row->cacheable_id)->toBe($channel->id)
        ->and($row->status)->toBe(CachedContentFileStatus::Pending);

    Bus::assertDispatched(DownloadCachedContentFile::class);
    Http::assertNothingSent();
});

// ── 2. Arr movie success ──────────────────────────────────────────────────

it('sends a movie to radarr and records an arr row when the cache method is arr', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrRequested);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('radarr')
        ->and($row->status)->toBe(CachedContentFileStatus::Requested)
        ->and($row->arr_integration_id)->toBe($this->radarr->id)
        ->and($row->arr_library_id)->toBe(77)
        ->and($row->media_request_id)->not->toBeNull()
        ->and($row->managed_by)->toBeNull()
        ->and(CachedContentFile::where('source', 'provider')->count())->toBe(0);

    Bus::assertDispatched(MonitorArrSearch::class);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('routes to radarr when the channel\'s playlist was eager-loaded without the routing columns', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    // Mirrors VodResource's table eager load: a narrow playlist select
    // without prefer_media_server_sources (and here without user_id too).
    $channel = Channel::query()
        ->with(['playlist' => fn ($q) => $q->select('id', 'name', 'uuid')])
        ->findOrFail($channel->id);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrRequested)
        ->and(CachedContentFile::sole()->source->value)->toBe('radarr')
        ->and(CachedContentFile::sole()->user_id)->toBe($this->user->id);
});

// ── 3. Arr rejects the add → provider fallback ────────────────────────────

it('falls back to the provider when radarr rejects the add', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
        ], 200),
        '*/api/v3/movie' => Http::response([], 400),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrFallbackQueued);

    $arrRow = CachedContentFile::where('source', 'radarr')->sole();
    expect($arrRow->status)->toBe(CachedContentFileStatus::Failed)
        ->and($arrRow->fallback_dispatched_at)->not->toBeNull();

    $providerRow = CachedContentFile::where('source', 'provider')->sole();
    expect($providerRow->status)->toBe(CachedContentFileStatus::Pending);
});

// ── 4. No external id → provider without HTTP ─────────────────────────────

it('queues through the provider without any HTTP when the channel has no tmdb id', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist, ['tmdb_id' => null]);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrFallbackQueued)
        ->and(CachedContentFile::where('source', 'provider')->where('cacheable_id', $channel->id)->exists())->toBeTrue();

    Http::assertNothingSent();
});

// ── 5. Already in Radarr with a file ──────────────────────────────────────

it('reports arr_already_available and keeps no rows when radarr has the file', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([['id' => 5, 'hasFile' => true]], 200),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrAlreadyAvailable)
        ->and(CachedContentFile::count())->toBe(0);
});

// ── 6. In Radarr but no file yet → monitored fallback ─────────────────────

it('falls back to the provider when radarr has the movie without a file', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([['id' => 5, 'hasFile' => false]], 200),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrMonitoredFallback)
        ->and(CachedContentFile::where('source', 'radarr')->count())->toBe(0);

    $providerRow = CachedContentFile::where('source', 'provider')->sole();
    expect($providerRow->status)->toBe(CachedContentFileStatus::Pending);
});

// ── 7. The playlist toggle gates arr routing ──────────────────────────────

it('queues through the provider when the playlist does not prefer media server sources', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => false]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::Queued)
        ->and(CachedContentFile::sole()->source->value)->toBe('provider');

    Http::assertNothingSent();
});

// ── 8. Integration selection ──────────────────────────────────────────────

it('uses the provider when two radarr integrations exist and none is selected', function () {
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies2',
    ]);

    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr');

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::Queued)
        ->and(CachedContentFile::sole()->source->value)->toBe('provider');

    Http::assertNothingSent();
});

it('uses the selected radarr integration when one is configured', function () {
    $second = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies2',
    ]);

    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $second->id);
    Http::fake(cadRadarrSuccessFakes());

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrRequested);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), $second->base_url));
});

// ── 9. Cross-playlist dedup by external id ────────────────────────────────

it('dedups an arr row across playlists of the same user', function () {
    $playlistA = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channelA = cadChannel($playlistA);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    expect(app(CachedContentDispatchService::class)->dispatch($channelA))->toBe(CacheDispatchResult::ArrRequested);

    $playlistB = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channelB = cadChannel($playlistB);

    expect(app(CachedContentDispatchService::class)->dispatch($channelB))->toBe(CacheDispatchResult::AlreadyQueued)
        ->and(CachedContentFile::where('source', 'radarr')->count())->toBe(1);

    $posts = 0;
    Http::assertSent(function (Request $request) use (&$posts): bool {
        if ($request->method() === 'POST') {
            $posts++;
        }

        return true;
    });
    expect($posts)->toBe(1);
});

// ── 10. Manual dispatch adopts a group-managed arr row ────────────────────

it('adopts a group-managed arr row on manual dispatch', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);

    $row = CachedContentFile::factory()
        ->arrMovie($this->radarr, $channel)
        ->dynamicGroupManaged()
        ->create();

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::AlreadyQueued)
        ->and($row->fresh()->managed_by)->toBeNull();

    Http::assertNothingSent();
});

// ── 11. Cache all episodes sends the whole series to Sonarr ───────────────

it('sends the whole series to sonarr when caching all episodes', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $series = cadSeriesWithEpisodes($playlist, episodeCount: 2);
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake(cadSonarrSuccessFakes());

    $counts = app(CachedContentDispatchService::class)->dispatchSeries($series);

    expect($counts[CacheDispatchResult::ArrRequested->value])->toBe(1)
        ->and($counts[CacheDispatchResult::Queued->value])->toBe(0);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('sonarr')
        ->and($row->cacheable_type)->toBe((new Series)->getMorphClass())
        ->and($row->cacheable_id)->toBe($series->id)
        ->and($row->arr_seasons)->toBeNull()
        ->and($row->arr_library_id)->toBe(9)
        ->and(CachedContentFile::where('source', 'provider')->count())->toBe(0);
});

// ── 12. A single episode lands a sonarr row on the series ─────────────────

it('creates a sonarr row on the series with the episode season when dispatching one episode', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $series = cadSeriesWithEpisodes($playlist, episodeCount: 1);
    $episode = $series->episodes()->first();
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake(cadSonarrSuccessFakes());

    $result = app(CachedContentDispatchService::class)->dispatch($episode);

    expect($result)->toBe(CacheDispatchResult::ArrRequested);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('sonarr')
        ->and($row->cacheable_type)->toBe((new Series)->getMorphClass())
        ->and($row->cacheable_id)->toBe($series->id)
        ->and($row->arr_seasons)->toBe([1]);
});

// ── 12b. Sonarr translates tmdb → tvdb (never written back) ───────────────

it('resolves a tvdb id from tmdb via sonarr without writing it back to the series', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $series = cadSeriesWithEpisodes($playlist, ['tmdb_id' => 500, 'tvdb_id' => null], episodeCount: 1);
    $episode = $series->episodes()->first();
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tmdbId' => 500,
                'tvdbId' => 81189,
                'title' => 'Resolved Series',
                'titleSlug' => 'resolved-series',
                'seasons' => [
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 9], 201),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($episode);

    expect($result)->toBe(CacheDispatchResult::ArrRequested);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('sonarr')
        ->and($row->tvdb_id)->toBe('81189')
        ->and($row->tmdb_id)->toBe('500')
        ->and($series->refresh()->tvdb_id)->toBeNull();
});

// ── 12c. No tmdb echo → provider fallback, no series POST ─────────────────

it('falls back to the provider when sonarr cannot translate the tmdb id', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $series = cadSeriesWithEpisodes($playlist, ['tmdb_id' => 500, 'tvdb_id' => null], episodeCount: 1);
    $episode = $series->episodes()->first();
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 81189,
                'title' => 'Older Sonarr Result',
                'titleSlug' => 'older-sonarr-result',
                'seasons' => [
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 9], 201),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($episode);

    expect($result)->toBe(CacheDispatchResult::ArrFallbackQueued)
        ->and(CachedContentFile::where('source', 'sonarr')->count())->toBe(0);

    $providerRow = CachedContentFile::where('source', 'provider')->sole();
    expect($providerRow->cacheable_id)->toBe($episode->id)
        ->and($providerRow->status)->toBe(CachedContentFileStatus::Pending);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v3/series'));
});

// ── 13. Matching is by ID, never by title ─────────────────────────────────

it('does not match a lookup result by title when the tmdb id differs', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $channel = cadChannel($playlist);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 999, 'title' => $channel->name, 'titleSlug' => 'same-title-different-id'],
        ], 200),
        '*/api/v3/movie' => Http::response(['id' => 77], 201),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::ArrFallbackQueued);

    $arrRow = CachedContentFile::where('source', 'radarr')->sole();
    expect($arrRow->status)->toBe(CachedContentFileStatus::Failed)
        ->and($arrRow->last_error_message)->toBe('The requested title was not found.');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v3/movie'));
});

// ── Step 5: dynamic groups ────────────────────────────────────────────────
//
// dispatchForDynamicGroup() routes members through the arr stack when the
// rule's cache_method is 'arr', with the same provenance pivot + retention
// snapshot the provider leg attaches. Arr rows dedup across playlists by
// external id; group release only ever drops provider rows.

it('sends vod members to radarr with provenance when the rule cache method is arr', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($group, cadGroupChannel($playlist, 1, ['tmdb_id' => '550']), 0);
    cadAttachMember($group, cadGroupChannel($playlist, 2, ['tmdb_id' => '551']), 1);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
            ['tmdbId' => 551, 'title' => 'Second Movie', 'titleSlug' => 'second-movie'],
        ], 200),
        '*/api/v3/movie' => Http::response(['id' => 77], 201),
    ]);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::ArrRequested->value])->toBe(2)
        ->and(CachedContentFile::where('source', 'provider')->count())->toBe(0);

    CachedContentFile::where('source', 'radarr')->get()->each(function (CachedContentFile $row) use ($group): void {
        expect($row->managed_by)->toBe(CachedContentManagedBy::DynamicGroup);

        $pivot = DB::table('cached_content_file_dynamic_groups')
            ->where('cached_content_file_id', $row->id)
            ->where('dynamic_group_id', $group->id)
            ->first();
        expect($pivot)->not->toBeNull()
            ->and($pivot->retention)->toBe('in_group');
    });

    Bus::assertDispatched(MonitorArrSearch::class);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('falls back to provider rows with their own pivot when radarr rejects the group add', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($group, cadGroupChannel($playlist, 1), 0);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
        ], 200),
        '*/api/v3/movie' => Http::response([], 400),
    ]);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    // The provider leg counts its own Queued bucket in the group path; the
    // ArrFallbackQueued remap only applies to the manual dispatch router.
    expect($counts[CacheDispatchResult::Queued->value])->toBe(1);

    $arrRow = CachedContentFile::where('source', 'radarr')->sole();
    expect($arrRow->status)->toBe(CachedContentFileStatus::Failed);

    $providerRow = CachedContentFile::where('source', 'provider')->sole();
    expect($providerRow->status)->toBe(CachedContentFileStatus::Pending);

    // Both rows carry the group's provenance snapshot.
    foreach ([$arrRow, $providerRow] as $row) {
        $pivot = DB::table('cached_content_file_dynamic_groups')
            ->where('cached_content_file_id', $row->id)
            ->where('dynamic_group_id', $group->id)
            ->first();
        expect($pivot)->not->toBeNull()
            ->and($pivot->retention)->toBe('in_group');
    }
});

it('keeps the provider for a rule saved without a cache_method, even when the global method is arr', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true]); // no cache_method
    cadAttachMember($group, cadGroupChannel($playlist, 1), 0);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(1);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('provider');

    Http::assertNothingSent();
});

it('sends members to radarr when the rule explicitly follows an arr global setting', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'global']);
    cadAttachMember($group, cadGroupChannel($playlist, 1), 0);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect(CachedContentFile::sole()->source->value)->toBe('radarr');
});

it('lists only rules that explicitly follow the global cache method', function () {
    [$playlist] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'global', 'name' => 'Explicit Global']);
    $playlist->update(['dynamic_groups_config' => [
        ...$playlist->dynamic_groups_config,
        ['enabled' => true, 'type' => 'vod', 'source' => 'popular', 'name' => 'Legacy Rule', 'tmdb_params' => [], 'cache_enabled' => true],
        ['enabled' => true, 'type' => 'vod', 'source' => 'upcoming', 'name' => 'No Cache', 'tmdb_params' => [], 'cache_method' => 'global'],
    ]]);

    expect(DynamicGroup::rulesFollowingGlobalCacheMethod($this->user->id))
        ->toBe([$playlist->name.': Explicit Global']);
});

it('lets a provider rule override an arr global setting', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'provider']);
    cadAttachMember($group, cadGroupChannel($playlist, 1), 0);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(1);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('provider');

    Http::assertNothingSent();
});

it('sends each member series to sonarr with the latest season when the rule cache method is arr', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true, 'cache_method' => 'arr']);
    $series = cadSeriesWithEpisodes($playlist, episodeCount: 1);
    Episode::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season' => 2,
        'episode_num' => 1,
        'url' => 'https://provider.example.com/show/s2e1.mkv',
    ]);
    cadAttachMember($group, $series, 0);
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake(cadSonarrSuccessFakes());

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::ArrRequested->value])->toBe(1)
        ->and(CachedContentFile::where('source', 'provider')->count())->toBe(0);

    $row = CachedContentFile::sole();
    expect($row->source->value)->toBe('sonarr')
        ->and($row->cacheable_type)->toBe((new Series)->getMorphClass())
        ->and($row->cacheable_id)->toBe($series->id)
        ->and($row->arr_seasons)->toBe([2])
        ->and($row->managed_by)->toBe(CachedContentManagedBy::DynamicGroup);
});

it('pins a never_expire arr row by clearing managed_by and keeping the pivot', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_retention' => 'never_expire',
    ]);
    cadAttachMember($group, cadGroupChannel($playlist, 1), 0);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::ArrRequested->value])->toBe(1);

    $row = CachedContentFile::sole();
    expect($row->managed_by)->toBeNull();

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->first();
    expect($pivot->dynamic_group_id)->toBe($group->id)
        ->and($pivot->retention)->toBe('never_expire');
});

it('stamps dropped_at on the series arr row pivot when the member leaves the group', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true, 'cache_method' => 'arr']);
    $series = cadSeriesWithEpisodes($playlist, episodeCount: 1);
    cadAttachMember($group, $series, 0);
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake(cadSonarrSuccessFakes());

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    $row = CachedContentFile::sole();
    expect(DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->whereNull('dropped_at')
        ->exists())->toBeTrue();

    // The member series leaves the group.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();

    app(CachedContentRetentionService::class)->markDroppedForGroup($group);

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->first();
    expect($pivot->dropped_at)->not->toBeNull();
});

it('skips an arr member with an eligible media match without any rows or HTTP', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    $matched = cadGroupChannel($playlist, 1);
    cadAttachMember($group, $matched, 0);
    cadMatchToMedia($playlist, $matched);
    cadSettings(method: 'arr', radarrId: $this->radarr->id);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::MediaServerAvailable->value])->toBe(1)
        ->and(CachedContentFile::count())->toBe(0)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0);

    Http::assertNothingSent();
});

it('shares one arr row across playlists and keeps it while another group holds it', function () {
    [$playlistA, $groupA] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($groupA, cadGroupChannel($playlistA, 1), 0);

    [$playlistB, $groupB] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($groupB, cadGroupChannel($playlistB, 1), 0); // same tmdb id

    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($groupA);
    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($groupB);

    $row = CachedContentFile::where('source', 'radarr')->sole();
    $pivotGroupIds = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->pluck('dynamic_group_id')
        ->sort()
        ->values();
    expect($pivotGroupIds->all())->toBe([$groupA->id, $groupB->id]);

    $posts = 0;
    Http::assertSent(function (Request $request) use (&$posts): bool {
        if ($request->method() === 'POST') {
            $posts++;
        }

        return true;
    });
    expect($posts)->toBe(1);

    // The title drops from group A: its pivot is stamped, group B's is not
    // — the shared arr row is scoped by identity, and tmdb 550 stays in
    // group B's scope, so release must keep the row.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $groupA->id)->delete();

    app(CachedContentRetentionService::class)->markDroppedForGroup($groupA);
    app(CachedContentRetentionService::class)->markDroppedForGroup($groupB);

    $pivots = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->get();
    expect($pivots->firstWhere('dynamic_group_id', $groupA->id)->dropped_at)->not->toBeNull()
        ->and($pivots->firstWhere('dynamic_group_id', $groupB->id)->dropped_at)->toBeNull();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($row->fresh())->not->toBeNull()
        ->and($row->fresh()->managed_by)->toBe(CachedContentManagedBy::DynamicGroup);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

it('returns only this group\'s members for a shared arr row via arrRowMembersOf', function () {
    [$playlistA, $groupA] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    $channelA = cadGroupChannel($playlistA, 1);
    cadAttachMember($groupA, $channelA, 0);

    [$playlistB, $groupB] = cadPlaylistWithGroup($this->user, 'vod', ['cache_enabled' => true, 'cache_method' => 'arr']);
    $channelB = cadGroupChannel($playlistB, 1); // same tmdb id
    cadAttachMember($groupB, $channelB, 0);

    cadSettings(method: 'arr', radarrId: $this->radarr->id);
    Http::fake(cadRadarrSuccessFakes());

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($groupA);
    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($groupB);

    $row = CachedContentFile::where('source', 'radarr')->sole();

    $membersB = iterator_to_array(app(CachedContentDispatchService::class)->arrRowMembersOf($groupB, $row));
    expect($membersB)->toHaveCount(1)
        ->and($membersB[0]->id)->toBe($channelB->id)
        ->and($membersB[0]->playlist->is($playlistB))->toBeTrue();

    $membersA = iterator_to_array(app(CachedContentDispatchService::class)->arrRowMembersOf($groupA, $row));
    expect($membersA)->toHaveCount(1)
        ->and($membersA[0]->id)->toBe($channelA->id)
        ->and($membersA[0]->playlist->is($playlistA))->toBeTrue();
});

it('shares one sonarr row across playlists by tvdb id and keeps it while another group holds it', function () {
    [$playlistA, $groupA] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($groupA, cadSeriesWithEpisodes($playlistA, episodeCount: 1), 0);

    [$playlistB, $groupB] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($groupB, cadSeriesWithEpisodes($playlistB, episodeCount: 1), 0); // same tvdb id

    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake(cadSonarrSuccessFakes());

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($groupA);
    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($groupB);

    $row = CachedContentFile::where('source', 'sonarr')->sole();
    expect($row->tvdb_id)->toBe('81189')
        ->and($row->tmdb_id)->toBe('1399');

    $pivotGroupIds = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->pluck('dynamic_group_id')
        ->sort()
        ->values();
    expect($pivotGroupIds->all())->toBe([$groupA->id, $groupB->id]);

    $posts = 0;
    Http::assertSent(function (Request $request) use (&$posts): bool {
        if ($request->method() === 'POST') {
            $posts++;
        }

        return true;
    });
    expect($posts)->toBe(1);

    // The series drops from group A: its pivot is stamped, group B's is
    // not — tvdb 81189 stays in group B's scope — so release keeps the row.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $groupA->id)->delete();

    app(CachedContentRetentionService::class)->markDroppedForGroup($groupA);
    app(CachedContentRetentionService::class)->markDroppedForGroup($groupB);

    $pivots = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->get();
    expect($pivots->firstWhere('dynamic_group_id', $groupA->id)->dropped_at)->not->toBeNull()
        ->and($pivots->firstWhere('dynamic_group_id', $groupB->id)->dropped_at)->toBeNull();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($row->fresh())->not->toBeNull()
        ->and($row->fresh()->managed_by)->toBe(CachedContentManagedBy::DynamicGroup);
});

it('keeps a sonarr row in scope when its series member matches only by tmdb id', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($group, cadSeriesWithEpisodes($playlist, ['tmdb_id' => 500, 'tvdb_id' => null], episodeCount: 1), 0);
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tmdbId' => 500,
                'tvdbId' => 81189,
                'title' => 'Resolved Series',
                'titleSlug' => 'resolved-series',
                'seasons' => [
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 9], 201),
    ]);

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    // The row carries the resolved tvdb id plus the series' tmdb id; the
    // member series matches only by tmdb.
    $row = CachedContentFile::where('source', 'sonarr')->sole();
    expect($row->tvdb_id)->toBe('81189')
        ->and($row->tmdb_id)->toBe('500');

    app(CachedContentRetentionService::class)->markDroppedForGroup($group);

    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->first();
    expect($pivot)->not->toBeNull()
        ->and($pivot->dropped_at)->toBeNull();
});

it('returns in-scope episodes for a sonarr row via arrRowMembersOf across tvdb and tmdb matches', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true]);
    $seriesX = cadSeriesWithEpisodes($playlist, episodeCount: 1); // tvdb 81189, tmdb 1399
    Episode::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $seriesX->id,
        'season' => 2,
        'episode_num' => 1,
        'url' => 'https://provider.example.com/show/s2e1.mkv',
    ]);
    $seriesY = cadSeriesWithEpisodes($playlist, ['tmdb_id' => 500, 'tvdb_id' => null], episodeCount: 1);
    cadAttachMember($group, $seriesX, 0);
    cadAttachMember($group, $seriesY, 1);

    $row = CachedContentFile::factory()
        ->arrSeries($this->sonarr, $seriesX)
        ->create(['tmdb_id' => '500', 'arr_seasons' => [1]]);

    $members = iterator_to_array(app(CachedContentDispatchService::class)->arrRowMembersOf($group, $row));

    // Season 1 of both member series (the row's arr_seasons filter), the
    // season-2 episode excluded; series matched by tvdb (X) and tmdb (Y).
    expect($members)->toHaveCount(2)
        ->and(collect($members)->pluck('series_id')->sort()->values()->all())->toBe([$seriesX->id, $seriesY->id])
        ->and(collect($members)->every(fn (Episode $episode) => $episode->season === 1))->toBeTrue();

    foreach ($members as $member) {
        expect($member->series->id)->toBe($member->series_id)
            ->and($member->playlist->id)->toBe($playlist->id);
    }
});

it('attaches the group pivot to a tmdb-resolved series arr row and resolves the tvdb id once', function () {
    [$playlist, $group] = cadPlaylistWithGroup($this->user, 'series', ['cache_enabled' => true, 'cache_method' => 'arr']);
    cadAttachMember($group, cadSeriesWithEpisodes($playlist, ['tmdb_id' => 500, 'tvdb_id' => null], episodeCount: 1), 0);
    cadSettings(method: 'arr', sonarrId: $this->sonarr->id);
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tmdbId' => 500,
                'tvdbId' => 81189,
                'title' => 'Resolved Series',
                'titleSlug' => 'resolved-series',
                'seasons' => [
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 9], 201),
    ]);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::ArrRequested->value])->toBe(1);

    // The tmdb-resolved series still lands its group's provenance pivot on
    // the shared sonarr row (previously the group leg computed tvdb 0 and
    // attached none).
    $row = CachedContentFile::where('source', 'sonarr')->sole();
    $pivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $row->id)
        ->where('dynamic_group_id', $group->id)
        ->first();
    expect($row->tvdb_id)->toBe('81189')
        ->and($pivot)->not->toBeNull()
        ->and($pivot->retention)->toBe('in_group')
        ->and($pivot->dropped_at)->toBeNull();

    // One tmdb: lookup for the whole dispatch — the group leg's resolution
    // is memoized, so dispatchArr() doesn't ask Sonarr again.
    $tmdbLookups = 0;
    Http::assertSent(function (Request $request) use (&$tmdbLookups): bool {
        $url = strtolower($request->url());
        if (str_contains($url, '/series/lookup') && (str_contains($url, 'term=tmdb%3a500') || str_contains($url, 'term=tmdb:500'))) {
            $tmdbLookups++;
        }

        return true;
    });
    expect($tmdbLookups)->toBe(1);
});
