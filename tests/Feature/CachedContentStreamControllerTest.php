<?php

use App\Models\CachedContentFile;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires SyncPipelineService -> dispatch(ProcessM3uImport).
    // Bus::fake() catches it; nothing reaches Redis during tests.
    Bus::fake();
    Storage::fake('cache');
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
});

it('returns 401 with invalid credentials', function () {
    // No auth, no resolution - the controller should bail at the auth step.
    $response = $this->get('/cached-content/baduser/badpass/some-uuid.mp4');

    $response->assertStatus(401);
});

it('serves a cached file when credentials resolve and the file belongs to the playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-abc.mp4', 'fake-bytes-for-testing');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'file_path' => 'cache/movie-abc.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertOk();
    expect((string) $response->streamedContent())->toContain('fake-bytes-for-testing');
});

it('does not serve cached files when caching is disabled', function () {
    app(GeneralSettings::class)->enable_cache = false;
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-disabled.mp4', 'bytes');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'file_path' => 'cache/movie-disabled.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertNotFound();
});

it('returns 404 when the file belongs to a different playlist (no info leak)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    Storage::disk('cache')->put('cache/movie-xyz.mp4', 'bytes');

    $fileA = CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'file_path' => 'cache/movie-xyz.mp4',
    ]);

    // userB tries to access userA's file via userB's playlist - 404, not 403.
    // Matches DvrStreamController pattern (no info leak via status code difference).
    $response = $this->get("/cached-content/{$userB->name}/{$playlistB->uuid}/{$fileA->uuid}.mp4");

    $response->assertStatus(404);
});

it('returns 404 when the cached file exists but the row is not in Completed status', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-pending.mp4', 'bytes');

    $file = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'file_path' => 'cache/movie-pending.mp4',
        // Status defaults to Pending from the factory.
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

it('serves a 206 Partial Content response for a Range request', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-range.mp4', str_repeat('A', 1024).str_repeat('B', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '777',
        'file_path' => 'cache/movie-range.mp4',
    ]);

    $response = $this->withHeaders(['Range' => 'bytes=0-99'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-99/2048');
    $response->assertHeader('Accept-Ranges', 'bytes');
});

it('returns 404 when the file row exists but the storage file is missing', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    // Deliberately do NOT put the file on disk.

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '666',
        'file_path' => 'cache/movie-missing.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

// --- PR #1524 review item 5: servable scope at stream auth ---
//
// The stream controller must honor the same sharing rule as the
// dispatcher + cache-hit gate so a file shared by a sibling playlist
// plays through this playlist's credentials. Cross-USER access is still
// 404 - the scope subquery filters sharing candidates by user_id.

it('streams a file shared by a same-user sibling playlist through this playlist credentials', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-shared.mp4', 'shared-bytes');

    // File belongs to playlistA (sharing), accessed via playlistB credentials.
    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '1234',
        'file_path' => 'cache/movie-shared.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlistB->uuid}/{$file->uuid}.mp4");

    $response->assertOk();
    expect((string) $response->streamedContent())->toContain('shared-bytes');
});

it('returns 404 when the playlist is not a sharing sibling and the file belongs to a sibling', function () {
    // Same user, but sibling playlistA has sharing OFF. The file is not
    // servable for playlistB - 404 (not 403) to match the
    // "no info leak" convention.
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => false]);
    $playlistB = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-private.mp4', 'bytes');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '5678',
        'file_path' => 'cache/movie-private.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlistB->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

it('returns 404 when another user tries to stream a file via their own credentials, even if the source shares', function () {
    // PR #1524 review item 5: other user's credentials cannot stream
    // A's uuid even if A's playlist has sharing ON. Cross-USER sharing
    // is never allowed.
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($userB)->create();
    Storage::disk('cache')->put('cache/movie-other-user.mp4', 'bytes');

    $fileA = CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '9999',
        'file_path' => 'cache/movie-other-user.mp4',
    ]);

    $response = $this->get("/cached-content/{$userB->name}/{$playlistB->uuid}/{$fileA->uuid}.mp4");

    $response->assertStatus(404);
});

// NOTE: PlaylistAuth (Method 1) auth coverage is intentionally omitted here.
// `playlist_auths` uses a polymorphic relation through `PlaylistAuthPivot`
// rather than a direct `playlist_id` column, so a factory-based seed is
// non-trivial. The Method 1 path is already exercised by the broader
// DvrRecordingDownloadTest coverage; our controller mirrors that pattern
// verbatim, so the shape coverage from Method 2 tests is sufficient for
// Phase 1 scope.
