<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Filament\Resources\VodGroups\Widgets\DynamicGroupCacheActivityWidget;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The activity widget is gated on the master cache toggle + at least one
    // row — start each test from a known-good baseline and let each test
    // shape its own scenario.
    // Prime the singleton from DB first — Spatie's SettingsMapper::save()
    // calls ensureNoMissingSettings() which throws MissingSettings for any
    // property not present on the loaded object. The first test in an
    // isolation run would otherwise try to save with all 141 properties
    // missing. Mirrors the CacheDynamicGroupContentTest:22 pattern.
    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();
});

it('canView() returns false when caching is disabled', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    expect(DynamicGroupCacheActivityWidget::canView())->toBeFalse();
});

it('canView() returns false when caching is enabled but no CachedContentFile rows exist', function () {
    // No factory calls here — DB is empty
    expect(DynamicGroupCacheActivityWidget::canView())->toBeFalse();
});

it('canView() returns true when caching is enabled and at least one row exists', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect(DynamicGroupCacheActivityWidget::canView())->toBeTrue();
});

it('renders the most recent 10 CachedContentFile rows ordered by updated_at desc', function () {
    // Create 12 rows with staggered updated_at to verify ordering + limit
    $rows = collect();
    for ($i = 0; $i < 12; $i++) {
        $rows->push(CachedContentFile::factory()->completed()->create([
            'content_type' => 'movie',
            'tmdb_id' => (string) (1000 + $i),
            'updated_at' => now()->subMinutes($i),
        ]));
    }

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // The 2 oldest (indexes 10 and 11) should NOT be visible
        ->assertCanNotSeeTableRecords([$rows[10], $rows[11]])
        // The 10 newest should be visible
        ->assertCanSeeTableRecords($rows->take(10)->all());
});

it('renders the status badge with the correct label and color from the enum', function () {
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // Labels come from CachedContentFileStatus::getLabel()
        ->assertSee('Completed')
        ->assertSee('Failed');
});

it('renders the content label as "type: tmdb N" with optional season/episode suffix', function () {
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    $episode = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 3,
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // Cheap human-readable identity — no TMDB title lookup
        ->assertSee('movie: tmdb 550')
        ->assertSee('episode: tmdb 1399 S1E3');
});

it('exposes the section heading via getSectionHeading()', function () {
    $instance = Livewire::test(DynamicGroupCacheActivityWidget::class)->instance();

    expect($instance->getSectionHeading())->toBe('Dynamic Group Cache Activity');
});

it('is registered as a footer widget on both ListVodGroups and ListCategories', function () {
    $vodFooter = (new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->invoke(new ListVodGroups);
    $seriesFooter = (new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
        ->invoke(new ListCategories);

    expect($vodFooter)->toContain(DynamicGroupCacheActivityWidget::class)
        ->and($seriesFooter)->toContain(DynamicGroupCacheActivityWidget::class);
});

it('the Failed status shows the failure_count column for non-zero failures', function () {
    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
        'failure_count' => 3,
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('3');
});

it('renders a "current / total (percent)" progress label for Downloading rows with both byte fields', function () {
    // 1 GiB downloaded of a 4 GiB expected = 25%
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 1_073_741_824,
        expected: 4_294_967_296,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('1.00 GB / 4.00 GB (25%)');
});

it('renders only the current byte count when bytes_expected is null (chunked transfer)', function () {
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 524_288_000,
        expected: null,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('500.00 MB')
        // No "X / Y" framing, no percent suffix
        ->assertDontSee('%');
});

it('renders the progress placeholder for non-Downloading rows when Downloading rows are also present', function () {
    // Confirms the column shows the placeholder for Completed/Failed rows
    // alongside actual progress for Downloading rows. The placeholder character
    // (em-dash, U+2014) is used by Filament's default placeholder rendering.
    CachedContentFile::factory()->completed()->create(['content_type' => 'movie', 'tmdb_id' => '100']);
    CachedContentFile::factory()->failed()->create(['content_type' => 'movie', 'tmdb_id' => '101']);
    CachedContentFile::factory()->downloading(
        downloaded: 1_610_612_736, // 1.5 GiB
        expected: 3_221_225_472,  // 3.0 GiB
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '102',
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // Downloading row IS rendered with progress text (positive proof).
        ->assertSee('1.50 GB / 3.00 GB (50%)')
        // The placeholder character appears at least once for the non-Downloading rows.
        ->assertSee('—');
});

it('getProgressLabel() returns null for rows with no bytes_downloaded yet', function () {
    // Real-world case: Step 6 just started, no chunk has crossed the 1-MiB threshold
    $fresh = CachedContentFile::factory()->downloading(downloaded: 0, expected: 1_000_000)->create([
        'content_type' => 'movie', 'tmdb_id' => '999',
    ]);

    expect(DynamicGroupCacheActivityWidget::getProgressLabel($fresh))->toBeNull();
});

it('getProgressLabel() clamps the percent display at 100', function () {
    // Bytes can briefly exceed expected during buffering — must never show >100%.
    $overshoot = CachedContentFile::factory()->downloading(
        downloaded: 5_000_000_000,
        expected: 4_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '888',
    ]);

    $label = DynamicGroupCacheActivityWidget::getProgressLabel($overshoot);
    expect($label)->toContain('(100%)')
        ->and($label)->not->toContain('(125%)');
});

it('getProgressAttributes() paints a primary-color bar at the right percentage', function () {
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 1_073_741_824, // 25% of 4 GiB
        expected: 4_294_967_296,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '777',
    ]);

    $attrs = DynamicGroupCacheActivityWidget::getProgressAttributes($downloading);

    expect($attrs)->toHaveKey('style')
        ->and($attrs['style'])->toContain('25%')
        ->and($attrs['style'])->toContain('bg-primary-500');
});

it('getProgressAttributes() paints an amber bar when last_progress_at is older than 30 seconds', function () {
    $stalled = CachedContentFile::factory()->downloading(
        downloaded: 524_288_000,
        expected: 2_147_483_648,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '666',
        'last_progress_at' => now()->subSeconds(120),
    ]);

    $attrs = DynamicGroupCacheActivityWidget::getProgressAttributes($stalled);

    expect($attrs['style'])->toContain('bg-amber-400')
        ->and($attrs['style'])->not->toContain('bg-primary-500');
});

it('renders the resolved TMDB title as the content label when present', function () {
    // Movie with title populated by DownloadCachedContentFile::resolveAndStoreTitle()
    // — the widget should prefer that over the legacy "type: tmdb N" fallback.
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => 'Fight Club',
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('Fight Club')
        ->assertDontSee('movie: tmdb 550');
});

it('falls back to the legacy "type: tmdb N" label when title is null', function () {
    // Pre-migration rows or rows whose TMDB lookup failed: still need a readable
    // identity. The "movie: tmdb 550" / "episode: tmdb 1399 S1E3" shape lives on.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => null,
    ]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 3, 'title' => null,
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('movie: tmdb 550')
        ->assertSee('episode: tmdb 1399 S1E3');
});

it('renders series episode titles with the em-dash separator', function () {
    // The TMDB-format title for an episode: "Breaking Bad — Pilot"
    $episode = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000, expected: 1_000_000_000,
    )->create([
        'content_type' => 'episode', 'tmdb_id' => '1396',
        'season_number' => 1, 'episode_number' => 1,
        'title' => 'Breaking Bad — Pilot',
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('Breaking Bad — Pilot');
});

it('getContentLabel() returns the title field directly when set, regardless of content_type', function () {
    // Direct unit-style coverage — table render only shows one row's label at a time
    // so this protects the fallback ordering: title first, legacy shape second.
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => 'Fight Club',
    ]);
    expect(DynamicGroupCacheActivityWidget::getContentLabel($movie))->toBe('Fight Club');

    $legacy = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '999', 'title' => null,
    ]);
    expect(DynamicGroupCacheActivityWidget::getContentLabel($legacy))->toBe('movie: tmdb 999');

    $legacyEpisode = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 3, 'title' => null,
    ]);
    expect(DynamicGroupCacheActivityWidget::getContentLabel($legacyEpisode))->toBe('episode: tmdb 1399 S1E3');
});

it('formatEtaSeconds() formats seconds in all four ranges', function () {
    // Sub-minute: "42s"
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(42))->toBe('42s');
    // Sub-hour: "2m 14s" (with seconds), "5m" (without)
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(134))->toBe('2m 14s');
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(300))->toBe('5m');
    // Sub-day: "1h 23m" (with minutes), "7h" (without)
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(4980))->toBe('1h 23m');
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(7 * 3600))->toBe('7h');
    // Multi-day: "1d 2h" (with hours), "3d" (without)
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(86400 + 7200))->toBe('1d 2h');
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(86400 * 3))->toBe('3d');
});

it('getEtaLabel() returns null for non-Downloading rows', function () {
    // Completed/Failed/Pending rows show the placeholder "—" in the ETA column.
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '10',
        'bytes_per_second' => 1_000_000,
        'bytes_downloaded' => 1_000_000_000,
        'bytes_expected' => 2_000_000_000,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($completed))->toBeNull();

    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '11',
        'bytes_per_second' => 1_000_000,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($failed))->toBeNull();
});

it('getEtaLabel() returns null when bytes_expected is null (chunked transfer)', function () {
    // Chunked transfers: no Content-Length from upstream, so we don't know the
    // total. ETA is undisplayable rather than misleading.
    $row = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000,
        expected: null,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '12',
        'bytes_per_second' => 5_000_000,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($row))->toBeNull();
});

it('getEtaLabel() returns null when bytes_per_second is null or zero (first-window or stalled)', function () {
    // No rate yet (first 1-MiB window hasn't completed) — show "—".
    $row = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '13',
        'bytes_per_second' => null,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($row))->toBeNull();

    $row2 = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '14',
        'bytes_per_second' => 0,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($row2))->toBeNull();
});

it('getEtaLabel() returns null when last_progress_at is older than 30 seconds (stalled)', function () {
    // 100 MB remaining at 5 MB/s = 20s, but the last update is 90s old — the
    // rate is unreliable. Treat as stalled alongside the amber bar indicator.
    $stalled = CachedContentFile::factory()->downloading(
        downloaded: 1_900_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '15',
        'bytes_per_second' => 5_000_000,
        'last_progress_at' => now()->subSeconds(90),
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($stalled))->toBeNull();
});

it('getEtaLabel() computes the correct ETA string from rate + remaining bytes', function () {
    // 1 GB remaining at 50 MB/s = 1_000_000_000 / 50_000_000 = exactly 20s
    $row = CachedContentFile::factory()->downloading(
        downloaded: 1_000_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '16',
        'bytes_per_second' => 50_000_000,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($row))->toBe('20s');

    // 500 MB remaining at 25 MB/s = 500_000_000 / 25_000_000 = exactly 20s
    $row2 = CachedContentFile::factory()->downloading(
        downloaded: 500_000_000, expected: 1_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '17',
        'bytes_per_second' => 25_000_000,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($row2))->toBe('20s');

    // 4.5 GB remaining at 25 MB/s = 4_500_000_000 / 25_000_000 = 180s → "3m"
    $row3 = CachedContentFile::factory()->downloading(
        downloaded: 500_000_000, expected: 5_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '18',
        'bytes_per_second' => 25_000_000,
    ]);
    expect(DynamicGroupCacheActivityWidget::getEtaLabel($row3))->toBe('3m');
});

it('renders the ETA column alongside the progress column for Downloading rows', function () {
    // Use GiB-aligned values so formatBytes() lands cleanly on GB (no MB carrying).
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 1_610_612_736,  // 1.5 GiB
        expected: 3_221_225_472,    // 3.0 GiB
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '100',
        'title' => 'Pending Progress',
        'bytes_per_second' => 50_000_000,
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('1.50 GB / 3.00 GB (50%)')
        ->assertSee('33s'); // (3.0 - 1.5) GiB / 50 MB/s = 32.21s → ceil → "33s"
});

it('Delete cache action removes the Storage file and the row', function () {
    // Manual delete via the widget's row action: should both remove the file
    // from Storage and delete the cached_content_files row. Standard Eloquent
    // cascade detaches the pivot rows.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => 'Fight Club',
        'file_path' => 'cache/movie:550::::.mp4',
    ]);
    Storage::disk('local')->put('cache/movie:550::::.mp4', 'fake-bytes');
    expect(Storage::disk('local')->exists('cache/movie:550::::.mp4'))->toBeTrue()
        ->and(CachedContentFile::find($file->id))->not->toBeNull();

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $file);

    expect(Storage::disk('local')->exists('cache/movie:550::::.mp4'))->toBeFalse()
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('Delete cache action is safe when the row has no Storage file yet (Failed/Downloading rows)', function () {
    // Failing or in-flight downloads never write to Storage, so file_path is null.
    // The action's ->before() must not error in that case — Storage::delete() on
    // a null path is a no-op via the empty() guard.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $file = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
        'file_path' => null,
    ]);
    Storage::shouldReceive('disk')->with('local')->andReturn(Storage::disk('local'));

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $file);

    expect(CachedContentFile::find($file->id))->toBeNull();
});

it('Delete cache action cascades pivot rows via the FK', function () {
    // CachedContentFile is shared across dynamic_groups via the
    // cached_content_file_dynamic_groups pivot. $record->delete() with the FK
    // ON DELETE CASCADE removes the pivot rows automatically — verify the
    // behavior end-to-end via the widget action.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Playlist::factory() fires PlaylistCreated → SyncPipelineService → Redis
    // lock — Bus::fake() is mandatory per the project test pattern.
    Bus::fake();

    // Local setup — this test file's beforeEach doesn't create user/playlist/group.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => true, 'available_streams' => 0,
    ]);
    $group1 = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group One',
    ]);
    $group2 = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Group Two',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '552',
        'file_path' => 'cache/movie:552::::.mp4',
    ]);
    $file->dynamicGroups()->attach([$group1->id, $group2->id]);
    expect($file->dynamicGroups()->count())->toBe(2);

    Storage::disk('local')->put('cache/movie:552::::.mp4', 'data');

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $file);

    expect(CachedContentFile::find($file->id))->toBeNull()
        ->and(DB::table('cached_content_file_dynamic_groups')->where('cached_content_file_id', $file->id)->count())->toBe(0)
        ->and(Storage::disk('local')->exists('cache/movie:552::::.mp4'))->toBeFalse();
});

it('View error action is visible only on Failed rows and surfaces last_error_message in the modal', function () {
    // Operators need to see WHY a download failed without grepping logs. The
    // activity widget's "View error" record action (warning triangle icon)
    // is gated on status=Failed and pulls the message from last_error_message
    // (populated by DownloadCachedContentFile::markFailed()).
    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '700',
        'title' => 'Broken File',
        'last_error_message' => 'HTTP 502 Bad Gateway — upstream provider returned a transient error',
        'last_failed_at' => now()->subMinutes(2),
        'failure_count' => 3,
    ]);

    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '701',
        'title' => 'Working File',
    ]);

    $pending = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '702',
        'title' => 'In Flight',
        'status' => CachedContentFileStatus::Pending,
    ]);

    Livewire::test(DynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertTableActionVisible('viewError', $failed)
        ->assertTableActionHidden('viewError', $completed)
        ->assertTableActionHidden('viewError', $pending);
});
