<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 */
function setEnableCacheForDispatchTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

/**
 * Playlist::factory()->create() fires PlaylistListener -> SyncPipelineService
 * -> dispatch(ProcessM3uImport). Bus::fake() catches that; the dispatch
 * service tests never trigger the listener.
 */
beforeEach(function () {
    Bus::fake();
    setEnableCacheForDispatchTest(true);
});

// --- dispatchForChannel: ownership stamping ---

it('dispatchForChannel stamps user_id and playlist_id on the new row', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForChannel($channel);

    expect($jobs)->toHaveCount(1)
        ->and($jobs->first())->toBeInstanceOf(DownloadCachedContentFile::class);

    $row = CachedContentFile::sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->playlist_id)->toBe($playlist->id)
        ->and($row->content_type)->toBe('movie')
        ->and($row->tmdb_id)->toBe('550')
        ->and($row->status)->toBe(CachedContentFileStatus::Pending);
});

it('dispatchForChannel stamps playlist user when no auth user is logged in (scheduled orchestrator path)', function () {
    // Scheduled `cache:content` runs without an auth context. The service
    // must fall back to the source channel/episode's playlist owner.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 551,
        'url' => 'https://example.com/movie.mp4',
    ]);

    Auth::logout();

    app(CachedContentDispatchService::class)->dispatchForChannel($channel);

    $row = CachedContentFile::sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->playlist_id)->toBe($playlist->id);
});

it('dispatchForChannel includes tvdb_id in the fingerprint', function () {
    // Constraint 6 (PR #1500 review): tvdb_id must be in the fingerprint
    // so a cached row matches the live fingerprint even when the source
    // channel has a tvdb_id set.
    $playlist = Playlist::factory()->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => 550,
        'tvdb_id' => 12345,
        'url' => 'https://example.com/movie.mp4',
    ]);

    app(CachedContentDispatchService::class)->dispatchForChannel($channel);

    $row = CachedContentFile::sole();
    // Fingerprint shape: content_type:tmdb_id:tvdb_id:season_number:episode_number:quality
    expect($row->content_fingerprint)->toContain('12345');
});

// --- batched dedup: N channels with same fingerprint -> 1 INSERT ---

it('dispatchForChannel batched dedup: N channels with same fingerprint produce exactly 1 INSERT', function () {
    // Constraint 11 (PR #1500 review): dispatch dedup must be batched.
    // The single-item API is the degenerate case - subsequent calls hit
    // the existing-row short-circuit and produce no extra INSERTs.
    $playlist = Playlist::factory()->create();
    $channels = collect();
    for ($i = 0; $i < 5; $i++) {
        $channels->push(Channel::factory()->for($playlist)->create([
            'tmdb_id' => 550, // same fingerprint as every other channel
            'url' => "https://example.com/movie-{$i}.mp4",
        ]));
    }

    $insertCount = 0;
    DB::listen(function ($query) use (&$insertCount): void {
        // PostgreSQL: "insert into" / SQLite: "insert into" too.
        if (preg_match('/^insert into "cached_content_files"/i', trim($query->sql))) {
            $insertCount++;
        }
    });

    $service = app(CachedContentDispatchService::class);
    foreach ($channels as $channel) {
        $service->dispatchForChannel($channel);
    }

    expect($insertCount)->toBe(1, "expected exactly 1 INSERT, got {$insertCount}")
        ->and(CachedContentFile::count())->toBe(1);
});

it('dispatchForChannel does not INSERT when an existing Pending row blocks the fingerprint', function () {
    $playlist = Playlist::factory()->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);

    // Pre-existing row in any status short-circuits the INSERT.
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'status' => CachedContentFileStatus::Pending,
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
    ]);

    $insertCount = 0;
    DB::listen(function ($query) use (&$insertCount): void {
        if (preg_match('/^insert into "cached_content_files"/i', trim($query->sql))) {
            $insertCount++;
        }
    });

    $jobs = app(CachedContentDispatchService::class)->dispatchForChannel($channel);

    expect($jobs)->toHaveCount(0)
        ->and($insertCount)->toBe(0)
        ->and(CachedContentFile::count())->toBe(1);
});

// --- isCrossPlaylistDuplicate + findSharedCacheHit: source-owns-file sharing rule ---

it('isCrossPlaylistDuplicate returns false when source playlist has sharing OFF', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'share_cache_across_playlists' => false,
    ]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor(['content_type' => 'movie', 'tmdb_id' => '550']);

    $isDuplicate = app(CachedContentDispatchService::class)
        ->isCrossPlaylistDuplicate($fingerprint, $playlist);

    expect($isDuplicate)->toBeFalse();
});

it('isCrossPlaylistDuplicate returns true when source playlist has sharing ON AND Completed row exists', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'share_cache_across_playlists' => true,
    ]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor(['content_type' => 'movie', 'tmdb_id' => '550']);

    $isDuplicate = app(CachedContentDispatchService::class)
        ->isCrossPlaylistDuplicate($fingerprint, $playlist);

    expect($isDuplicate)->toBeTrue();
});

it('isCrossPlaylistDuplicate returns false when matching row is Pending (not Completed)', function () {
    // Constraint 4 (PR #1500 review): sharing only kicks in when a
    // Completed row exists. Pending/Downloading/Failed don't satisfy
    // a sharing lookup - a failed share would just re-fail on playback.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'share_cache_across_playlists' => true,
    ]);
    // Vary tmdb_id per iteration so each row has a unique fingerprint
    // (content_fingerprint has a UNIQUE constraint - same content = same
    // fingerprint = insert violation).
    $i = 0;
    foreach ([
        CachedContentFileStatus::Pending,
        CachedContentFileStatus::Downloading,
        CachedContentFileStatus::Failed,
    ] as $status) {
        $row = CachedContentFile::factory()->create([
            'content_type' => 'movie',
            'tmdb_id' => (string) (550 + $i++),
            'status' => $status,
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);
        $fingerprint = $row->content_fingerprint;

        $isDuplicate = app(CachedContentDispatchService::class)
            ->isCrossPlaylistDuplicate($fingerprint, $playlist);

        expect($isDuplicate)->toBeFalse("sharing should not match {$status->value} rows");
    }
});

it('isCrossPlaylistDuplicate returns false when matching row exists for a different playlist', function () {
    // Sharing is per-source-playlist, not per-fingerprint-globally.
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($userB)->create(['share_cache_across_playlists' => true]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlistA->id,
        'user_id' => $userA->id,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor(['content_type' => 'movie', 'tmdb_id' => '550']);

    $isDuplicate = app(CachedContentDispatchService::class)
        ->isCrossPlaylistDuplicate($fingerprint, $playlistB);

    expect($isDuplicate)->toBeFalse();
});

it('findSharedCacheHit returns the matching Completed row when sharing-on', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $hit = app(CachedContentDispatchService::class)
        ->findSharedCacheHit($existing->content_fingerprint, $playlist);

    expect($hit)->not->toBeNull()
        ->and($hit->id)->toBe($existing->id);
});

it('findSharedCacheHit returns null when sharing is off', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => false]);
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $hit = app(CachedContentDispatchService::class)
        ->findSharedCacheHit($existing->content_fingerprint, $playlist);

    expect($hit)->toBeNull();
});

// --- dispatchForChannel short-circuits on a sharing hit ---

it('dispatchForChannel short-circuits when an existing shared Completed row satisfies the dispatch', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);

    $jobs = app(CachedContentDispatchService::class)->dispatchForChannel($channel);

    expect($jobs)->toHaveCount(0)
        ->and(CachedContentFile::count())->toBe(1); // the pre-existing share-hit
});

// --- dispatchForEpisode: same ownership + sharing rules, episode identity ---

it('dispatchForEpisode stamps user_id and playlist_id and uses parent Series tmdb_id/tvdb_id in fingerprint', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 60625,
        'tvdb_id' => 888,
    ]);
    $episode = Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 5,
        'url' => 'https://example.com/ep.mp4',
    ]);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForEpisode($episode);

    expect($jobs)->toHaveCount(1);
    $row = CachedContentFile::sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->playlist_id)->toBe($playlist->id)
        ->and($row->content_type)->toBe('episode')
        ->and($row->tmdb_id)->toBe('60625')
        ->and($row->tvdb_id)->toBe('888')
        ->and($row->season_number)->toBe(1)
        ->and($row->episode_number)->toBe(5);
});

it('dispatchForEpisode includes tvdb_id from the parent Series (not the episode)', function () {
    // Constraint 6 + Constraint 11 combined: episodes don't have their own
    // tvdb_id column, the parent's tvdb_id must be folded into the
    // fingerprint.
    $playlist = Playlist::factory()->create();
    $series = Series::factory()->for($playlist)->create(['tmdb_id' => 60625, 'tvdb_id' => 999]);
    $episode = Episode::factory()->for($playlist)->for($series, 'series')->create([
        'season' => 2,
        'episode_num' => 3,
        'url' => 'https://example.com/ep.mp4',
    ]);

    app(CachedContentDispatchService::class)->dispatchForEpisode($episode);

    $row = CachedContentFile::sole();
    expect($row->content_fingerprint)->toContain('999');
});

// --- dispatchForGroup: Phase 2 stub ---

it('dispatchForGroup returns empty Collection when called on an empty DynamicGroup', function () {
    // PR E: the Phase 2 stub is now a real implementation. An empty
    // DynamicGroup (no channels / no series in its membership) returns
    // an empty Collection without throwing - this verifies the basic
    // safety floor (no work to do = no error). Full membership-traversal
    // behavior is covered by DynamicGroupDispatchTest.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toBeEmpty()
        ->and(CachedContentFile::count())->toBe(0);
});

// --- enable_cache kill switch: dispatch is a no-op when the toggle is off ---

it('dispatchForChannel returns empty Collection without creating a row when enable_cache is off', function () {
    // PR #1524 review item 5: no path may dispatch when the kill switch
    // is off. Even with a valid playlist + URL, the dispatcher must
    // short-circuit before any INSERT.
    setEnableCacheForDispatchTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 7777,
        'url' => 'https://example.com/kill-switch.mp4',
    ]);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForChannel($channel);

    expect($jobs)->toBeEmpty()
        ->and(CachedContentFile::count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatchForEpisode returns empty Collection without creating a row when enable_cache is off', function () {
    setEnableCacheForDispatchTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create(['tmdb_id' => 8888]);
    $episode = Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 1,
        'url' => 'https://example.com/kill-switch-ep.mp4',
    ]);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForEpisode($episode);

    expect($jobs)->toBeEmpty()
        ->and(CachedContentFile::count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatchForGroup short-circuits before iterating when enable_cache is off', function () {
    // PR #1524 review item 5: dispatchForGroup must short-circuit BEFORE
    // iterating channels/episodes so it never even hits the membership
    // load. The empty Collection result + zero pivot rows verify the
    // dispatch path was never entered.
    setEnableCacheForDispatchTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = DynamicGroup::factory()->for($playlist)->for($user)->create(['type' => 'vod']);

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 9999,
        'url' => 'https://example.com/group-kill-switch.mp4',
    ]);
    $channel->dynamicGroups()->attach($group->id);

    Auth::login($user);

    $jobs = app(CachedContentDispatchService::class)->dispatchForGroup($group);

    expect($jobs)->toBeEmpty()
        ->and(CachedContentFile::count())->toBe(0)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

// --- describeExisting: lookup helper for the "Cache Now" UI disambiguation ---

it('describeExisting returns the matching Pending row when one exists for the item playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 1010,
        'url' => 'https://example.com/describe.mp4',
    ]);

    $existing = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1010',
        'status' => CachedContentFileStatus::Pending,
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $hit = app(CachedContentDispatchService::class)->describeExisting($channel);

    expect($hit)->not->toBeNull()
        ->and($hit->id)->toBe($existing->id)
        ->and($hit->status)->toBe(CachedContentFileStatus::Pending);
});

it('describeExisting falls back to a fingerprint-only match when no row exists for the item playlist', function () {
    // Cross-playlist sharing rule: even if the source playlist has no
    // row for the fingerprint, a row stamped to another playlist with
    // the same fingerprint is still surfaced so the UI can say "Already
    // cached" rather than the red failure notification.
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $playlistA = Playlist::factory()->for($ownerA)->create();
    $playlistB = Playlist::factory()->for($ownerB)->create();
    $channel = Channel::factory()->for($ownerB)->for($playlistB)->create([
        'tmdb_id' => 2020,
        'url' => 'https://example.com/describe-cross.mp4',
    ]);

    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '2020',
        'playlist_id' => $playlistA->id,
        'user_id' => $ownerA->id,
    ]);

    $hit = app(CachedContentDispatchService::class)->describeExisting($channel);

    expect($hit)->not->toBeNull()
        ->and($hit->id)->toBe($existing->id);
});

it('describeExisting returns null when no row matches the fingerprint at all', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 3030,
        'url' => 'https://example.com/describe-none.mp4',
    ]);

    expect(app(CachedContentDispatchService::class)->describeExisting($channel))->toBeNull();
});

// --- cacheNowNotification: shared result->notification mapping ---

it('cacheNowNotification returns an info "Already cached" notification when result.already is "cached"', function () {
    // Review item 3: a single shared helper covers both VOD + episode
    // action handlers. Severity must be info (not success) for the no-op
    // cases so users don't think a fresh download was queued.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 4040,
        'url' => 'https://example.com/cached.mp4',
    ]);

    $notification = CachedContentDispatchService::cacheNowNotification($channel, [
        'queued' => true,
        'already' => 'cached',
    ]);

    expect($notification->getTitle())->toBe('Already cached')
        ->and($notification->getBody())->toBe('This VOD already has a completed cached file.')
        ->and($notification->getStatus())->toBe('info');
});

it('cacheNowNotification returns an info "Already queued for caching" notification when result.already is "queued"', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 4041,
        'url' => 'https://example.com/queued.mp4',
    ]);

    $notification = CachedContentDispatchService::cacheNowNotification($channel, [
        'queued' => true,
        'already' => 'queued',
    ]);

    expect($notification->getTitle())->toBe('Already queued for caching')
        ->and($notification->getBody())->toBe('A pending or downloading cached file already exists for this VOD.')
        ->and($notification->getStatus())->toBe('info');
});

it('cacheNowNotification returns a success "Cache download queued" notification for a fresh dispatch', function () {
    // Only the new-dispatch path keeps the success severity.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 4042,
        'url' => 'https://example.com/fresh.mp4',
    ]);

    $notification = CachedContentDispatchService::cacheNowNotification($channel, ['queued' => true]);

    expect($notification->getTitle())->toBe('Cache download queued')
        ->and($notification->getBody())->toBe('Track progress on the Cached Downloads page.')
        ->and($notification->getStatus())->toBe('success');
});

it('cacheNowNotification returns a danger notification for a failed dispatch', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 4043,
        'url' => 'https://example.com/fail.mp4',
    ]);

    $notification = CachedContentDispatchService::cacheNowNotification($channel, [
        'queued' => false,
        'error' => 'Caching is disabled in Settings.',
    ]);

    expect($notification->getTitle())->toBe('Could not queue cache')
        ->and($notification->getBody())->toBe('Caching is disabled in Settings.')
        ->and($notification->getStatus())->toBe('danger');
});

it('cacheNowNotification uses the episode-specific body copy for Episodes', function () {
    // The helper must key its copy off the item type so VOD and episode
    // rows get the right "This episode..." / "This VOD..." wording.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create(['tmdb_id' => 5050]);
    $episode = Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 1,
        'url' => 'https://example.com/ep.mp4',
    ]);

    $cached = CachedContentDispatchService::cacheNowNotification($episode, [
        'queued' => true,
        'already' => 'cached',
    ]);
    $queued = CachedContentDispatchService::cacheNowNotification($episode, [
        'queued' => true,
        'already' => 'queued',
    ]);

    expect($cached->getTitle())->toBe('Already cached')
        ->and($cached->getBody())->toBe('This episode already has a completed cached file.')
        ->and($queued->getTitle())->toBe('Already queued for caching')
        ->and($queued->getBody())->toBe('A pending or downloading cached file already exists for this episode.');
});
