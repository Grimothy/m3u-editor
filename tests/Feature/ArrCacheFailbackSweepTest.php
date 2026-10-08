<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\ArrCacheFailbackService;
use App\Settings\GeneralSettings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * Row helpers prefixed acf* (arr cache failback) — CacheViaArrTest owns the
 * cva* ones; don't reuse them here so the suites stay independently
 * runnable.
 */
function acfPlaylist(): Playlist
{
    return Playlist::factory()->create(['prefer_media_server_sources' => true]);
}

function acfArr(Playlist $playlist, string $type, array $attributes = []): ArrIntegration
{
    return ArrIntegration::factory()->{$type}()->cacheEnabled()->cacheFailback()->create([
        'user_id' => $playlist->user_id,
        'url' => "http://{$type}.test",
    ]);
}

function acfChannel(Playlist $playlist, int $tmdbId = 550): Channel
{
    return Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => $tmdbId,
        'url' => "https://provider.example.com/movie/{$tmdbId}.mkv",
    ]);
}

function acfEpisode(Playlist $playlist, int $tvdbId = 81189, int $season = 2, int $episodeNum = 3): Episode
{
    $series = Series::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'enabled' => true,
        'tvdb_id' => $tvdbId,
    ]);

    return Episode::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'enabled' => true,
        'season' => $season,
        'episode_num' => $episodeNum,
        'url' => "https://provider.example.com/{$series->id}/s{$season}e{$episodeNum}.mkv",
    ]);
}

function acfRow(Channel|Episode $item, ArrIntegration $arr, array $overrides = []): CachedContentFile
{
    return CachedContentFile::factory()->forItem($item)->create([
        'source' => 'arr',
        'arr_integration_id' => $arr->id,
        'arr_requested_at' => now()->subMinutes(5),
        ...$overrides,
    ]);
}

function acfService(): ArrCacheFailbackService
{
    return app(ArrCacheFailbackService::class);
}

/**
 * Radarr fake routing by path: status / queue / history / movie list /
 * single movie (unmonitor PUT). Anything else fails loudly.
 *
 * @param  array<int, array<string, mixed>>  $queue
 * @param  array<int, array<string, mixed>>  $history
 * @param  array<int, array<string, mixed>>  $movies
 */
function acfRadarrFake(array $queue = [], array $history = [], array $movies = [], bool $healthy = true): void
{
    Http::fake(function (Request $request) use ($queue, $history, $movies, $healthy) {
        $url = $request->url();

        if (str_contains($url, '/system/status')) {
            return $healthy
                ? Http::response(['version' => '5.0'])
                : Http::response(['error' => 'boom'], 500);
        }

        if (str_contains($url, '/queue')) {
            return Http::response(['page' => 1, 'totalRecords' => count($queue), 'records' => $queue]);
        }

        if (str_contains($url, '/history')) {
            return Http::response(['page' => 1, 'totalRecords' => count($history), 'records' => $history]);
        }

        if (preg_match('#/movie/\d+$#', $url)) {
            return Http::response(['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]);
        }

        if (str_contains($url, '/movie')) {
            return Http::response($movies);
        }

        throw new ConnectionException('unexpected arr request: '.$url);
    });
}

/**
 * Sonarr fake routing by path: status / queue / history / series list /
 * series lookup / episodes / episode monitor (unmonitor PUT).
 *
 * @param  array<int, array<string, mixed>>  $queue
 * @param  array<int, array<string, mixed>>  $history
 * @param  array<int, array<string, mixed>>  $series
 * @param  array<int, array<string, mixed>>  $episodes
 */
function acfSonarrFake(array $queue = [], array $history = [], array $series = [], array $episodes = [], bool $healthy = true): void
{
    Http::fake(function (Request $request) use ($queue, $history, $series, $episodes, $healthy) {
        $url = $request->url();

        if (str_contains($url, '/system/status')) {
            return $healthy
                ? Http::response(['version' => '4.0'])
                : Http::response(['error' => 'boom'], 500);
        }

        // /queue before /episode: the queue URL's includeEpisode=true query
        // would otherwise match the episodes branch.
        if (str_contains($url, '/queue')) {
            return Http::response(['page' => 1, 'totalRecords' => count($queue), 'records' => $queue]);
        }

        if (str_contains($url, '/history')) {
            return Http::response(['page' => 1, 'totalRecords' => count($history), 'records' => $history]);
        }

        if (str_contains($url, '/episode/monitor')) {
            return Http::response([]);
        }

        if (str_contains($url, '/episode')) {
            return Http::response($episodes);
        }

        if (str_contains($url, '/series/lookup')) {
            return Http::response($series);
        }

        if (str_contains($url, '/series')) {
            return Http::response($series);
        }

        throw new ConnectionException('unexpected arr request: '.$url);
    });
}

function acfHistoryEvent(string $eventType, string $when, ?int $movieId = null, ?int $episodeId = null, ?int $seriesId = null): array
{
    return array_filter([
        'eventType' => $eventType,
        'date' => $when,
        'movieId' => $movieId,
        'episodeId' => $episodeId,
        'seriesId' => $seriesId,
    ], fn ($value): bool => $value !== null);
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    Sleep::fake();
    NotificationFacade::fake();
});

// --- Movies (Radarr) ---

it('fails back on a terminal queue failure and unmonitors the movie', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist), $arr);

    acfRadarrFake(
        queue: [[
            'status' => 'failed',
            'trackedDownloadState' => 'failedPending',
            'trackedDownloadStatus' => 'warning',
            'movie' => ['tmdbId' => 550, 'title' => 'Fight Club'],
        ]],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();

    $row->refresh();

    expect($row->source)->toBe('provider')
        ->and($row->fallback_dispatched_at)->not->toBeNull()
        ->and($row->status)->toBe(CachedContentFileStatus::Pending);
    Bus::assertDispatched(DownloadCachedContentFile::class, fn (DownloadCachedContentFile $job): bool => $job->cachedContentFileId === $row->id);

    // Unmonitor, never delete.
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/movie/7'))->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE')->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'
            && ($request['deleteFiles'] ?? null) !== null)->count())->toBe(0);
});

it('fails back on a downloadFailed history event after the request', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    acfRadarrFake(
        history: [acfHistoryEvent('downloadFailed', now()->addMinutes(30)->toIso8601String(), movieId: 7)],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();

    expect($row->refresh()->fallback_dispatched_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('fails back after 24 hours of never being grabbed', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    // Not in the queue, nothing in history, no file in the library.
    acfRadarrFake(movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]]);

    acfService()->sweep();

    expect($row->refresh()->fallback_dispatched_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('leaves a movie alone while the arr is still downloading it', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(
        queue: [[
            'status' => 'downloading',
            'trackedDownloadState' => 'downloading',
            'trackedDownloadStatus' => 'ok',
            'movie' => ['tmdbId' => 550, 'title' => 'Fight Club'],
        ]],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('arr')
        ->and($row->fallback_dispatched_at)->toBeNull();
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('leaves a movie alone when a grab happened after the request', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(
        history: [acfHistoryEvent('grabbed', now()->subHours(29)->toIso8601String(), movieId: 7)],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('arr')
        ->and($row->fallback_dispatched_at)->toBeNull();
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('notifies once instead of failing back when the arr import failed', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    acfRadarrFake(
        queue: [[
            'status' => 'completed',
            'trackedDownloadState' => 'importFailed',
            'trackedDownloadStatus' => 'warning',
            'movie' => ['tmdbId' => 550, 'title' => 'Fight Club'],
        ]],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();
    acfService()->sweep();

    $user = User::find($row->user_id);

    expect($row->refresh()->source)->toBe('arr')
        ->and($row->fallback_dispatched_at)->toBeNull()
        ->and($row->fallback_notified_at)->not->toBeNull();
    NotificationFacade::assertSentTo($user, DatabaseNotification::class, 1);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('notifies once on a ManualInteractionRequired webhook event', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $arr->id,
        'user_id' => $playlist->user_id,
        'external_id' => '550',
        'status' => 'manual_required',
        'last_event_at' => now(),
    ]);

    acfRadarrFake(movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]]);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('arr')
        ->and($row->fallback_dispatched_at)->toBeNull()
        ->and($row->fallback_notified_at)->not->toBeNull();
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('drops the tracking row when the arr has the file', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    acfRadarrFake(movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => true, 'monitored' => true]]);

    acfService()->sweep();

    expect(CachedContentFile::query()->count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

// --- Episodes (Sonarr) ---

it('fails back a single episode on a queue failure and unmonitors it', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'sonarr');
    $row = acfRow(acfEpisode($playlist), $arr);

    acfSonarrFake(
        queue: [[
            'status' => 'failed',
            'trackedDownloadState' => 'failedPending',
            'trackedDownloadStatus' => 'warning',
            'series' => ['tvdbId' => 81189, 'title' => 'Breaking Bad'],
            'episode' => ['seasonNumber' => 2, 'episodeNumber' => 3, 'title' => '...And the Bag\'s in the River'],
        ]],
        series: [['id' => 9, 'tvdbId' => 81189]],
        episodes: [['id' => 88, 'seasonNumber' => 2, 'episodeNumber' => 3, 'hasFile' => false]],
    );

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider')
        ->and($row->fallback_dispatched_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/episode/monitor')
        && in_array(88, $request['episodeIds'] ?? []))->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE')->count())->toBe(0);
});

it('drops the tracking row when Sonarr already has the episode file', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'sonarr');
    $row = acfRow(acfEpisode($playlist), $arr);

    acfSonarrFake(
        series: [['id' => 9, 'tvdbId' => 81189]],
        episodes: [['id' => 88, 'seasonNumber' => 2, 'episodeNumber' => 3, 'hasFile' => true]],
    );

    acfService()->sweep();

    expect(CachedContentFile::query()->count())->toBe(0);
});

// --- Guards ---

it('skips the whole sweep when the arr is unreachable', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(healthy: false);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('arr')
        ->and($row->fallback_dispatched_at)->toBeNull();
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatches the provider even when the unmonitor call fails', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    Http::fake(function (Request $request) {
        $url = $request->url();

        // Healthy everywhere except the unmonitor lookups, which the
        // failback must survive.
        if (str_contains($url, '/system/status')) {
            return Http::response(['version' => '5.0']);
        }

        if (str_contains($url, '/queue')) {
            return Http::response(['records' => [[
                'status' => 'failed',
                'trackedDownloadState' => 'failedPending',
                'trackedDownloadStatus' => 'warning',
                'movie' => ['tmdbId' => 550, 'title' => 'Fight Club'],
            ]]]);
        }

        if (str_contains($url, '/history')) {
            return Http::response(['records' => []]);
        }

        if (str_contains($url, '/movie') && ! str_contains($url, 'tmdbId=') && ! preg_match('#/movie/\d+$#', $url)) {
            return Http::response([['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]]);
        }

        throw new ConnectionException('radarr went away');
    });

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider')
        ->and($row->fallback_dispatched_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('never dispatches the provider twice for the same row', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    acfRadarrFake(
        queue: [[
            'status' => 'failed',
            'trackedDownloadState' => 'failedPending',
            'trackedDownloadStatus' => 'warning',
            'movie' => ['tmdbId' => 550, 'title' => 'Fight Club'],
        ]],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    // Two overlapping sweeps: the second must find nothing to do.
    acfService()->sweep();
    acfService()->sweep();

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 1);
    expect($row->refresh()->source)->toBe('provider');
});

it('keeps sweeping when one row cannot be judged', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $bad = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => null]);
    $good = acfRow(acfChannel($playlist, 551), $arr, ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(movies: [
        ['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true],
        ['id' => 8, 'tmdbId' => 551, 'hasFile' => false, 'monitored' => true],
    ]);

    acfService()->sweep();

    expect($bad->refresh()->source)->toBe('arr')
        ->and($good->refresh()->source)->toBe('provider');
    Bus::assertDispatched(DownloadCachedContentFile::class, fn (DownloadCachedContentFile $job): bool => $job->cachedContentFileId === $good->id);
});

it('does not serve an arr tracking row to playback', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $channel = acfChannel($playlist, 550);
    acfRow($channel, $arr);

    expect(CachedContentFile::findServableFor($channel))->toBeNull();
});

it('does nothing when the integration has failback off', function () {
    $playlist = acfPlaylist();
    $arr = ArrIntegration::factory()->radarr()->cacheEnabled()->create([
        'user_id' => $playlist->user_id,
        'url' => 'http://radarr.test',
        'cache_failback' => false,
    ]);
    acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]]);

    acfService()->sweep();

    expect(CachedContentFile::query()->where('source', 'arr')->count())->toBe(1);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
    Http::assertNothingSent();
});

it('fetches the arr library once however many rows are tracked', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');

    foreach ([550, 551, 552] as $tmdbId) {
        acfRow(acfChannel($playlist, $tmdbId), $arr);
    }

    acfRadarrFake(movies: [
        ['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true],
        ['id' => 8, 'tmdbId' => 551, 'hasFile' => false, 'monitored' => true],
        ['id' => 9, 'tmdbId' => 552, 'hasFile' => false, 'monitored' => true],
    ]);

    acfService()->sweep();

    $libraryCalls = Http::recorded(fn (Request $request): bool => $request->method() === 'GET'
        && preg_match('#/movie$#', $request->url()) === 1);

    expect($libraryCalls->count())->toBe(1);
});

it('still falls back a manual-attention title once the deadline passes', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $arr->id,
        'user_id' => $playlist->user_id,
        'external_id' => '550',
        'status' => 'manual_required',
        'last_event_at' => now()->subHours(29),
    ]);

    acfRadarrFake(movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]]);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider')
        ->and($row->fallback_dispatched_at)->not->toBeNull()
        ->and($row->fallback_notified_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('still falls back a stuck import-failed queue entry once the deadline passes', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(
        queue: [[
            'status' => 'completed',
            'trackedDownloadState' => 'importFailed',
            'trackedDownloadStatus' => 'warning',
            'movie' => ['tmdbId' => 550, 'title' => 'Fight Club'],
        ]],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider')
        ->and($row->fallback_dispatched_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('fails back on a download failure even after a manual-attention warning', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $arr->id,
        'user_id' => $playlist->user_id,
        'external_id' => '550',
        'status' => 'manual_required',
        'last_event_at' => now(),
    ]);

    acfRadarrFake(
        history: [acfHistoryEvent('downloadFailed', now()->addMinutes(30)->toIso8601String(), movieId: 7)],
        movies: [['id' => 7, 'tmdbId' => 550, 'hasFile' => false, 'monitored' => true]],
    );

    acfService()->sweep();

    expect($row->refresh()->fallback_dispatched_at)->not->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('never lets the downloader claim an arr-tracked row', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $row = acfRow(acfChannel($playlist, 550), $arr, ['status' => CachedContentFileStatus::Pending]);

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    Http::fake();
    (new DownloadCachedContentFile($row->id))->handle();

    expect($row->refresh()->status)->toBe(CachedContentFileStatus::Pending)
        ->and($row->source)->toBe('arr');
    Http::assertNothingSent();
});
