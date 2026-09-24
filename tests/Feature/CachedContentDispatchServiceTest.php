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

// --- findServableCacheHit: per-playlist + cross-playlist (within a single user) sharing rule ---

it('findServableCacheHit returns the playlist own Completed row when one exists', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $hit = app(CachedContentDispatchService::class)
        ->findServableCacheHit($existing->content_fingerprint, $playlist);

    expect($hit)->not->toBeNull()
        ->and($hit->id)->toBe($existing->id);
});

it('findServableCacheHit returns null when no Completed row exists for the playlist or any sharing sibling', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    // Pending row exists but is not Completed.
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $fingerprint = CachedContentFile::fingerprintFor(['content_type' => 'movie', 'tmdb_id' => '550']);

    $hit = app(CachedContentDispatchService::class)
        ->findServableCacheHit($fingerprint, $playlist);

    expect($hit)->toBeNull();
});

it('findServableCacheHit never matches rows owned by a different user', function () {
    // Cross-user sharing is forbidden by design. Even if both users have
    // sharing ON, a row owned by userA must not be servable for userB.
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

    $hit = app(CachedContentDispatchService::class)
        ->findServableCacheHit($fingerprint, $playlistB);

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

// --- PR #1524 review items 1+2: per-playlist uniqueness + cross-playlist sharing ---

it('two playlists of different users each get their own row for the same fingerprint', function () {
    // Item 1: composite unique on (content_fingerprint, playlist_id).
    // User A and user B both cache the same tmdb - each gets their own
    // row. The previous global unique on content_fingerprint made this
    // impossible (one user would always lose the INSERT race).
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    $channelA = Channel::factory()->for($userA)->for($playlistA)->create([
        'tmdb_id' => 777,
        'url' => 'https://example.com/a.mp4',
    ]);
    $channelB = Channel::factory()->for($userB)->for($playlistB)->create([
        'tmdb_id' => 777,
        'url' => 'https://example.com/b.mp4',
    ]);

    $jobsA = app(CachedContentDispatchService::class)->dispatchForChannel($channelA);
    $jobsB = app(CachedContentDispatchService::class)->dispatchForChannel($channelB);

    expect($jobsA)->toHaveCount(1)
        ->and($jobsB)->toHaveCount(1)
        ->and(CachedContentFile::count())->toBe(2);
});

it('same user, playlistA shares, playlistB dispatch is skipped', function () {
    // Item 2: cross-playlist sharing within one user. PlaylistA already
    // has a Completed row; PlaylistB is a sibling owned by the same
    // user with sharing ON. PlaylistB's dispatch must NOT create a new
    // row (the previous short-circuit only checked the item's own
    // playlist, which had no row for the fingerprint, so it always
    // dispatched).
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($user)->create();
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
    ]);
    $channelB = Channel::factory()->for($user)->for($playlistB)->create([
        'tmdb_id' => 888,
        'url' => 'https://example.com/b.mp4',
    ]);

    $jobs = app(CachedContentDispatchService::class)->dispatchForChannel($channelB);

    expect($jobs)->toHaveCount(0)
        ->and(CachedContentFile::count())->toBe(1);
});

it('same user, playlistA NOT sharing, playlistB gets its own row', function () {
    // Item 2: when sharing is OFF, cross-playlist sharing does NOT
    // apply - PlaylistB must insert its own row.
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => false]);
    $playlistB = Playlist::factory()->for($user)->create();
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
    ]);
    $channelB = Channel::factory()->for($user)->for($playlistB)->create([
        'tmdb_id' => 888,
        'url' => 'https://example.com/b.mp4',
    ]);

    $jobs = app(CachedContentDispatchService::class)->dispatchForChannel($channelB);

    expect($jobs)->toHaveCount(1)
        ->and(CachedContentFile::count())->toBe(2);
});

it('two unmatched movies on the same playlist get distinct fingerprints and rows', function () {
    // Item 6: when neither tmdb_id nor tvdb_id is set the fingerprint
    // falls back to a local_key derived from the channel id. Two such
    // channels on the same playlist must each get their own row (the
    // previous implementation collapsed them to `movie::::` and one
    // row for the whole playlist).
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channelA = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => null,
        'tvdb_id' => null,
        'url' => 'https://example.com/unmatched-a.mp4',
    ]);
    $channelB = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => null,
        'tvdb_id' => null,
        'url' => 'https://example.com/unmatched-b.mp4',
    ]);

    $service = app(CachedContentDispatchService::class);
    $jobsA = $service->dispatchForChannel($channelA);
    $jobsB = $service->dispatchForChannel($channelB);

    expect($jobsA)->toHaveCount(1)
        ->and($jobsB)->toHaveCount(1)
        ->and(CachedContentFile::count())->toBe(2);

    $rows = CachedContentFile::all();
    expect($rows[0]->content_fingerprint)->not->toBe($rows[1]->content_fingerprint);
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

it('describeExisting falls back to a same-user sharing row when no own-playlist row exists', function () {
    // PR #1524: cross-playlist sharing within the SAME user is surfaced
    // as "Already cached" (the row is servable for the item's playlist
    // via the source playlist's `share_cache_across_playlists = true`).
    $owner = User::factory()->create();
    $playlistA = Playlist::factory()->for($owner)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($owner)->create();
    $channel = Channel::factory()->for($owner)->for($playlistB)->create([
        'tmdb_id' => 2020,
        'url' => 'https://example.com/describe-cross.mp4',
    ]);

    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '2020',
        'playlist_id' => $playlistA->id,
        'user_id' => $owner->id,
    ]);

    $hit = app(CachedContentDispatchService::class)->describeExisting($channel);

    expect($hit)->not->toBeNull()
        ->and($hit->id)->toBe($existing->id);
});

it('describeExisting never returns a row owned by a different user (privacy)', function () {
    // PR #1524 review item (carry-over from package 1): the previous
    // implementation reported another user's cached file as "Already
    // cached". The servable-scope rewrite guarantees that never happens.
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $playlistA = Playlist::factory()->for($ownerA)->create();
    $playlistB = Playlist::factory()->for($ownerB)->create();
    $channel = Channel::factory()->for($ownerB)->for($playlistB)->create([
        'tmdb_id' => 2021,
        'url' => 'https://example.com/describe-cross-user.mp4',
    ]);

    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '2021',
        'playlist_id' => $playlistA->id,
        'user_id' => $ownerA->id,
    ]);

    $hit = app(CachedContentDispatchService::class)->describeExisting($channel);

    expect($hit)->toBeNull();
});

it('describeExisting prefers the item playlist own row over a shared row', function () {
    // When both the item's playlist AND a sibling sharing playlist have a
    // Completed row for the fingerprint, the own-playlist row wins.
    $owner = User::factory()->create();
    $playlistA = Playlist::factory()->for($owner)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($owner)->create();
    $channel = Channel::factory()->for($owner)->for($playlistB)->create([
        'tmdb_id' => 2022,
        'url' => 'https://example.com/describe-prefer-own.mp4',
    ]);

    $shared = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '2022',
        'playlist_id' => $playlistA->id,
        'user_id' => $owner->id,
    ]);
    $own = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '2022',
        'playlist_id' => $playlistB->id,
        'user_id' => $owner->id,
    ]);

    $hit = app(CachedContentDispatchService::class)->describeExisting($channel);

    expect($hit)->not->toBeNull()
        ->and($hit->id)->toBe($own->id)
        ->and($hit->id)->not->toBe($shared->id);
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
