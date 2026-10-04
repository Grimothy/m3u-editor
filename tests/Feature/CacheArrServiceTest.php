<?php

use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\User;
use App\Services\Arr\ArrService;
use App\Services\ContentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->radarrIntegration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
    ]);
    $this->sonarrIntegration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);
});

// ── requestForCache ───────────────────────────────────────────────────────

it('requests a movie via radarr and creates an approved cache media request', function () {
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
        ], 200),
        '*/api/v3/movie' => Http::response(['id' => 77], 201),
    ]);

    $result = app(ContentRequestService::class)->requestForCache($this->radarrIntegration, 'movie', 550);

    expect($result['ok'])->toBeTrue()
        ->and($result['library_id'])->toBe(77);

    $mediaRequest = MediaRequest::query()->sole();
    expect($mediaRequest->playlist_auth_id)->toBeNull()
        ->and($mediaRequest->status)->toBe('approved')
        ->and($mediaRequest->arr_integration_id)->toBe($this->radarrIntegration->id)
        ->and($mediaRequest->request_type)->toBe('movie')
        ->and($mediaRequest->external_id)->toBe('550')
        ->and($mediaRequest->requested_at)->not->toBeNull()
        ->and($mediaRequest->reviewed_at)->not->toBeNull();
});

it('reports already_available with library and file info without adding to radarr', function () {
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([['id' => 5, 'hasFile' => true]], 200),
    ]);

    $result = app(ContentRequestService::class)->requestForCache($this->radarrIntegration, 'movie', 550);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('already_available')
        ->and($result['library_id'])->toBe(5)
        ->and($result['has_file'])->toBeTrue()
        ->and(MediaRequest::count())->toBe(0);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('reports submission_failed without creating a media request when radarr rejects the add', function () {
    Http::fake([
        '*/api/v3/movie?tmdbId=*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
        ], 200),
        '*/api/v3/movie' => Http::response([], 400),
    ]);

    $result = app(ContentRequestService::class)->requestForCache($this->radarrIntegration, 'movie', 550);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('submission_failed')
        ->and(MediaRequest::count())->toBe(0);
});

// ── resolveProgress ───────────────────────────────────────────────────────

function cacheArrMediaRequest(int $integrationId): MediaRequest
{
    return MediaRequest::create([
        'playlist_auth_id' => null,
        'arr_integration_id' => $integrationId,
        'title' => 'Dark',
        'external_id' => '12345',
        'request_type' => 'series',
        'payload' => ['tvdbId' => 12345, 'title' => 'Dark'],
        'status' => 'approved',
        'requested_at' => now(),
        'reviewed_at' => now(),
    ]);
}

it('aggregates per-episode sonarr queue records into one progress result', function () {
    $mediaRequest = cacheArrMediaRequest($this->sonarrIntegration->id);

    $queue = [
        ['externalId' => 12345, 'title' => 'Dark', 'status' => 'downloading', 'progress' => 50, 'size' => 100, 'sizeLeft' => 50, 'timeLeft' => '00:01:00', 'quality' => 'HDTV-1080p', 'protocol' => 'torrent', 'trackedDownloadState' => null],
        ['externalId' => 12345, 'title' => 'Dark', 'status' => 'downloading', 'progress' => 100, 'size' => 100, 'sizeLeft' => 0, 'timeLeft' => null, 'quality' => 'HDTV-1080p', 'protocol' => 'torrent', 'trackedDownloadState' => null],
    ];

    $result = app(ContentRequestService::class)->resolveProgress($mediaRequest, $queue, aggregateEpisodes: true);

    expect($result['size'])->toBe(200)
        ->and($result['size_left'])->toBe(50)
        ->and($result['progress'])->toBe(75)
        ->and($result['status'])->toBe('downloading');
});

it('reports the blocked tracked state from the representative queue item when aggregating', function () {
    $mediaRequest = cacheArrMediaRequest($this->sonarrIntegration->id);

    $queue = [
        ['externalId' => 12345, 'title' => 'Dark', 'status' => 'downloading', 'progress' => 50, 'size' => 100, 'sizeLeft' => 50, 'timeLeft' => '00:01:00', 'quality' => 'HDTV-1080p', 'protocol' => 'torrent', 'trackedDownloadState' => 'importBlocked'],
        ['externalId' => 12345, 'title' => 'Dark', 'status' => 'downloading', 'progress' => 100, 'size' => 100, 'sizeLeft' => 0, 'timeLeft' => null, 'quality' => 'HDTV-1080p', 'protocol' => 'torrent', 'trackedDownloadState' => null],
    ];

    $result = app(ContentRequestService::class)->resolveProgress($mediaRequest, $queue, aggregateEpisodes: true);

    expect($result['tracked_state'])->toBe('importBlocked');
});

// ── RadarrService::remove ─────────────────────────────────────────────────

it('removes a movie from the radarr library with its files', function () {
    Http::fake([
        '*/api/v3/*' => Http::response([], 200),
    ]);

    $result = ArrService::make($this->radarrIntegration)->remove(5);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/movie/5?deleteFiles=true&addImportExclusion=false'));
});

// ── SonarrService::monitorSeasonAndSearch ─────────────────────────────────

it('monitors one season of a sonarr series and searches it', function () {
    Http::fake([
        '*/api/v3/series/9*' => Http::response([
            'id' => 9,
            'title' => 'Dark',
            'seasons' => [
                ['seasonNumber' => 1, 'monitored' => false],
                ['seasonNumber' => 2, 'monitored' => false],
            ],
        ], 200),
        '*/api/v3/command*' => Http::response(['id' => 1], 201),
    ]);

    $result = ArrService::make($this->sonarrIntegration)->monitorSeasonAndSearch(9, 2);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/series/9'));

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/series/9')) {
            return false;
        }

        return collect($request['seasons'])->firstWhere('seasonNumber', 2)['monitored'] === true;
    });

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/command')
        && $request['name'] === 'SeasonSearch'
        && $request['seriesId'] === 9
        && $request['seasonNumber'] === 2);
});

// ── SonarrService::resolveTvdbIdFromTmdb ──────────────────────────────────

it('resolves a tvdb id from a tmdb lookup match', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([['tmdbId' => 500, 'tvdbId' => 81189]], 200),
    ]);

    expect(ArrService::make($this->sonarrIntegration)->resolveTvdbIdFromTmdb(500))->toBe(81189);
});

it('returns null when the sonarr lookup does not echo the tmdb id', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([['tvdbId' => 81189]], 200),
    ]);

    expect(ArrService::make($this->sonarrIntegration)->resolveTvdbIdFromTmdb(500))->toBeNull();
});

it('returns null when the sonarr lookup echoes a different tmdb id', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([['tmdbId' => 999, 'tvdbId' => 1]], 200),
    ]);

    expect(ArrService::make($this->sonarrIntegration)->resolveTvdbIdFromTmdb(500))->toBeNull();
});

it('returns null when the sonarr lookup fails', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 500),
    ]);

    expect(ArrService::make($this->sonarrIntegration)->resolveTvdbIdFromTmdb(500))->toBeNull();
});
