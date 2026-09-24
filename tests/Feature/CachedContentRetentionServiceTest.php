<?php

use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentRetentionService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory fires SyncPipelineService -> dispatch(ProcessM3uImport)
    // via a listener; Bus::fake() catches it. Retention tests never trigger it.
    Bus::fake();
});

// --- baseline: evaluate() returns the IDs of files no longer in any live fingerprint set ---

it('evaluate() returns IDs of cached files no longer in the live fingerprint set', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    // Live Channel for tmdb=550 - this fingerprint stays wanted.
    Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 550]);

    // Cached row for tmdb=550 (alive), and a stale row for tmdb=999 (orphaned).
    $alive = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);
    $orphan = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toContain($orphan->id)
        ->and($ids->all())->not->toContain($alive->id);
});

it('evaluate() returns empty when every cached file still has a live content match', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 550]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toBeEmpty();
});

// --- never_expire: Constraint 5 (PR #1500 review) ---

it('evaluate() skips files with never_expire = true even when no live content match exists', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    // No live channel for tmdb=999 - the cached row IS orphaned by
    // the live-fingerprint rule, but never_expire pins it forever.
    $pinned = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'never_expire' => true,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->not->toContain($pinned->id);
});

it('evaluate() deletes non-pinned orphans in the same scope while keeping never_expire rows', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $pinned = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'never_expire' => true,
    ]);
    $orphan = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toContain($orphan->id)
        ->and($ids->all())->not->toContain($pinned->id);
});

// --- Constraint 12: rebuild fingerprints ONCE per (user, playlist) group ---

it('evaluate() rebuilds each (user, playlist) group\'s fingerprint set ONCE - per-group fingerprint rebuild, not per-file', function () {
    // The naive implementation walks each cached file and rebuilds the
    // scope's fingerprint set on every iteration. PR #1500 shipped that
    // O(files x scope) pattern. This test verifies the
    // O(scopes + files) reduction: with N cached files in 1 group, the
    // live-fingerprint rebuild runs ONCE - counted by counting SELECTs
    // against the channels table.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    // 10 channels (each produces a fingerprint) + 10 cached files.
    for ($i = 0; $i < 10; $i++) {
        Channel::factory()->for($user)->for($playlist)->create(['tmdb_id' => 1000 + $i]);
        CachedContentFile::factory()->completed()->create([
            'content_type' => 'movie',
            'tmdb_id' => (string) (2000 + $i), // none of these match the channels - all orphan
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);
    }

    // Count SELECTs against `channels` - the live-fingerprint source
    // for movies. With per-group rebuild we expect a small constant
    // number of channel SELECTs (one for the group, plus the model's
    // own internal queries); with per-file rebuild we'd see N=10+.
    $channelSelects = 0;
    DB::listen(function ($query) use (&$channelSelects): void {
        // Postgres/SQLite all use `"channels"` for the table name.
        if (preg_match('/from "channels"/i', $query->sql)) {
            $channelSelects++;
        }
    });

    $ids = app(CachedContentRetentionService::class)->evaluate();

    // The 10 cached files all become orphan IDs (no live match for tmdb=2000..2009).
    expect($ids->count())->toBe(10);
    // Per-group rebuild: the channels SELECT runs AT MOST a small constant
    // number of times. Per-file rebuild would have run 10 times. We allow
    // up to 3 to leave headroom for model-level queries.
    expect($channelSelects)->toBeLessThanOrEqual(3, "channels SELECTs should be O(groups), got {$channelSelects} - looks like per-file rebuild");
});

it('evaluate() handles multiple (user, playlist) groups in a single pass', function () {
    // Two scopes; each must rebuild its own fingerprint set. Constraint 12
    // says O(groups + files), not O(groups * files).
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();

    Channel::factory()->for($userA)->for($playlistA)->create(['tmdb_id' => 550]);
    Channel::factory()->for($userB)->for($playlistB)->create(['tmdb_id' => 770]);

    // Each playlist has one alive + one orphan cached file.
    $aliveA = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
        'playlist_id' => $playlistA->id, 'user_id' => $userA->id,
    ]);
    $aliveB = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '770',
        'playlist_id' => $playlistB->id, 'user_id' => $userB->id,
    ]);
    $orphanA = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '1001',
        'playlist_id' => $playlistA->id, 'user_id' => $userA->id,
    ]);
    $orphanB = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '2002',
        'playlist_id' => $playlistB->id, 'user_id' => $userB->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toContain($orphanA->id)
        ->and($ids->all())->toContain($orphanB->id)
        ->and($ids->all())->not->toContain($aliveA->id)
        ->and($ids->all())->not->toContain($aliveB->id);
});

// --- Episode fingerprints: tvdb_id from parent Series (Constraint 6) ---

it('episode fingerprint matches the live membership via parent Series tmdb_id and tvdb_id', function () {
    // Without the parent-Series tvdb_id, an episode cached with tvdb_id=888
    // would never match a live fingerprint built without tvdb_id - eviction
    // would happen while the membership is still valid. This is the
    // Constraint 6 regression test (PR #1500 had this mismatch).
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 60625,
        'tvdb_id' => 888,
    ]);
    Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 5,
    ]);

    // Build a cached row using the SAME fingerprint the live-membership
    // path would produce. If the dispatcher were missing tvdb_id, the
    // cached row's fingerprint would not contain '888' and this test
    // would mark the row as orphan.
    $liveFingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'tvdb_id' => '888',
        'season_number' => 1,
        'episode_number' => 5,
    ]);

    $alive = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'tvdb_id' => '888',
        'season_number' => 1,
        'episode_number' => 5,
        'content_fingerprint' => $liveFingerprint,
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->not->toContain($alive->id);
});

// --- deleteIds: chunked delete + on-disk cleanup ---

it('deleteIds removes the rows and their on-disk files', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $row = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'file_path' => 'cache/orphan.mp4',
    ]);
    Storage::disk('cache')->put('cache/orphan.mp4', 'fake bytes');

    $count = app(CachedContentRetentionService::class)->deleteIds(collect([$row->id]));

    expect($count)->toBe(1)
        ->and(CachedContentFile::find($row->id))->toBeNull()
        ->and(Storage::disk('cache')->exists('cache/orphan.mp4'))->toBeFalse();
});

it('deleteIds returns 0 when the ID list is empty', function () {
    expect(app(CachedContentRetentionService::class)->deleteIds(collect()))
        ->toBe(0);
});

// --- PR #1524 review item 4: SQL-built "auto" set agrees with Playlist::effectiveCacheRetentionMode() ---

it('automaticPlaylistIdsQuery() and Playlist::effectiveCacheRetentionMode() agree on AUTO status for every (override, global) combination', function () {
    // Five combinations plus the null-global border. For every playlist,
    // membership in the SQL-built set must match
    // `effectiveCacheRetentionMode() === 'time-based'`.
    $user = User::factory()->create();
    $plExplicitTime = Playlist::factory()->for($user)->create(['cache_retention_mode' => 'time-based']);
    $plExplicitNever = Playlist::factory()->for($user)->create(['cache_retention_mode' => 'never-expire']);
    $plExplicitManual = Playlist::factory()->for($user)->create(['cache_retention_mode' => 'manual']);
    $plExplicitEmpty = Playlist::factory()->for($user)->create(['cache_retention_mode' => '']);
    $plNull = Playlist::factory()->for($user)->create(['cache_retention_mode' => null]);

    $allPlaylists = [$plExplicitTime, $plExplicitNever, $plExplicitManual, $plExplicitEmpty, $plNull];
    $service = app(CachedContentRetentionService::class);
    $settings = app(GeneralSettings::class);

    $assertAgreement = function () use ($service, $allPlaylists): void {
        $autoIds = $service->automaticPlaylistIdsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($allPlaylists as $p) {
            $inAuto = in_array((int) $p->id, $autoIds, true);
            $effective = $p->fresh()->effectiveCacheRetentionMode();
            $expectedAuto = $effective === 'time-based';

            expect($inAuto)->toBe(
                $expectedAuto,
                "Playlist {$p->id} (override={$p->cache_retention_mode}): SQL says ".($inAuto ? 'AUTO' : 'NOT AUTO')
                    ." but effectiveCacheRetentionMode() returned '{$effective}'",
            );
        }
    };

    // Case 1 + 3 + 4 + null-fallback: global = time-based.
    $settings->cache_retention_mode = 'time-based';
    $settings->save();
    $assertAgreement();

    // Case 2 + explicit override survives global change: global = never-expire.
    $settings->cache_retention_mode = 'never-expire';
    $settings->save();
    $assertAgreement();

    // Case 5 + null-global normalised border: global = manual, then global = null.
    $settings->cache_retention_mode = 'manual';
    $settings->save();
    $assertAgreement();

    $settings->cache_retention_mode = null;
    $settings->save();
    $assertAgreement();
});

// --- PR #1524 review item 4: per-playlist cache_retention_mode is honored ---

it('evaluate() keeps stale rows when a playlist override is set to never-expire', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => 'never-expire',
    ]);

    // Stale row that would otherwise be picked up.
    $stale = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->not->toContain($stale->id);
});

it('evaluate() keeps stale rows when a playlist override is set to manual', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => 'manual',
    ]);

    $stale = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->not->toContain($stale->id);
});

it('evaluate() keeps stale rows when NULL override and global default is never-expire', function () {
    $settings = app(GeneralSettings::class);
    $settings->cache_retention_mode = 'never-expire';
    $settings->save();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => null,
    ]);

    $stale = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->not->toContain($stale->id);
});

it('evaluate() deletes stale rows when NULL override and global default is time-based', function () {
    $settings = app(GeneralSettings::class);
    $settings->cache_retention_mode = 'time-based';
    $settings->save();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => null,
    ]);

    $stale = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toContain($stale->id);
});

it('evaluate() deletes stale rows when playlist override is time-based even with global never-expire', function () {
    $settings = app(GeneralSettings::class);
    $settings->cache_retention_mode = 'never-expire';
    $settings->save();

    $user = User::factory()->create();

    $autoPlaylist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => 'time-based',
    ]);
    $manualPlaylist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => null,
    ]);

    $autoStale = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'playlist_id' => $autoPlaylist->id,
        'user_id' => $user->id,
    ]);
    $manualStale = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'playlist_id' => $manualPlaylist->id,
        'user_id' => $user->id,
    ]);

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids->all())->toContain($autoStale->id)
        ->and($ids->all())->not->toContain($manualStale->id);
});

// --- PR #1524 review item 8: deleteIds re-checks never_expire to close the pin race ---

it('deleteIds re-checks never_expire so a row pinned between evaluate() and deleteIds() survives', function () {
    // Scenario from PR #1524 review item 8: an operator pins a row to
    // never_expire=true AFTER evaluate() has already decided to delete it.
    // deleteIds() must re-check never_expire and skip the row instead of
    // racing ahead with the DELETE. This protects user intent over the
    // classic SELECT/DELETE race window.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'cache_retention_mode' => 'time-based',
    ]);

    $row = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'never_expire' => false,
    ]);

    // simulate: evaluate decided to delete, then operator pins the row
    $row->update(['never_expire' => true]);
    $row->refresh();

    $count = app(CachedContentRetentionService::class)->deleteIds(collect([$row->id]));

    expect($count)->toBe(0)
        ->and(CachedContentFile::find($row->id))->not->toBeNull();
});

it('deleteIds skips rows whose status flipped back to Pending/Downloading between SELECT and DELETE', function () {
    // Existing re-check safety (status) - kept here so the never_expire
    // variant above is testable alongside it.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $row = CachedContentFile::factory()->completed()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $row->update(['status' => 'downloading']);
    $row->refresh();

    $count = app(CachedContentRetentionService::class)->deleteIds(collect([$row->id]));

    expect($count)->toBe(0)
        ->and(CachedContentFile::find($row->id))->not->toBeNull();
});
