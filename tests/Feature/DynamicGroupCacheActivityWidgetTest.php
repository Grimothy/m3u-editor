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
