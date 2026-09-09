<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Filament\Resources\VodGroups\Widgets\DynamicGroupCacheActivityWidget;
use App\Models\CachedContentFile;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
