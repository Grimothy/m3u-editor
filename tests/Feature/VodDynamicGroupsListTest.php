<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
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

it('registers the VOD Dynamic Groups nav item at the TOP of the VOD Channels group', function () {
    // Sort = 1 puts Dynamic Groups ABOVE VOD Groups (sort 2) and
    // VODs (sort 3). Dynamic Groups is the operator's primary
    // surface for "auto-grouped by TMDB" content — the user
    // explicitly asked for it to appear at the top of the section.
    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeTrue()
        ->and(VodDynamicGroupResource::getNavigationGroup())->toBe(__('VOD Channels'))
        ->and(VodDynamicGroupResource::getNavigationSort())->toBeLessThanOrEqual(1);
});

it('hides the sidebar entry when the experimental feature flag is disabled', function () {
    config()->set('feature.playlist_tmdb_dynamic_groups', false);

    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('hides the sidebar entry when TMDB is not configured', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(TmdbService::class, $tmdb);

    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('registers an index page but no view page (the shared parent DynamicGroupResource owns the view route)', function () {
    $pages = VodDynamicGroupResource::getPages();

    expect($pages)->toHaveKey('index')
        ->and($pages)->not->toHaveKey('view');
});

it('still hides the create route (rules are configured on the Playlist form, not here)', function () {
    expect(VodDynamicGroupResource::canCreate())->toBeFalse();
});

// --- ListVodGroups / ListCategories footer-widgets decoupling ----------------

it('the tab count query is Postgres-compatible (no GROUP BY on a subquery column)', function () {
    // Regression guard: the original bug crashed the page with
    //   "column dynamic_groups.id must appear in the GROUP BY clause"
    // because getEloquentQuery() included withCount('channels'), which
    // adds a subquery column to SELECT, and getTabs() then did
    // groupBy('playlist_id') on the same builder. Postgres rejects
    // that combination because the subquery column and dynamic_groups.*
    // columns are not in GROUP BY. Verify getTabs() now returns without
    // throwing, and the playlist counts are computed correctly.
    $this->playlist->update(['name' => 'My Playlist']);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD A',
    ]);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'VOD B',
    ]);

    // If the bug regresses, this throws a QueryException on Postgres.
    // The bug was triggered by groupBy('playlist_id') on
    // getEloquentQuery(), which Postgres rejects when a subquery
    // column (withCount) is also selected. After the refactor, the
    // getEloquentQuery() no longer has withCount and the
    // groupBy lives in getPlaylistSubTabs() (the per-playlist
    // sub-tabs), not in getTabs() (the top-level groups/cache
    // tabs). Sanity-check both: top-level tabs present + playlist
    // sub-tabs present with the right count.
    $page = Livewire::test(ListVodDynamicGroups::class)->instance();
    $topTabs = $page->getTabs();
    $subTabs = $page->getPlaylistSubTabs();

    expect($topTabs)->toHaveKey('groups')
        ->and($topTabs)->toHaveKey('cache')
        ->and($subTabs)->toHaveKey('all')
        ->and($subTabs)->toHaveKey((string) $this->playlist->id);
    expect($subTabs[(string) $this->playlist->id]->getBadge())->toBe('2');
});

it('embeds the VOD-side Dynamic Group Cache Activity widget in the page content() schema (visually attached to the table area)', function () {
    // The cache activity widget is no longer a footer widget stacked
    // below the page — it's embedded in the page's content() schema
    // right after the EmbeddedTable, so it visually attaches to the
    // table area. The widget itself renders a <x-filament::section>
    // wrapper via its custom Blade view so the "clustered section"
    // look is preserved.
    expect(method_exists(ListVodDynamicGroups::class, 'content'))->toBeTrue();

    // Sanity: the content() override must reference the widget class.
    $reflection = new ReflectionMethod(ListVodDynamicGroups::class, 'content');
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('VodDynamicGroupCacheActivityWidget::class');
});

it('ListVodGroups no longer registers the per-type DynamicGroupsWidget (moved to the VOD Channels sidebar)', function () {
    $vodFooter = (new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->invoke(new ListVodGroups);

    foreach ($vodFooter as $widgetClass) {
        expect($widgetClass)->not->toContain('VodGroups\\Widgets\\DynamicGroupsWidget');
    }
});

it('ListCategories no longer registers the per-type DynamicGroupsWidget (moved to the Series sidebar)', function () {
    $seriesFooter = (new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
        ->invoke(new ListCategories);

    foreach ($seriesFooter as $widgetClass) {
        expect($widgetClass)->not->toContain('Categories\\Widgets\\DynamicGroupsWidget');
    }
});

// --- Page-level behavior ------------------------------------------------------

it('shows only vod-type Dynamic Groups for the authenticated user', function () {
    $vodMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'My VOD Trending',
    ]);
    // Same-user: series (must NOT show — wrong type for this listing).
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    // Other-user: vod (must NOT show — wrong owner).
    $otherUser = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $otherUser->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Their VOD Trending',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertCanSeeTableRecords([$vodMine])
        ->assertCanNotSeeTableRecords([
            DynamicGroup::where('name', 'My Series Trending')->first(),
            DynamicGroup::where('name', 'Their VOD Trending')->first(),
        ]);
});

it('links the view action to the shared DynamicGroupResource view route', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'View Me',
    ]);

    $expectedUrl = DynamicGroupResource::getUrl('view', ['record' => $group]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionHasUrl('view', $expectedUrl, $group);
});

it('exposes view and delete actions but no edit', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('edit');

    expect($group->refresh()->exists())->toBeTrue();
});

it('deleting a row removes the DynamicGroup record', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->callTableAction('delete', $group);

    expect(DynamicGroup::find($group->id))->toBeNull();
});

// --- Items count -------------------------------------------------------------

it('the Items column counts channels', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'tmdb_id' => '550', 'is_vod' => true,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD Group',
    ]);
    $group->channels()->attach($channel);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('channels_count', 1, $group);
});

// --- Cache column ------------------------------------------------------------

it('the Cached column shows "—" for groups without a cache-enabled rule', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'No Cache Rule',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'No Cache Rule', 'cache_enabled' => false],
        ],
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('cache_status', '—', $group);
});

it('the Cached column shows "{cached}/{total}" when caching is enabled and content is cached', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'tmdb_id' => '550', 'is_vod' => true,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Cached Group',
    ]);
    $group->channels()->attach($channel);

    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Cached Group', 'cache_enabled' => true],
        ],
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('cache_status', '1/1', $group);
});

// --- Cache Now bulk action ---------------------------------------------------

it('exposes a cache_now bulk action on the VOD listing', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableBulkActionExists('cache_now');
});

it('cache_now dispatches one DownloadCachedContentFile job per channel across selected vod-type groups', function () {
    Bus::fake();

    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => true],
        ['name' => 'Popular', 'cache_enabled' => true],
    ]]);

    $groupA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending',
    ]);
    $groupAChannels = collect(['800', '801', '802'])->map(
        fn (string $tmdbId) => Channel::factory()->for($this->playlist)->create(['tmdb_id' => $tmdbId])
    );
    $groupA->channels()->attach($groupAChannels->pluck('id')->all());

    $groupB = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Popular',
    ]);
    $groupBChannels = collect(['900', '901'])->map(
        fn (string $tmdbId) => Channel::factory()->for($this->playlist)->create(['tmdb_id' => $tmdbId])
    );
    $groupB->channels()->attach($groupBChannels->pluck('id')->all());

    Livewire::test(ListVodDynamicGroups::class)
        ->callTableBulkAction('cache_now', [$groupA->id, $groupB->id]);

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 5);
});

it('cache_now fires a warning notification when dynamic-group caching is disabled', function () {
    Bus::fake();

    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    $tester = Livewire::test(ListVodDynamicGroups::class)
        ->callTableBulkAction('cache_now', [$group->id]);

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
    $tester->assertNotified('Dynamic Group Caching is disabled');
});
