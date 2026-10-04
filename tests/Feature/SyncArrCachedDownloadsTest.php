<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CachedContentSource;
use App\Enums\CacheDispatchResult;
use App\Events\ArrQueueUpdated;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\MonitorArrSearch;
use App\Jobs\RefreshMediaServerLibraryJob;
use App\Jobs\SyncArrCachedDownloads;
use App\Jobs\SyncMediaServer;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\MediaRequest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Services\MediaSourceMatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
|--------------------------------------------------------------------------
| Step 6 — progress sync and error fallback
|--------------------------------------------------------------------------
|
| SyncArrCachedDownloads mirrors Radarr/Sonarr queue progress into arr
| cached download rows and falls back to the provider when arr reports a
| problem. MonitorArrSearch does the same when its interactive search
| comes back all-rejected. Jobs run directly via app()->call() because
| Bus::fake() (needed for the Playlist factory listeners) swallows
| dispatch(); Bus::assertDispatched only proves something was queued.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Http::preventStrayRequests();
    NotificationFacade::fake();
    // Only the broadcast event — faking ALL events swallows Eloquent model events.
    Event::fake([ArrQueueUpdated::class]);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
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

    sadSettings();
});

/**
 * Bind GeneralSettings so the provider leg (enable_cache) works in fallbacks.
 */
function sadSettings(bool $enableCache = true): GeneralSettings
{
    $settings = new GeneralSettings;
    $settings->enable_cache = $enableCache;
    $settings->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

/**
 * Run the sync job directly — Bus::fake() would swallow its dispatch.
 */
function sadRunSync(ArrIntegration $integration): void
{
    app()->call([new SyncArrCachedDownloads($integration->id), 'handle']);
}

/**
 * A VOD channel with a cacheable URL and a tmdb_id on $playlist.
 */
function sadChannel(Playlist $playlist, array $overrides = []): Channel
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
 * An arr CachedContentFile row standing for $cacheable (Channel → radarr,
 * Series → sonarr), linked to $integration by identity.
 */
function sadArrRow(Channel|Series $cacheable, ArrIntegration $integration, array $overrides = []): CachedContentFile
{
    $isMovie = $cacheable instanceof Channel;

    return CachedContentFile::create(array_merge([
        'user_id' => $cacheable->user_id,
        'playlist_id' => $cacheable->playlist_id,
        'cacheable_type' => $cacheable->getMorphClass(),
        'cacheable_id' => $cacheable->getKey(),
        'content_type' => $isMovie ? 'movie' : 'series',
        'tmdb_id' => $isMovie ? (string) $cacheable->tmdb_id : ($cacheable->tmdb_id ? (string) $cacheable->tmdb_id : null),
        'tvdb_id' => $isMovie ? null : (string) $cacheable->tvdb_id,
        'title' => $isMovie ? $cacheable->display_title : $cacheable->name,
        'status' => CachedContentFileStatus::Requested,
        'source' => $isMovie ? CachedContentSource::Radarr : CachedContentSource::Sonarr,
        'arr_integration_id' => $integration->id,
        'arr_library_id' => 77,
    ], $overrides));
}

/**
 * The MediaRequest the arr leg created for the row (auto-approved cache path shape).
 */
function sadMediaRequest(ArrIntegration $integration, string $externalId, string $type): MediaRequest
{
    return MediaRequest::create([
        'playlist_auth_id' => null,
        'arr_integration_id' => $integration->id,
        'title' => 'Fight Club',
        'external_id' => $externalId,
        'request_type' => $type,
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now()->subMinutes(5),
        'reviewed_at' => now()->subMinutes(5),
    ]);
}

/**
 * A series with tvdb/tmdb ids and cacheable episodes on $playlist.
 */
function sadSeriesWithEpisodes(Playlist $playlist, int $episodeCount = 1): Series
{
    $series = Series::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => 1399,
        'tvdb_id' => 81189,
    ]);

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
 * A playlist (media-server preference on) + DynamicGroup whose
 * dynamic_groups_config holds the matching rule (mirrors cadPlaylistWithGroup).
 *
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: Playlist, 1: DynamicGroup}
 */
function sadPlaylistWithGroup(User $user, string $type = 'vod', array $ruleOverrides = []): array
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
function sadAttachMember(DynamicGroup $group, Channel|Series $item, int $position): void
{
    $relation = $item instanceof Channel ? 'channels' : 'series';
    $group->{$relation}()->attach($item->id, ['position' => $position]);
}

/**
 * Give $channel an eligible media-server match (mirrors cadMatchToMedia).
 */
function sadMatchToMedia(Playlist $provider, Channel $channel): Channel
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

// ── 1. Downloading progress is mirrored ───────────────────────────────────

it('mirrors a radarr downloading record into the arr row', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr, [
        'media_request_id' => sadMediaRequest($this->radarr, '550', 'movie')->id,
    ]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => [
        ['id' => 1, 'downloadId' => 'd1', 'status' => 'downloading', 'size' => 1000, 'sizeleft' => 400, 'movie' => ['tmdbId' => 550, 'title' => 'Fight Club']],
    ]], 200)]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Downloading)
        ->and($row->bytes_expected)->toBe(1000)
        ->and($row->bytes_downloaded)->toBe(600)
        ->and($row->last_progress_at)->not->toBeNull();
});

// ── 2. importBlocked fails the row and queues the provider copy ───────────

it('fails the row and queues a provider copy when arr reports importBlocked', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr, [
        'media_request_id' => sadMediaRequest($this->radarr, '550', 'movie')->id,
    ]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => [
        ['id' => 1, 'status' => 'downloading', 'trackedDownloadState' => 'importBlocked', 'size' => 1000, 'sizeleft' => 900, 'movie' => ['tmdbId' => 550, 'title' => 'Fight Club']],
    ]], 200)]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Failed)
        ->and($row->fallback_dispatched_at)->not->toBeNull()
        ->and($row->failure_count)->toBe(1)
        ->and($row->last_error_message)->toContain('reported a problem');

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->status)->toBe(CachedContentFileStatus::Pending)
        ->and($provider->cacheable_id)->toBe($channel->id);

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

// ── 3. Group-managed rows fall back through the group ─────────────────────

it('falls back through the group so the provider row carries the group pivot', function () {
    [$playlist, $group] = sadPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    $channel = sadChannel($playlist);
    sadAttachMember($group, $channel, 0);

    $row = sadArrRow($channel, $this->radarr, [
        'managed_by' => CachedContentManagedBy::DynamicGroup,
        'media_request_id' => sadMediaRequest($this->radarr, '550', 'movie')->id,
    ]);
    $row->dynamicGroups()->attach($group->id, ['retention' => 'in_group', 'retention_days' => 7, 'dropped_at' => null]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => [
        ['id' => 1, 'status' => 'downloading', 'trackedDownloadState' => 'importFailed', 'size' => 1000, 'sizeleft' => 1000, 'movie' => ['tmdbId' => 550, 'title' => 'Fight Club']],
    ]], 200)]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Failed);

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->status)->toBe(CachedContentFileStatus::Pending)
        ->and($provider->managed_by)->toBe(CachedContentManagedBy::DynamicGroup)
        ->and($provider->dynamicGroups()->whereKey($group->id)->exists())->toBeTrue();
});

// ── 4. The fallback guard runs once per row ───────────────────────────────

it('returns zero counts and creates nothing on a second fallbackToProvider call', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr);

    $dispatcher = app(CachedContentDispatchService::class);

    $first = $dispatcher->fallbackToProvider($row);
    $second = $dispatcher->fallbackToProvider($row->refresh());

    expect($first[CacheDispatchResult::Queued->value])->toBe(1)
        ->and(array_sum($second))->toBe(0)
        ->and(CachedContentFile::query()->provider()->count())->toBe(1);
});

// ── 5. The webhook event path marks Imported and completes the request ────

it('marks the row imported from a webhook event and completes the request', function () {
    $channel = sadChannel($this->playlist);
    $request = sadMediaRequest($this->radarr, '550', 'movie');
    $row = sadArrRow($channel, $this->radarr, [
        'status' => CachedContentFileStatus::Downloading,
        'media_request_id' => $request->id,
    ]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'user_id' => $this->user->id,
        'external_id' => '550',
        'title' => 'Fight Club',
        'event_type' => 'Download',
        'status' => 'imported',
        'progress' => 100,
        'size' => 4096,
        'last_event_at' => now(),
    ]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Imported)
        ->and($row->bytes_expected)->toBe(4096)
        ->and($row->bytes_downloaded)->toBe(4096)
        ->and($request->refresh()->status)->toBe('completed');
});

// ── 6. An Imported row with a media match becomes Completed ───────────────

it('completes an imported row once the channel has an eligible media match', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr, [
        'status' => CachedContentFileStatus::Imported,
        'last_progress_at' => now()->subDay(),
    ]);

    sadMatchToMedia($this->playlist, $channel);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Completed)
        ->and($row->last_error_message)->toBeNull();
});

// ── 7. An Imported row that never reached the media server is flagged ─────

it('flags an imported row that has not appeared on any media server within a day', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr, [
        'status' => CachedContentFileStatus::Imported,
        'last_progress_at' => now()->subHours(25),
    ]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Imported)
        ->and($row->last_error_message)->toContain('not found on any media server');
});

// ── 8. Sonarr aggregates its queue records ────────────────────────────────

it('aggregates sonarr queue records for the same tvdb id', function () {
    $series = sadSeriesWithEpisodes($this->playlist);
    $row = sadArrRow($series, $this->sonarr, [
        'media_request_id' => sadMediaRequest($this->sonarr, '81189', 'series')->id,
    ]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => [
        ['id' => 1, 'status' => 'downloading', 'size' => 1000, 'sizeleft' => 400, 'series' => ['tvdbId' => 81189, 'title' => 'Game of Thrones']],
        ['id' => 2, 'status' => 'downloading', 'size' => 500, 'sizeleft' => 100, 'series' => ['tvdbId' => 81189, 'title' => 'Game of Thrones']],
    ]], 200)]);

    sadRunSync($this->sonarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Downloading)
        ->and($row->bytes_expected)->toBe(1500)
        ->and($row->bytes_downloaded)->toBe(1000);
});

// ── 9. The webhook trigger ────────────────────────────────────────────────

it('dispatches the sync job from an arr webhook when rows are in flight', function () {
    $channel = sadChannel($this->playlist);
    sadArrRow($channel, $this->radarr);

    $this->postJson('/api/webhooks/arr/'.$this->radarr->webhook_secret, [
        'eventType' => 'Grab',
        'movie' => ['title' => 'Fight Club', 'tmdbId' => 550],
        'downloadId' => 'abc123',
    ])->assertNoContent();

    Bus::assertDispatched(SyncArrCachedDownloads::class);
});

it('does not dispatch the sync job from a webhook when no arr rows are in flight', function () {
    sadChannel($this->playlist); // provider-style row only

    $this->postJson('/api/webhooks/arr/'.$this->radarr->webhook_secret, [
        'eventType' => 'Grab',
        'movie' => ['title' => 'Fight Club', 'tmdbId' => 550],
        'downloadId' => 'abc123',
    ])->assertNoContent();

    Bus::assertNotDispatched(SyncArrCachedDownloads::class);
});

// ── 10. MonitorArrSearch all-rejected falls back to the provider ──────────

it('fails the tracked row and queues a provider copy when all releases are rejected', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr);

    Http::fake([
        '*/api/v3/release*' => Http::response([
            ['title' => 'Fight Club.2024.1080p', 'approved' => false, 'rejections' => ['Quality not wanted']],
        ], 200),
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    app()->call([new MonitorArrSearch($this->radarr->id, 77, 'Fight Club', $this->user->id, $row->id), 'handle']);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Failed)
        ->and($row->fallback_dispatched_at)->not->toBeNull()
        ->and($row->last_error_message)->toContain('quality profile');

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->status)->toBe(CachedContentFileStatus::Pending);
});

it('does the same for a sonarr-tracked series row', function () {
    $series = sadSeriesWithEpisodes($this->playlist);
    $row = sadArrRow($series, $this->sonarr, ['arr_seasons' => [1]]);

    Http::fake([
        '*/api/v3/release?seriesId=77&seasonNumber=*' => Http::response([
            ['title' => 'Game.of.Thrones.S01', 'approved' => false, 'rejections' => ['Quality not wanted']],
        ], 200),
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    app()->call([new MonitorArrSearch($this->sonarr->id, 77, 'Game of Thrones', $this->user->id, $row->id), 'handle']);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Failed);

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->status)->toBe(CachedContentFileStatus::Pending)
        ->and($provider->cacheable_type)->toBe((new Episode)->getMorphClass());
});

it('searches each tracked sonarr season by seasonNumber and fails the row when all are rejected', function () {
    $series = sadSeriesWithEpisodes($this->playlist);
    $row = sadArrRow($series, $this->sonarr, ['arr_seasons' => [2]]);

    Episode::factory()->create([
        'user_id' => $this->playlist->user_id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season' => 2,
        'episode_num' => 1,
        'url' => 'https://provider.example.com/show/s2e1.mkv',
    ]);

    Http::fake([
        '*/api/v3/release?seriesId=77&seasonNumber=*' => Http::response([
            ['title' => 'Game.of.Thrones.S02', 'approved' => false, 'rejections' => ['Quality not wanted']],
        ], 200),
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    app()->call([new MonitorArrSearch($this->sonarr->id, 77, 'Game of Thrones', $this->user->id, $row->id), 'handle']);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/api/v3/release')
            && str_contains($request->url(), 'seriesId=77')
            && str_contains($request->url(), 'seasonNumber=2');
    });

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Failed)
        ->and($row->fallback_dispatched_at)->not->toBeNull();

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->status)->toBe(CachedContentFileStatus::Pending);
});

it('leaves the sonarr row requested when a tracked season has an approved release', function () {
    $series = sadSeriesWithEpisodes($this->playlist);
    $row = sadArrRow($series, $this->sonarr, ['arr_seasons' => [2]]);

    Http::fake([
        '*/api/v3/release?seriesId=77&seasonNumber=*' => Http::response([
            ['title' => 'Game.of.Thrones.S02', 'approved' => true, 'rejections' => []],
        ], 200),
    ]);

    app()->call([new MonitorArrSearch($this->sonarr->id, 77, 'Game of Thrones', $this->user->id, $row->id), 'handle']);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Requested)
        ->and(CachedContentFile::query()->provider()->count())->toBe(0);
});

it('does not check releases for a sonarr row tracking every season', function () {
    $series = sadSeriesWithEpisodes($this->playlist);
    $row = sadArrRow($series, $this->sonarr);

    Http::fake();

    app()->call([new MonitorArrSearch($this->sonarr->id, 77, 'Game of Thrones', $this->user->id, $row->id), 'handle']);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/release'));

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Requested);
});

// ── 12. Group fallback survives a deleted row cacheable ───────────────────

it('falls back through the group when the arr row\'s cacheable channel was deleted', function () {
    [$playlist, $group] = sadPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    $member = sadChannel($playlist);
    sadAttachMember($group, $member, 0);

    // The row's own cacheable is a different channel sharing the tmdb_id —
    // and it has been deleted since the row was created.
    $gone = sadChannel($playlist);
    $row = sadArrRow($gone, $this->radarr, [
        'managed_by' => CachedContentManagedBy::DynamicGroup,
    ]);
    $row->dynamicGroups()->attach($group->id, ['retention' => 'in_group', 'retention_days' => 7, 'dropped_at' => null]);

    $gone->delete();

    app(CachedContentDispatchService::class)->fallbackToProvider($row->refresh());

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->cacheable_id)->toBe($member->id)
        ->and($provider->status)->toBe(CachedContentFileStatus::Pending)
        ->and($provider->managed_by)->toBe(CachedContentManagedBy::DynamicGroup)
        ->and($provider->dynamicGroups()->whereKey($group->id)->exists())->toBeTrue();
});

// ── 11. arr already downloading: leave the row alone ─────────────────────

it('leaves the row requested when arr already has the title in its queue', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr);

    Http::fake([
        '*/api/v3/release*' => Http::response([
            ['title' => 'Fight Club.2024.1080p', 'approved' => false, 'rejections' => ['Quality not wanted']],
        ], 200),
        '*/api/v3/queue*' => Http::response(['records' => [
            ['id' => 1, 'status' => 'downloading', 'size' => 1000, 'sizeleft' => 500, 'movie' => ['tmdbId' => 550, 'title' => 'Fight Club']],
        ]], 200),
    ]);

    app()->call([new MonitorArrSearch($this->radarr->id, 77, 'Fight Club', $this->user->id, $row->id), 'handle']);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Requested)
        ->and(CachedContentFile::query()->provider()->count())->toBe(0);
});

// ── 13. An Imported transition refreshes the linked media server ──────────

it('dispatches the media server refresh jobs when a row transitions to imported', function () {
    $mediaServer = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $this->radarr->media_server_integration_id = $mediaServer->id;
    $this->radarr->save();

    $channel = sadChannel($this->playlist);
    $request = sadMediaRequest($this->radarr, '550', 'movie');
    sadArrRow($channel, $this->radarr, [
        'status' => CachedContentFileStatus::Downloading,
        'media_request_id' => $request->id,
    ]);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'user_id' => $this->user->id,
        'external_id' => '550',
        'title' => 'Fight Club',
        'event_type' => 'Download',
        'status' => 'imported',
        'progress' => 100,
        'size' => 4096,
        'last_event_at' => now(),
    ]);

    sadRunSync($this->radarr);

    $row = CachedContentFile::query()->arr()->sole();
    expect($row->status)->toBe(CachedContentFileStatus::Imported);

    Bus::assertDispatched(RefreshMediaServerLibraryJob::class);
    Bus::assertDispatched(SyncMediaServer::class);
});

it('refreshes the media server once when several rows import in one sweep', function () {
    $mediaServer = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $this->radarr->media_server_integration_id = $mediaServer->id;
    $this->radarr->save();

    foreach (['550', '551'] as $tmdbId) {
        $channel = sadChannel($this->playlist, ['tmdb_id' => $tmdbId]);
        $request = sadMediaRequest($this->radarr, $tmdbId, 'movie');
        sadArrRow($channel, $this->radarr, [
            'status' => CachedContentFileStatus::Downloading,
            'media_request_id' => $request->id,
        ]);

        ArrQueueEvent::factory()->create([
            'arr_integration_id' => $this->radarr->id,
            'user_id' => $this->user->id,
            'external_id' => $tmdbId,
            'title' => "Movie {$tmdbId}",
            'event_type' => 'Download',
            'status' => 'imported',
            'progress' => 100,
            'size' => 4096,
            'last_event_at' => now(),
        ]);
    }

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    // Null cache locks always acquire, so ShouldBeUnique can't hide per-title dispatches.
    config(['cache.default' => 'null']);
    sadRunSync($this->radarr);

    expect(CachedContentFile::query()->arr()->where('status', CachedContentFileStatus::Imported)->count())->toBe(2);
    Bus::assertDispatchedTimes(RefreshMediaServerLibraryJob::class, 1);
});

it('marks a downloading row imported when it left the queue and radarr has the file, without a webhook', function () {
    $channel = sadChannel($this->playlist);
    $request = sadMediaRequest($this->radarr, '550', 'movie');
    $row = sadArrRow($channel, $this->radarr, [
        'status' => CachedContentFileStatus::Downloading,
        'media_request_id' => $request->id,
        'bytes_downloaded' => 4096,
        'bytes_expected' => 4096,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/movie*' => Http::response([['id' => 77, 'tmdbId' => 550, 'hasFile' => true]], 200),
    ]);

    sadRunSync($this->radarr);

    expect($row->refresh()->status)->toBe(CachedContentFileStatus::Imported)
        ->and($request->refresh()->status)->toBe('completed');
});

it('leaves a downloading row alone when it left the queue but radarr has no file yet', function () {
    $channel = sadChannel($this->playlist);
    $request = sadMediaRequest($this->radarr, '550', 'movie');
    $row = sadArrRow($channel, $this->radarr, [
        'status' => CachedContentFileStatus::Downloading,
        'media_request_id' => $request->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/movie*' => Http::response([['id' => 77, 'tmdbId' => 550, 'hasFile' => false]], 200),
    ]);

    sadRunSync($this->radarr);

    expect($row->refresh()->status)->toBe(CachedContentFileStatus::Downloading);
});

it('marks a sonarr row imported when its tracked seasons have files after leaving the queue', function () {
    $series = sadSeriesWithEpisodes($this->playlist);
    $request = sadMediaRequest($this->sonarr, '81189', 'series');
    $row = sadArrRow($series, $this->sonarr, [
        'status' => CachedContentFileStatus::Downloading,
        'media_request_id' => $request->id,
        'arr_seasons' => [1],
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
            ['seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => false],
        ], 200),
    ]);

    sadRunSync($this->sonarr);

    expect($row->refresh()->status)->toBe(CachedContentFileStatus::Imported);
});

it('fails a requested row over to the provider when radarr is waiting for the release', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr, [
        'media_request_id' => sadMediaRequest($this->radarr, '550', 'movie')->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/movie*' => Http::response([['id' => 77, 'tmdbId' => 550, 'hasFile' => false, 'isAvailable' => false]], 200),
    ]);

    sadRunSync($this->radarr);

    $row->refresh();
    expect($row->status)->toBe(CachedContentFileStatus::Failed)
        ->and($row->fallback_dispatched_at)->not->toBeNull()
        ->and($row->last_error_message)->toContain('until it is released');

    $provider = CachedContentFile::query()->provider()->sole();
    expect($provider->cacheable_id)->toBe($channel->id);

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('leaves a requested row waiting when radarr considers the movie available', function () {
    $channel = sadChannel($this->playlist);
    $row = sadArrRow($channel, $this->radarr, [
        'media_request_id' => sadMediaRequest($this->radarr, '550', 'movie')->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/movie*' => Http::response([['id' => 77, 'tmdbId' => 550, 'hasFile' => false, 'isAvailable' => true]], 200),
    ]);

    sadRunSync($this->radarr);

    expect($row->refresh()->status)->toBe(CachedContentFileStatus::Requested)
        ->and(CachedContentFile::query()->provider()->count())->toBe(0);
});
