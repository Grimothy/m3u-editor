<?php

use App\Models\ArrCacheDeparture;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\ArrCacheCleanupService;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

function accRadarr(Playlist $playlist): ArrIntegration
{
    return ArrIntegration::factory()->radarr()->cacheEnabled()->create([
        'user_id' => $playlist->user_id,
        'url' => 'http://radarr.test',
        'quality_profile_id' => 1,
        'root_folder_path' => '/media',
    ]);
}

function accChannel(Playlist $playlist, int $tmdbId): Channel
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

/**
 * A VOD dynamic group (rule + row) holding `$members`, appended to the
 * playlist's rules.
 *
 * @param  array<int, Channel>  $members
 * @param  array<string, mixed>  $rule
 */
function accGroup(Playlist $playlist, string $name, array $members, array $rule = []): DynamicGroup
{
    $playlist->refresh()->update(['dynamic_groups_config' => [
        ...($playlist->dynamic_groups_config ?? []),
        [
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => $name,
            'tmdb_params' => [],
            'cache_enabled' => true,
            'cache_keep_days' => 0,
            ...$rule,
        ],
    ]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => $name,
    ]);

    foreach ($members as $position => $member) {
        $group->channels()->attach($member->id, ['position' => $position]);
    }

    return $group->fresh();
}

/**
 * Fake a Radarr whose cleanup tag (id 5) is on `$movies` (library id =>
 * tmdb id). Every other request succeeds.
 *
 * @param  array<int, int>  $movies
 * @param  array<int, int>  $tags  tag ids per library id; defaults to [5]
 */
function accFakeRadarr(ArrIntegration $radarr, array $movies, array $tags = []): void
{
    $responses = [
        'radarr.test/api/v3/tag' => Http::response([['id' => 5, 'label' => 'm3u-editor-cache-'.$radarr->id]]),
        'radarr.test/api/v3/tag/detail/5' => Http::response(['id' => 5, 'movieIds' => array_keys($movies)]),
    ];

    foreach ($movies as $movieId => $tmdbId) {
        $responses["radarr.test/api/v3/movie/{$movieId}*"] = Http::response([
            'id' => $movieId,
            'tmdbId' => $tmdbId,
            'title' => "Movie {$tmdbId}",
            'tags' => $tags[$movieId] ?? [5],
        ]);
    }

    Http::fake($responses);
}

function accDeletes(): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE')->count();
}

function accSweep(bool $dryRun = false): array
{
    return app(ArrCacheCleanupService::class)->sweep($dryRun);
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    Sleep::fake();

    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    $settings->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $settings);

    $this->playlist = Playlist::factory()->create(['prefer_media_server_sources' => true]);
    $this->radarr = accRadarr($this->playlist);
});

// --- Tagging ---

it('tags a movie a cleanup rule sends to Radarr, creating the tag', function () {
    $group = accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 550)], ['cache_arr_cleanup' => true]);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response([['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club', 'images' => []]]),
        'radarr.test/api/v3/tag' => Http::sequence()->push([])->push(['id' => 5, 'label' => 'm3u-editor-cache-'.$this->radarr->id]),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v3/tag')
        && $request['label'] === 'm3u-editor-cache-'.$this->radarr->id);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v3/movie')
        && $request['tags'] === [5]);
});

it('does not tag movies from rules without cleanup or from Cache Now', function () {
    $channel = accChannel($this->playlist, 550);
    $group = accGroup($this->playlist, 'Trending', [$channel]);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response([['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club', 'images' => []]]),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);
    app(CachedContentDispatchService::class)->dispatch(accChannel($this->playlist, 551), arrCleanup: true);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/tag'));
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->every(
        fn (array $pair): bool => $pair[0]['tags'] === []
    ))->toBeTrue();
});

it('turns cleanup off for Never expire rules', function () {
    $group = accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true, 'cache_never_expire' => true]);

    expect($group->cacheSettings()['arr_cleanup'])->toBeFalse();
});

// --- Cleanup ---

it('removes a tagged movie once it has been out of every group for the keep days', function () {
    accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 999)], ['cache_arr_cleanup' => true, 'cache_keep_days' => 3]);
    accFakeRadarr($this->radarr, [7 => 550]);

    // First run records the departure; nothing is removed yet.
    expect(accSweep())->toBe([])
        ->and(accDeletes())->toBe(0);
    $departure = ArrCacheDeparture::query()->sole();
    expect($departure->arr_movie_id)->toBe(7)
        ->and($departure->tmdb_id)->toBe(550);

    $this->travel(2)->days();
    expect(accSweep())->toBe([])
        ->and(accDeletes())->toBe(0);

    $this->travel(2)->days();
    expect(accSweep())->toHaveCount(1)
        ->and(ArrCacheDeparture::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/movie/7')
        && $request->url() === 'http://radarr.test/api/v3/movie/7?deleteFiles=true&addImportExclusion=false');
});

it('waits at least a day even when keep days is 0', function () {
    accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true]);
    accFakeRadarr($this->radarr, [7 => 550]);

    accSweep();
    $this->travel(12)->hours();
    accSweep();
    expect(accDeletes())->toBe(0);

    $this->travel(13)->hours();
    accSweep();
    expect(accDeletes())->toBe(1);
});

it('keeps a movie another cache group still holds, even one without cleanup', function () {
    accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true]);
    accGroup($this->playlist, 'Popular', [accChannel($this->playlist, 550)]);
    ArrCacheDeparture::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'arr_movie_id' => 7,
        'tmdb_id' => 550,
        'left_at' => now()->subDays(30),
    ]);
    accFakeRadarr($this->radarr, [7 => 550]);

    accSweep();

    expect(accDeletes())->toBe(0)
        ->and(ArrCacheDeparture::query()->count())->toBe(0);
});

it('never removes a movie without the cleanup tag', function () {
    accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true]);
    // Listed under the tag but the movie itself no longer carries it.
    accFakeRadarr($this->radarr, [7 => 550], tags: [7 => [3]]);
    ArrCacheDeparture::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'arr_movie_id' => 7,
        'tmdb_id' => 550,
        'left_at' => now()->subDays(30),
    ]);

    accSweep();

    expect(accDeletes())->toBe(0);
});

it('ignores tags that belong to another integration', function () {
    accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true]);
    Http::fake([
        'radarr.test/api/v3/tag' => Http::response([['id' => 5, 'label' => 'm3u-editor-cache-999']]),
    ]);

    accSweep();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tag/detail'));
    expect(accDeletes())->toBe(0)
        ->and(ArrCacheDeparture::query()->count())->toBe(0);
});

it('does nothing when no rule has cleanup on', function () {
    accGroup($this->playlist, 'Trending', []);

    accSweep();

    Http::assertNothingSent();
});

it('changes nothing when Radarr is unreachable', function () {
    accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true]);
    $departure = ArrCacheDeparture::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'arr_movie_id' => 7,
        'tmdb_id' => 550,
        'left_at' => now()->subDays(30),
    ]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(accSweep())->toBe([]);

    expect(accDeletes())->toBe(0)
        ->and($departure->fresh())->not->toBeNull();
});

it('lists movies on a dry run without removing or recording anything', function () {
    accGroup($this->playlist, 'Trending', [], ['cache_arr_cleanup' => true]);
    ArrCacheDeparture::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'arr_movie_id' => 7,
        'tmdb_id' => 550,
        'left_at' => now()->subDays(30),
    ]);
    accFakeRadarr($this->radarr, [7 => 550, 8 => 551]);

    $this->artisan('cache:cleanup', ['--dry-run' => true])
        ->expectsOutputToContain('Movie 550 (TMDB 550)')
        ->expectsOutputToContain('[DRY RUN] Would remove 1 dynamic-group movies from Radarr.')
        ->assertSuccessful();

    expect(accDeletes())->toBe(0)
        ->and(ArrCacheDeparture::query()->pluck('arr_movie_id')->all())->toBe([7]);
});
