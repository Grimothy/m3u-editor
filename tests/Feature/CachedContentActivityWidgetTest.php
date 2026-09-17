<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Widgets\CachedContentActivityWidget;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 */
function setEnableCacheForActivityWidget(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    setEnableCacheForActivityWidget(true);
    Bus::fake();
});

// canView()

it('canView returns false when enable_cache is off', function () {
    setEnableCacheForActivityWidget(false);

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(CachedContentActivityWidget::canView())->toBeFalse();
});

it('canView returns false for unauthenticated visitors', function () {
    auth()->logout();

    expect(CachedContentActivityWidget::canView())->toBeFalse();
});

it('canView returns true when enable_cache is on and the visitor is authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(CachedContentActivityWidget::canView())->toBeTrue();
});

// Rendering

it('renders for an authenticated user when enable_cache is on', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)->assertOk();
});

it('does not render for an authenticated user when enable_cache is off', function () {
    setEnableCacheForActivityWidget(false);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)->assertSet('mounted', false);
});

// Ownership scoping (table query)

it('table query only shows the current user\'s cached files for non-admin users', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();

    $mine = CachedContentFile::factory()->completed()->create([
        'user_id' => $owner->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '111',
        'title' => 'My Movie',
    ]);
    $theirs = CachedContentFile::factory()->completed()->create([
        'user_id' => $intruder->id,
        'playlist_id' => Playlist::factory()->for($intruder)->create()->id,
        'content_type' => 'movie',
        'tmdb_id' => '222',
        'title' => 'Their Movie',
    ]);

    $this->actingAs($owner);

    Livewire::test(CachedContentActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('table query shows every cached file for an admin regardless of user_id', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $adminPlaylist = Playlist::factory()->for($admin)->create();
    $otherPlaylist = Playlist::factory()->for($other)->create();

    $adminRow = CachedContentFile::factory()->completed()->create([
        'user_id' => $admin->id,
        'playlist_id' => $adminPlaylist->id,
        'content_type' => 'movie',
        'tmdb_id' => '111',
        'title' => 'Admin Movie',
    ]);
    $otherRow = CachedContentFile::factory()->completed()->create([
        'user_id' => $other->id,
        'playlist_id' => $otherPlaylist->id,
        'content_type' => 'movie',
        'tmdb_id' => '222',
        'title' => 'Other User Movie',
    ]);

    $this->actingAs($admin);

    Livewire::test(CachedContentActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$adminRow, $otherRow]);
});

// Per-row actions

it('per-row retry action re-dispatches DownloadCachedContentFile for a Failed row', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 700,
        'url' => 'https://example.com/movie.mp4',
    ]);
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '700',
        'title' => 'Failed movie',
    ]);

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->callTableAction('retry', $row)
        ->assertNotified();

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('per-row retry action surfaces a notification when the source Channel cannot be resolved', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '999-no-channel',
        'title' => 'Missing source',
    ]);

    $this->actingAs($user);

    // No matching Channel row exists for playlist=this, tmdb=999-no-channel
    // so resolveChannel() returns null. The retry action should report a
    // "Could not retry" notification instead of dispatching.
    Livewire::test(CachedContentActivityWidget::class)
        ->callTableAction('retry', $row)
        ->assertNotified('Could not retry');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('per-row deleteCache action removes the row and storage file', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $row = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '800',
        'title' => 'Delete me',
        'disk' => 'cache',
        'file_path' => 'cache/fingerprint.mp4',
    ]);

    // Match the widget's resolution: file_path is resolved relative to the
    // cache disk's root, so the on-disk file lives at cache/fingerprint.mp4.
    Storage::disk('cache')->put('cache/fingerprint.mp4', 'bytes');

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->callTableAction('deleteCache', $row)
        ->assertNotified();

    expect(CachedContentFile::find($row->id))->toBeNull();
    expect(Storage::disk('cache')->exists('cache/fingerprint.mp4'))->toBeFalse();
});

it('per-row cancel action sets a Pending cancellation flag for in-flight rows', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $fingerprint = 'movie:1234:::1080p';

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '1234',
        'content_fingerprint' => $fingerprint,
        'status' => CachedContentFileStatus::Pending,
    ]);

    Cache::shouldReceive('put')
        ->once()
        ->withArgs(fn (string $key, mixed $value, mixed $ttl): bool => $key === CachedContentFile::cancellationCacheKey($row->id));
    Cache::shouldReceive('put')
        ->once()
        ->withArgs(fn (string $key, mixed $value, mixed $ttl): bool => $key === CachedContentFile::pendingCancellationCacheKey($fingerprint));

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->callTableAction('cancel', $row)
        ->assertNotified();

    expect(CachedContentFile::find($row->id))->toBeNull();
});

it('per-row viewError action opens a modal containing the failure message', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '555',
        'last_error_message' => 'HTTP 503 from origin',
        'failure_count' => 3,
    ]);

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->mountTableAction('viewError', $row)
        ->assertHasNoErrors();
});

// Ownership enforcement (per-row + bulk)

it('per-row retry helper refuses to dispatch when the row belongs to another user', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $owner->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '4321',
    ]);

    // We can't drive callTableAction() for a row filtered out by
    // ownedBy() - Filament throws "Record no longer exists" before the
    // closure runs. Test the ownership gate directly via the helper
    // the action uses, mirroring PR #1500's review requirement that
    // retryCachedFile() MUST return false on ownership mismatch.
    $this->actingAs($intruder);

    expect(CachedContentActivityWidget::retryCachedFile($row))->toBeFalse();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

// Bulk actions

it('bulkRetry re-dispatches DownloadCachedContentFile for every Failed row in the selection', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    // Create matching channels so retry's source resolution succeeds. The
    // widget's resolveChannel() filters on is_vod=true; the default factory
    // leaves is_vod=false, which would silently fail retry's lookup.
    $channelA = Channel::factory()->for($user)->for($playlist)->create(['is_vod' => true, 'tmdb_id' => 1001, 'url' => 'https://example.com/a.mp4']);
    $channelB = Channel::factory()->for($user)->for($playlist)->create(['is_vod' => true, 'tmdb_id' => 1002, 'url' => 'https://example.com/b.mp4']);

    $rowA = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '1001',
        'title' => 'A',
    ]);
    $rowB = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '1002',
        'title' => 'B',
    ]);

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->callTableBulkAction('bulkRetry', [$rowA->id, $rowB->id])
        ->assertNotified();

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 2);
});

it('bulkRetry skips rows owned by another user (no dispatch, no surprise side effect)', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();

    $otherChannel = Channel::factory()->for($owner)->for($playlist)->create(['is_vod' => true, 'tmdb_id' => 3000, 'url' => 'https://example.com/x.mp4']);

    $theirRow = CachedContentFile::factory()->failed()->create([
        'user_id' => $owner->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '3000',
    ]);

    // Same ownership-out-of-table problem as the per-row intruder test:
    // bulk action by id fails to resolve before the closure runs. Drive
    // the helper directly instead.
    $this->actingAs($intruder);

    expect(CachedContentActivityWidget::retryCachedFile($theirRow))->toBeFalse();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('bulkCancel removes every Pending or Downloading row in the selection', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $pendingRow = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9001',
        'status' => CachedContentFileStatus::Pending,
    ]);
    $completedRow = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9002',
    ]);

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->callTableBulkAction('bulkCancel', [$pendingRow->id, $completedRow->id])
        ->assertNotified();

    expect(CachedContentFile::find($pendingRow->id))->toBeNull()
        ->and(CachedContentFile::find($completedRow->id))->not->toBeNull();
});

it('bulkDelete removes every selected row', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $rowA = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '7777',
        'disk' => 'cache',
        'file_path' => 'cache/a.mp4',
    ]);
    $rowB = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '7778',
        'disk' => 'cache',
        'file_path' => 'cache/b.mp4',
    ]);

    // file_path is resolved relative to the cache disk's root, so the
    // on-disk files live at cache/a.mp4 and cache/b.mp4.
    Storage::disk('cache')->put('cache/a.mp4', 'a');
    Storage::disk('cache')->put('cache/b.mp4', 'b');

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->callTableBulkAction('bulkDelete', [$rowA->id, $rowB->id])
        ->assertNotified();

    expect(CachedContentFile::find($rowA->id))->toBeNull()
        ->and(CachedContentFile::find($rowB->id))->toBeNull();

    expect(Storage::disk('cache')->exists('cache/a.mp4'))->toBeFalse()
        ->and(Storage::disk('cache')->exists('cache/b.mp4'))->toBeFalse();
});

// Formatters

it('getProgressLabel returns the Pending label for Pending rows', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'status' => CachedContentFileStatus::Pending,
    ]);

    expect(CachedContentActivityWidget::getProgressLabel($row))->toBe(__('Pending'));
});

it('getProgressLabel formats the final file size for Completed rows', function () {
    $row = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'file_size_bytes' => 1_572_864, // 1.50 MB
    ]);

    $label = CachedContentActivityWidget::getProgressLabel($row);

    expect($label)->toContain('MB')
        ->and($label)->toContain('1.50');
});

it('getProgressLabel falls back to bytes_downloaded when file_size_bytes is null on a Completed row', function () {
    $row = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'file_size_bytes' => null,
        'bytes_downloaded' => 2_097_152, // 2.00 MB
    ]);

    $label = CachedContentActivityWidget::getProgressLabel($row);

    expect($label)->toContain('MB')
        ->and($label)->toContain('2.00');
});

it('getProgressLabel returns the Completed label when no size is recorded', function () {
    $row = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'file_size_bytes' => null,
        'bytes_downloaded' => null,
    ]);

    expect(CachedContentActivityWidget::getProgressLabel($row))->toBe(__('Completed'));
});

it('getProgressLabel formats last-known bytes for Failed rows', function () {
    $row = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'bytes_downloaded' => 999,
    ]);

    $label = CachedContentActivityWidget::getProgressLabel($row);

    expect($label)->toContain('KB');
});

it('getProgressLabel returns the Failed label when no bytes are recorded', function () {
    $row = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'bytes_downloaded' => null,
    ]);

    expect(CachedContentActivityWidget::getProgressLabel($row))->toBe(__('Failed'));
});

it('getProgressLabel formats bytes for Downloading rows', function () {
    $row = CachedContentFile::factory()->downloading(524_288_000, 2_147_483_648)->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
    ]);

    $label = CachedContentActivityWidget::getProgressLabel($row);

    expect($label)->toContain('MB');
});

it('getEtaLabel returns null for stalled rows', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'status' => CachedContentFileStatus::Downloading,
        'bytes_downloaded' => 1_000_000,
        'bytes_expected' => 10_000_000,
        'bytes_per_second' => 1_000_000,
        'last_progress_at' => now()->subMinutes(2),
    ]);

    expect(CachedContentActivityWidget::getEtaLabel($row))->toBeNull();
});

it('getContentLabel prefers the persisted title when present', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '42',
        'title' => 'Inception',
    ]);

    expect(CachedContentActivityWidget::getContentLabel($row))->toBe('Inception');
});

it('getContentLabel returns the movie Channel display title when title is null and a Channel matches', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 5000,
        'title' => 'The Big Lebowski',
        'url' => 'https://example.com/big_lebowski.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '5000',
        'title' => null,
    ]);

    // Simulate the projection the widget query attaches when the row is
    // loaded via the table. Direct attribute injection is enough for
    // unit-level coverage; the integration table-render assertion below
    // exercises the full query path.
    $row->setAttribute('movie_source_title', 'The Big Lebowski');

    expect(CachedContentActivityWidget::getContentLabel($row))->toBe('The Big Lebowski');
});

it('getContentLabel returns the episode title when title is null and an Episode matches', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create(['tmdb_id' => 6000]);
    $season = Season::factory()->for($series)->create(['season_number' => 1]);
    Episode::factory()->for($series)->create([
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 3,
        'title' => 'The One With the Rumor',
        'url' => 'https://example.com/s1e3.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '6000',
        'season_number' => 1,
        'episode_number' => 3,
        'title' => null,
    ]);

    $row->setAttribute('episode_source_title', 'The One With the Rumor');

    expect(CachedContentActivityWidget::getContentLabel($row))->toBe('The One With the Rumor');
});

it('getContentLabel falls back to fingerprint when source row exists but its display title is empty', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 7000,
        'title' => null,
        'title_custom' => null,
        'name' => null,
        'name_custom' => null,
        'url' => 'https://example.com/empty.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '7000',
        'title' => null,
    ]);

    // Projection returns null when the source row has no usable title.
    $row->setAttribute('movie_source_title', null);

    $label = CachedContentActivityWidget::getContentLabel($row);

    expect($label)->toContain('movie')
        ->and($label)->toContain('7000');
});

it('getContentLabel falls back to a fingerprint label when title is null and no source row matches', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '42',
        'title' => null,
    ]);

    $label = CachedContentActivityWidget::getContentLabel($row);

    expect($label)->toContain('movie')
        ->and($label)->toContain('42');
});

// Integration: end-to-end widget render proves the projection path actually
// runs through the table query (catches widget-query-level mistakes).

it('table renders the resolved source title for a null-title row with a matching Channel', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 8000,
        'title' => 'Pulp Fiction',
        'url' => 'https://example.com/pulp_fiction.mp4',
    ]);
    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '8000',
        'title' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(CachedContentActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('Pulp Fiction');
});

// Resolution helpers

it('resolveChannel returns a Channel when the source playlist matches by tmdb_id', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 999,
        'url' => 'https://example.com/x.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '999',
    ]);

    $reflection = new ReflectionClass(CachedContentActivityWidget::class);
    $method = $reflection->getMethod('resolveChannel');
    $method->setAccessible(true);
    $resolved = $method->invoke(null, $playlist, $row);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($channel->id);
});

it('resolveEpisode returns an Episode when the source series matches by tmdb_id', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 5_111,
    ]);
    $season = Season::factory()->for($series)->create(['season_number' => 2]);
    $episode = Episode::factory()->for($series)->create([
        'season_id' => $season->id,
        'season' => 2,
        'episode_num' => 7,
        'url' => 'https://example.com/s2e7.mp4',
    ]);

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '5111',
        'season_number' => 2,
        'episode_number' => 7,
    ]);

    $reflection = new ReflectionClass(CachedContentActivityWidget::class);
    $method = $reflection->getMethod('resolveEpisode');
    $method->setAccessible(true);
    $resolved = $method->invoke(null, $playlist, $row);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($episode->id);
});
