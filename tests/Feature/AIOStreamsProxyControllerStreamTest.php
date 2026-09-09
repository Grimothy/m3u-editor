<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);

    // aiostreams_integration_id is the actual authorization/assignment field
    // (which integration a playlist may browse) - distinct from playlist_id
    // above, which is only the content-sync target for library imports.
    $this->playlist->update(['aiostreams_integration_id' => $this->integration->id]);
});

it('rewrites each stream candidate url to a proxied url, never returning the raw resolved url', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response([
            'streams' => [
                ['name' => 'Movie.720p', 'url' => 'https://debrid.example.com/secret-token/720p.mkv'],
                ['name' => 'Movie.1080p', 'url' => 'https://debrid.example.com/secret-token/1080p.mkv'],
            ],
        ], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertOk();

    $streams = $response->json('streams');

    expect($streams)->toHaveCount(2);

    foreach ($streams as $stream) {
        expect($stream['url'])
            ->toContain("/aiostreams-media/{$this->integration->id}/live/")
            ->not->toContain('debrid.example.com')
            ->not->toContain('secret-token');
    }
});

it('returns unauthorized for unrecognized credentials', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => []], 200),
    ]);

    $response = $this->get("/{$this->user->name}/wrong-password/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

// ── #1384 regressions: direct-proxy routes must enforce PlaylistAuth /
// effective-playlist AIOStreams scoping, not just "any enabled integration
// owned by the same user".

it('rejects a valid credential requesting an integration ID assigned to a different playlist owned by the same user', function () {
    $otherPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $otherIntegration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Other AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/other/manifest.json',
    ]);
    $otherPlaylist->update(['aiostreams_integration_id' => $otherIntegration->id]);

    // Valid owner credentials for $this->playlist, but requesting the
    // OTHER playlist's integration ID by guessing/incrementing it.
    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$otherIntegration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

it('rejects a PlaylistAuth credential with aiostreams_enabled=false even for its own assigned integration', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'aio_user',
        'password' => 'aio_pass',
        'enabled' => true,
        'aiostreams_enabled' => false,
    ]);
    $auth->assignTo($this->playlist);

    $response = $this->get("/aio_user/aio_pass/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

it('allows a PlaylistAuth credential with aiostreams_enabled=true for its assigned integration', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => []], 200),
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'aio_user_2',
        'password' => 'aio_pass_2',
        'enabled' => true,
        'aiostreams_enabled' => true,
    ]);
    $auth->assignTo($this->playlist);

    $response = $this->get("/aio_user_2/aio_pass_2/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertOk();
});

it('rejects a request for an integration ID that is not assigned to any playlist', function () {
    $unassignedIntegration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Unassigned AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/unassigned/manifest.json',
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$unassignedIntegration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

// ── TMDB ids: debrid addons only resolve IMDb ids, so a "tmdb:*" id from the
// catalog is resolved via the meta lookup before streams are fetched (PR #1491).

it('resolves a tmdb movie id to its imdb id before fetching streams', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'imdb_id' => 'tt0133093', 'name' => 'The Matrix'],
        ], 200),
        'aiostreams.test/abc/stream/movie/tt0133093.json*' => Http::response([
            'streams' => [
                ['name' => 'Matrix.1080p', 'url' => 'https://debrid.example.com/secret/1080p.mkv'],
            ],
        ], 200),
        'aiostreams.test/abc/stream/movie/tmdb:603.json*' => Http::response(['streams' => []], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/stream/movie/tmdb:603.json");

    $response->assertOk();
    expect($response->json('streams'))->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/stream/movie/tt0133093.json'));
});

it('preserves the season and episode coordinates when resolving a tmdb series id', function () {
    Http::fake([
        'aiostreams.test/abc/meta/series/tmdb:1399.json*' => Http::response([
            'meta' => ['id' => 'tmdb:1399', 'imdb_id' => 'tt0944947'],
        ], 200),
        'aiostreams.test/abc/stream/series/tt0944947:1:1.json*' => Http::response([
            'streams' => [
                ['name' => 'GoT.S01E01', 'url' => 'https://debrid.example.com/secret/got.mkv'],
            ],
        ], 200),
        'aiostreams.test/abc/*' => Http::response(['streams' => []], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/stream/series/tmdb:1399:1:1.json");

    $response->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/meta/series/tmdb:1399.json'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/stream/series/tt0944947:1:1.json'));
});

it('falls back to the original tmdb id when the meta lookup yields no imdb id', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:999999.json*' => Http::response(['meta' => ['id' => 'tmdb:999999']], 200),
        'aiostreams.test/abc/stream/movie/tmdb:999999.json*' => Http::response(['streams' => []], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/stream/movie/tmdb:999999.json");

    $response->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/stream/movie/tmdb:999999.json'));
});
