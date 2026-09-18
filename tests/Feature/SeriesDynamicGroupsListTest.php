<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\Pages\ListSeriesDynamicGroups;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the listing page renders under test.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    // Also gated behind a configured TMDB integration — without TMDB
    // the Sync pipeline is a no-op and there'd be nothing to show.
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    app()->instance(TmdbService::class, $tmdb);

    Bus::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

// --- Resource-level visibility / nav registration ----------------------------

it('registers the Series Dynamic Groups nav item at the TOP of the Series group', function () {
    expect(SeriesDynamicGroupResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SeriesDynamicGroupResource::getNavigationGroup())->toBe(__('Series'))
        ->and(SeriesDynamicGroupResource::getNavigationSort())->toBeLessThanOrEqual(1);
});

it('hides the sidebar entry when the experimental feature flag is disabled', function () {
    config()->set('feature.playlist_tmdb_dynamic_groups', false);

    expect(SeriesDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('hides the sidebar entry when TMDB is not configured', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(TmdbService::class, $tmdb);

    expect(SeriesDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('registers an index page but no view page (the shared parent DynamicGroupResource owns the view route)', function () {
    $pages = SeriesDynamicGroupResource::getPages();

    expect($pages)->toHaveKey('index')
        ->and($pages)->not->toHaveKey('view');
});

it('still hides the create route (rules are configured on the Playlist form, not here)', function () {
    expect(SeriesDynamicGroupResource::canCreate())->toBeFalse();
});

// --- Old footer-widgets fully retired ----------------------------------------

it('retires both per-type DynamicGroupsWidgets from ListVodGroups and ListCategories footer slots', function () {
    expect((new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->getDeclaringClass()
        ->getName())->toBe(Page::class)
        ->and((new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
            ->getDeclaringClass()
            ->getName())->toBe(Page::class);
});

// --- Page-level behavior ------------------------------------------------------

it('shows only series-type Dynamic Groups for the authenticated user', function () {
    $seriesMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    // Same-user: vod (must NOT show — wrong type for this listing).
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'My VOD Trending',
    ]);
    // Other-user: series (must NOT show — wrong owner).
    $otherUser = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $otherUser->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Their Series Trending',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertCanSeeTableRecords([$seriesMine])
        ->assertCanNotSeeTableRecords([
            DynamicGroup::where('name', 'My VOD Trending')->first(),
            DynamicGroup::where('name', 'Their Series Trending')->first(),
        ]);
});

it('links the view action to the shared DynamicGroupResource view route', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'View Me',
    ]);

    $expectedUrl = DynamicGroupResource::getUrl('view', ['record' => $group]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableActionHasUrl('view', $expectedUrl, $group);
});

it('exposes view and delete actions but no edit', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('edit');

    expect($group->refresh()->exists())->toBeTrue();
});

it('deleting a row removes the DynamicGroup record', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->callTableAction('delete', $group);

    expect(DynamicGroup::find($group->id))->toBeNull();
});

// --- Items count -------------------------------------------------------------

it('the Items column counts series', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series Group',
    ]);
    $group->series()->attach($series);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableColumnStateSet('series_count', 1, $group);
});

// --- Tabs --------------------------------------------------------------------

it('per-playlist sub-tabs scope the table by playlist_id without breaking groupBy', function () {
    // Postgres-compat regression guard for the getEloquentQuery() +
    // groupBy('playlist_id') interaction — see the VOD-side page's
    // sibling test for the full explanation. Mirror assertion: tabs
    // present + per-playlist count correct.
    $this->playlist->update(['name' => 'My Playlist']);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series A',
    ]);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'popular', 'name' => 'Series B',
    ]);

    $page = Livewire::test(ListSeriesDynamicGroups::class)->instance();
    $tabs = $page->getTabs();

    expect(array_key_exists(null, $tabs))->toBeTrue()
        ->and($tabs)->toHaveKey((string) $this->playlist->id);
    expect($tabs[(string) $this->playlist->id]->getBadge())->toBe('2');
});
