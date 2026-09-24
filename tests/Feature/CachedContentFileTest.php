<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory()->create() fires PlaylistListener -> SyncPipelineService
    // -> dispatch(ProcessM3uImport). Bus::fake() catches that; ownership tests
    // never trigger the listener.
    Bus::fake();
});

// fingerprintFor() - locked 5-rule normalization contract:
//   1. content_type lowercased+trimmed, REQUIRED (throws if empty)
//   2. quality lowercased+trimmed
//   3. tmdb_id/tvdb_id coerced to string (null/missing -> '')
//   4. season_number/episode_number (int)-cast then stringified (no leading zeros)
//   5. Separator: ':'
// Output format: content_type:tmdb_id:tvdb_id:season_number:episode_number:quality

it('builds a deterministic fingerprint for a movie', function () {
    // 6 fields separated by 5 colons; the 4 empty middle fields contribute
    // 4 empty positions between tmdb_id and quality.
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]))->toBe('movie:550::::1080p');
});

it('builds a deterministic fingerprint for an episode with season and episode', function () {
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'season_number' => 1,
        'episode_number' => 5,
        'quality' => '4K',
    ]))->toBe('episode:60625::1:5:4k');
});

it('renders null/missing tvdb_id as empty in the fingerprint', function () {
    // Explicit null
    $withNull = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'tvdb_id' => null,
    ]);
    // Key absent
    $absent = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($withNull)->toBe('movie:550::::')
        ->and($absent)->toBe('movie:550::::');
});

it('lowercases and trims quality values', function () {
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '  4K  ',
    ]))->toBe('movie:550::::4k');
});

it('lowercases and trims content_type', function () {
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => ' MOVIE ',
        'tmdb_id' => '550',
    ]);

    expect($fingerprint)->toStartWith('movie:550');
});

it('throws InvalidArgumentException when content_type is an empty string', function () {
    expect(fn () => CachedContentFile::fingerprintFor(['content_type' => '']))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException when content_type key is missing', function () {
    expect(fn () => CachedContentFile::fingerprintFor(['tmdb_id' => '550']))
        ->toThrow(InvalidArgumentException::class);
});

it('casts season_number as int then string (no leading zeros)', function () {
    // Without leading-zero padding - season 7 becomes '7', not '07'.
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'season_number' => 7,
    ]))->toBe('movie:::7::');
});

it('coerces integer tmdb_id to string in the fingerprint', function () {
    // Even if the caller passes an int (mirroring episodes.tmdb_id which is integer-typed),
    // the fingerprint must be string-stable.
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => 123,
    ]))->toBe('movie:123::::');
});

it('produces the same fingerprint regardless of field insertion order', function () {
    $ordered = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);
    $reversed = CachedContentFile::fingerprintFor([
        'quality' => '1080p',
        'tmdb_id' => '550',
        'content_type' => 'movie',
    ]);

    expect($ordered)->toBe($reversed);
});

// Creating event - empty content_type rejection

it('rejects empty content_type in the creating event', function () {
    expect(fn () => CachedContentFile::factory()->create(['content_type' => '']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects whitespace-only content_type in the creating event (normalized to empty)', function () {
    expect(fn () => CachedContentFile::factory()->create(['content_type' => '   ']))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts factory defaults without an explicit content_type', function () {
    $file = CachedContentFile::factory()->create();

    expect($file->content_type)->toBe('movie');
});

// Creating event - fingerprint auto-derivation

it('auto-derives content_fingerprint from parts in the creating event', function () {
    $file = CachedContentFile::factory()->forMovie('550')->create();

    expect($file->content_fingerprint)->toBe('movie:550::::1080p');
});

it('auto-generates uuid in the creating event when not supplied', function () {
    // PR B's download service will call CachedContentFile::create([...]) /
    // firstOrCreate(...) without a pre-supplied uuid. The `creating` boot
    // must populate it so the NOT NULL uuid column doesn't reject the row
    // (mirrors DvrRecording's boot convention at app/Models/DvrRecording.php:56-57).
    // Direct ::create() bypasses the factory's definition(), so this exercises
    // the production-style path. uuid is intentionally NOT in $fillable, so
    // the mass-assignment drop doesn't matter here.
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($file->uuid)->not->toBeEmpty()
        ->and($file->uuid)->toBeString();
});

it('auto-derives episode fingerprint with season and episode', function () {
    $file = CachedContentFile::factory()->forEpisode('60625', 1, 5)->create();

    expect($file->content_fingerprint)->toBe('episode:60625::1:5:1080p');
});

// hasFilePath()

it('hasFilePath returns false for pending files with no file_path', function () {
    $file = CachedContentFile::factory()->create(['file_path' => null]);

    expect($file->hasFilePath())->toBeFalse();
});

it('hasFilePath returns true for completed files with file_path', function () {
    $file = CachedContentFile::factory()->completed()->create();

    expect($file->hasFilePath())->toBeTrue();
});

it('hasFilePath returns false for completed files with empty file_path', function () {
    $file = CachedContentFile::factory()->completed()->create(['file_path' => '']);

    expect($file->hasFilePath())->toBeFalse();
});

it('hasFilePath returns false for failed files even with file_path', function () {
    // hasFilePath requires status === Completed, regardless of file_path.
    $file = CachedContentFile::factory()->failed()->create(['file_path' => 'cache/foo.mp4']);

    expect($file->hasFilePath())->toBeFalse();
});

// resolveStorageDisk()

it('returns the file disk when set', function () {
    $file = CachedContentFile::factory()->completed()->create(['disk' => 's3']);

    expect($file->resolveStorageDisk())->toBe('s3');
});

it('falls back to config(filesystems.default) when disk is null', function () {
    $file = CachedContentFile::factory()->create(['disk' => null]);

    expect($file->resolveStorageDisk())->toBe(config('filesystems.default'));
});

// resolveMimeType()

it('returns video/mp4 for .mp4 files', function () {
    $file = CachedContentFile::factory()->create(['file_path' => 'cache/foo.mp4']);

    expect($file->resolveMimeType())->toBe('video/mp4');
});

it('returns video/x-matroska for .mkv files', function () {
    $file = CachedContentFile::factory()->create(['file_path' => 'cache/foo.mkv']);

    expect($file->resolveMimeType())->toBe('video/x-matroska');
});

it('returns video/mp2t as the default for unknown extensions', function () {
    $file = CachedContentFile::factory()->create(['file_path' => 'cache/foo.ts']);

    expect($file->resolveMimeType())->toBe('video/mp2t');
});

it('returns video/mp2t as the default for null file_path', function () {
    $file = CachedContentFile::factory()->create(['file_path' => null]);

    expect($file->resolveMimeType())->toBe('video/mp2t');
});

// Uniqueness - DB-level guard on content_fingerprint (no service-layer firstOrCreate in Phase 1).
// Phase 2's download service will use firstOrCreate against this column.

it('rejects a second CachedContentFile with the same (content_fingerprint, playlist_id)', function () {
    // PR #1524 review item 1: uniqueness is per (content_fingerprint,
    // playlist_id), not global. Two rows with the same fingerprint for
    // the SAME playlist must still reject the second insert (the
    // "single row per playlist per fingerprint" invariant from PR #1500).
    $playlist = Playlist::factory()->create();
    CachedContentFile::factory()->forMovie('550')->create(['playlist_id' => $playlist->id]);

    expect(fn () => CachedContentFile::factory()->forMovie('550')->create(['playlist_id' => $playlist->id]))
        ->toThrow(QueryException::class);
});

it('allows two CachedContentFiles with the same fingerprint on different playlists', function () {
    // PR #1524 review item 1: different playlists are entitled to
    // their own copy of the same content. Two different playlists can
    // both have a row for fingerprint `movie:550::::1080p` - the
    // composite unique covers this case.
    $playlistA = Playlist::factory()->create();
    $playlistB = Playlist::factory()->create();
    CachedContentFile::factory()->forMovie('550')->create(['playlist_id' => $playlistA->id]);
    CachedContentFile::factory()->forMovie('550')->create(['playlist_id' => $playlistB->id]);

    expect(CachedContentFile::count())->toBe(2);
});

// --- fingerprintFor(): local_key (PR #1524 review item 6) ---
//
// The local_key disambiguator kicks in ONLY when both tmdb_id and
// tvdb_id are empty (no external identity to match on). Matched
// fingerprints stay byte-identical to the prior contract so the
// existing test expectations above all still hold.

it('appends local_key to fingerprint when both tmdb_id and tvdb_id are empty', function () {
    $fp = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'local_key' => 'ch42',
    ]);

    expect($fp)->toBe('movie::::::ch42');
});

it('does NOT append local_key when tmdb_id is set (matched content fingerprint stays unchanged)', function () {
    // The local_key segment must only appear for UNmatched content -
    // matched fingerprints must stay byte-identical to the prior
    // contract or existing rows / cache files would not match.
    $fp = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'local_key' => 'ch42',
    ]);

    expect($fp)->toBe('movie:550::::1080p');
});

it('does NOT append local_key when tvdb_id alone is set', function () {
    $fp = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tvdb_id' => '99',
        'quality' => '1080p',
        'local_key' => 'ch42',
    ]);

    expect($fp)->toBe('movie::99:::1080p');
});

// Enum sanity - also covered by the boot tests above; one explicit test for the enum mapping.

it('maps content_fingerprint status to the CachedContentFileStatus enum', function () {
    $pending = CachedContentFile::factory()->create();
    $completed = CachedContentFile::factory()->completed()->create();
    $failed = CachedContentFile::factory()->failed()->create();

    expect($pending->status)->toBe(CachedContentFileStatus::Pending)
        ->and($completed->status)->toBe(CachedContentFileStatus::Completed)
        ->and($failed->status)->toBe(CachedContentFileStatus::Failed);
});

// --- scopeOwnedBy() + scopeOwnedByPlaylist() - PR A ownership scopes ---
//
// Ownership now lives directly on cached_content_files (user_id,
// playlist_id) - no pivot traversal. These two scopes are the only
// filter call sites PR D's UI uses for per-user visibility and PR B's
// dispatcher uses for the source-owns-file sharing rule.

/**
 * Seed two users each with their own playlist and one cached file.
 * Returned as [$userA, $userB, $playlistA, $playlistB, $fileA, $fileB].
 *
 * @return array{0: User, 1: User, 2: Playlist, 3: Playlist, 4: CachedContentFile, 5: CachedContentFile}
 */
function seedTwoUsersWithCacheFiles(): array
{
    $userA = User::factory()->create(['is_admin' => false]);
    $userB = User::factory()->create(['is_admin' => false]);
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    $fileA = CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);
    $fileB = CachedContentFile::factory()->completed()->create([
        'user_id' => $userB->id,
        'playlist_id' => $playlistB->id,
        'content_type' => 'movie',
        'tmdb_id' => '551',
    ]);

    return [$userA, $userB, $playlistA, $playlistB, $fileA, $fileB];
}

it('scopeOwnedBy() returns only rows for the given user_id', function () {
    [$userA, $userB, , , $fileA, $fileB] = seedTwoUsersWithCacheFiles();

    $idsA = CachedContentFile::query()->ownedBy($userA->id)->pluck('id')->all();
    $idsB = CachedContentFile::query()->ownedBy($userB->id)->pluck('id')->all();

    expect($idsA)->toContain($fileA->id)
        ->and($idsA)->not->toContain($fileB->id)
        ->and($idsB)->toContain($fileB->id)
        ->and($idsB)->not->toContain($fileA->id);
});

it('scopeOwnedBy() returns no rows for a user who owns nothing', function () {
    seedTwoUsersWithCacheFiles();
    $stranger = User::factory()->create(['is_admin' => false]);

    $count = CachedContentFile::query()->ownedBy($stranger->id)->count();

    expect($count)->toBe(0);
});

it('scopeOwnedBy() excludes NULL user_id rows (orphan-safe)', function () {
    // Pre-PR-A historical rows have user_id IS NULL and must not leak
    // through a user-scoped query - they have no claimable owner.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'user_id' => null,
        'playlist_id' => null,
    ]);
    $user = User::factory()->create();

    $count = CachedContentFile::query()->ownedBy($user->id)->count();

    expect($count)->toBe(0)
        ->and(CachedContentFile::count())->toBe(1);  // the orphan row still exists
});

it('scopeOwnedByPlaylist() returns only rows for the given playlist_id', function () {
    [, , $playlistA, $playlistB, $fileA, $fileB] = seedTwoUsersWithCacheFiles();

    $idsA = CachedContentFile::query()->ownedByPlaylist($playlistA->id)->pluck('id')->all();
    $idsB = CachedContentFile::query()->ownedByPlaylist($playlistB->id)->pluck('id')->all();

    expect($idsA)->toContain($fileA->id)
        ->and($idsA)->not->toContain($fileB->id)
        ->and($idsB)->toContain($fileB->id)
        ->and($idsB)->not->toContain($fileA->id);
});

it('scopeOwnedByPlaylist() excludes NULL playlist_id rows', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'playlist_id' => null,
    ]);
    $playlist = Playlist::factory()->create();

    $count = CachedContentFile::query()->ownedByPlaylist($playlist->id)->count();

    expect($count)->toBe(0);
});

// --- scopeServableForPlaylist() - PR #1524 review items 1 + 2 ---
//
// The scope is the single source of truth for "is this row servable
// for that playlist" used by the dispatcher's existing-row short-circuit,
// the cache-hit gate, the stream controller's auth check, and
// describeExisting(). Its two branches are: the playlist's own row, and
// any sibling row owned by the same user with sharing enabled.

it('scopeServableForPlaylist() returns the playlist own row', function () {
    [, , $playlistA, $playlistB, $fileA, $fileB] = seedTwoUsersWithCacheFiles();

    $ids = CachedContentFile::query()->servableForPlaylist($playlistA)->pluck('id')->all();

    expect($ids)->toContain($fileA->id)
        ->and($ids)->not->toContain($fileB->id);
});

it('scopeServableForPlaylist() includes sharing rows from sibling playlists owned by the same user', function () {
    // Two playlists owned by user A; playlistA has sharing ON, playlistB
    // is the requester. The scope must include the file owned by
    // playlistA (sharing) AND any file owned by playlistB itself.
    $userA = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($userA)->create();
    $shared = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '777',
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
    ]);
    $own = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'user_id' => $userA->id,
        'playlist_id' => $playlistB->id,
    ]);

    $ids = CachedContentFile::query()->servableForPlaylist($playlistB)->pluck('id')->all();

    expect($ids)->toContain($shared->id)
        ->and($ids)->toContain($own->id);
});

it('scopeServableForPlaylist() does NOT include sibling playlists with sharing OFF', function () {
    // Sharing OFF breaks the cross-playlist branch - only the
    // requester's own row remains servable.
    $userA = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create(['share_cache_across_playlists' => false]);
    $playlistB = Playlist::factory()->for($userA)->create();
    $shared = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '777',
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
    ]);

    $ids = CachedContentFile::query()->servableForPlaylist($playlistB)->pluck('id')->all();

    expect($ids)->not->toContain($shared->id);
});

it('scopeServableForPlaylist() never includes rows from a different user', function () {
    // Cross-USER sharing is forbidden. playlistB is owned by userB;
    // the row belongs to userA - it must NOT leak through.
    [, $userB, $playlistA, , $fileA] = seedTwoUsersWithCacheFiles();
    $playlistB = Playlist::factory()->for($userB)->create();

    $ids = CachedContentFile::query()->servableForPlaylist($playlistB)->pluck('id')->all();

    expect($ids)->not->toContain($fileA->id);
});

it('scopeServableForPlaylist() accepts an int and resolves the owner via a nested subquery', function () {
    // The int overload is the hot path: `Channel::isCached()` /
    // `Episode::isCached()` pass `(int) $this->playlist_id` so they do
    // NOT lazy-load the Playlist model. The scope resolves
    // `playlists.user_id` server-side via a nested scalar subquery.
    [, , $playlistA, $playlistB, $fileA] = seedTwoUsersWithCacheFiles();

    // No playlist model loaded - the scope must work from the int alone.
    $ids = CachedContentFile::query()->servableForPlaylist((int) $playlistA->id)->pluck('id')->all();

    expect($ids)->toContain($fileA->id);

    // Sanity: an unknown int id returns no rows but does not throw.
    $ids = CachedContentFile::query()->servableForPlaylist(999999)->pluck('id')->all();
    expect($ids)->toBe([]);

    // Cross-user is still blocked when called via the int path.
    $ids = CachedContentFile::query()->servableForPlaylist((int) $playlistB->id)->pluck('id')->all();
    expect($ids)->not->toContain($fileA->id);
});

// --- playlist() relation ---

it('playlist() resolves to the source playlist', function () {
    [, , $playlistA, , $fileA] = seedTwoUsersWithCacheFiles();

    expect($fileA->playlist)->not->toBeNull()
        ->and($fileA->playlist->is($playlistA))->toBeTrue();
});

it('playlist() returns null when playlist_id is null', function () {
    $file = CachedContentFile::factory()->create(['playlist_id' => null, 'user_id' => null]);

    expect($file->playlist)->toBeNull();
});

// --- $fillable regression: last_error_message persists mass-assigned writes ---
//
// PR #1500 shipped this column in schema but NOT in $fillable, so
// DownloadCachedContentFile::markFailed() silently dropped the write and
// the "View error" modal always showed "No error message recorded." The
// fix in PR A adds it to $fillable; this test would have caught the
// original bug.

it('last_error_message survives mass-assigned update()', function () {
    $file = CachedContentFile::factory()->create();

    $file->update(['last_error_message' => 'connection refused by upstream proxy']);

    expect($file->fresh()->last_error_message)->toBe('connection refused by upstream proxy');
});

it('user_id and playlist_id survive mass-assigned update()', function () {
    $file = CachedContentFile::factory()->create(['user_id' => null, 'playlist_id' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $file->update(['user_id' => $user->id, 'playlist_id' => $playlist->id]);

    $fresh = $file->fresh();
    expect($fresh->user_id)->toBe($user->id)
        ->and($fresh->playlist_id)->toBe($playlist->id);
});

// --- factory default-stamps user_id + playlist_id (PR B dispatcher relies on this) ---

it('factory definition() defaults user_id and playlist_id', function () {
    $file = CachedContentFile::factory()->create();

    expect($file->user_id)->not->toBeNull()
        ->and($file->playlist_id)->not->toBeNull();
});
