<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CachedContentSource;
use App\Jobs\RefreshMediaServerLibraryJob;
use App\Jobs\SyncMediaServer;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentRetentionService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
|--------------------------------------------------------------------------
| Step 7 — release through arr, and media server refresh
|--------------------------------------------------------------------------
|
| releaseDynamicGroupCaches() releases group-managed arr rows by deleting
| the title (and its files) through Radarr/Sonarr once their provenance
| pivot is gone. Manual rows, never_expire rows and active downloads stay.
| A successful release asks the linked media server to rescan. Helpers use
| the car* prefix — sibling arr test files own the sad* names.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Http::preventStrayRequests();
    NotificationFacade::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $this->radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
    ]);
    $this->sonarr = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);

    carSettings();
});

/**
 * Bind GeneralSettings: TMDB configured (the sweep checks it) and cache enabled.
 */
function carSettings(): GeneralSettings
{
    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    $settings->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

/**
 * A VOD channel with a cacheable URL and a tmdb_id on $playlist.
 */
function carChannel(Playlist $playlist, array $overrides = []): Channel
{
    return Channel::factory()->create(array_merge([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => '550',
        'url' => 'https://provider.example.com/movie/fight-club.mkv',
    ], $overrides));
}

/**
 * An arr CachedContentFile row standing for $cacheable (Channel → radarr,
 * Series → sonarr), linked to $integration by identity.
 */
function carArrRow(Channel|Series $cacheable, ArrIntegration $integration, array $overrides = []): CachedContentFile
{
    $isMovie = $cacheable instanceof Channel;

    return CachedContentFile::create(array_merge([
        'user_id' => $cacheable->user_id,
        'playlist_id' => $cacheable->playlist_id,
        'cacheable_type' => $cacheable->getMorphClass(),
        'cacheable_id' => $cacheable->getKey(),
        'content_type' => $isMovie ? 'movie' : 'series',
        'tmdb_id' => $isMovie ? (string) $cacheable->tmdb_id : ($cacheable->tmdb_id ? (string) $cacheable->tmdb_id : null),
        'tvdb_id' => $isMovie ? null : (string) $cacheable->tvdb_id,
        'title' => $isMovie ? $cacheable->display_title : $cacheable->name,
        'status' => CachedContentFileStatus::Requested,
        'source' => $isMovie ? CachedContentSource::Radarr : CachedContentSource::Sonarr,
        'arr_integration_id' => $integration->id,
        'arr_library_id' => 77,
    ], $overrides));
}

/**
 * A playlist (media-server preference on) + DynamicGroup whose
 * dynamic_groups_config holds the matching rule.
 *
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: Playlist, 1: DynamicGroup}
 */
function carPlaylistWithGroup(User $user, string $type = 'vod', array $ruleOverrides = []): array
{
    $rule = array_merge([
        'enabled' => true,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => [],
    ], $ruleOverrides);

    $playlist = Playlist::factory()->for($user)->create(['prefer_media_server_sources' => true]);
    $playlist->update(['dynamic_groups_config' => [$rule]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
    ]);

    return [$playlist, $group];
}

/**
 * Attach a Channel or Series member with an explicit TMDB-rank position.
 */
function carAttachMember(DynamicGroup $group, Channel|Series $item, int $position): void
{
    $relation = $item instanceof Channel ? 'channels' : 'series';
    $group->{$relation}()->attach($item->id, ['position' => $position]);
}

/**
 * A group-managed arr row with a live `in_group` provenance pivot, whose
 * cacheable identity is NOT among the group's attached members (it left).
 */
function carOrphanedGroupRow(ArrIntegration $integration, DynamicGroup $group, Playlist $playlist, array $overrides = []): CachedContentFile
{
    $cacheable = $group->type === 'series'
        ? Series::factory()->create([
            'user_id' => $playlist->user_id,
            'playlist_id' => $playlist->id,
            'tmdb_id' => 1399,
            'tvdb_id' => 81189,
        ])
        : carChannel($playlist);

    $row = carArrRow($cacheable, $integration, array_merge([
        'managed_by' => CachedContentManagedBy::DynamicGroup,
        'status' => CachedContentFileStatus::Completed,
    ], $overrides));
    $row->dynamicGroups()->attach($group->id, ['retention' => 'in_group', 'retention_days' => 7, 'dropped_at' => null]);

    return $row;
}

/**
 * Attach an in-scope member whose identity differs from the orphaned row's.
 */
function carAttachOtherMember(DynamicGroup $group, Playlist $playlist): void
{
    if ($group->type === 'series') {
        carAttachMember($group, Series::factory()->create([
            'user_id' => $playlist->user_id,
            'playlist_id' => $playlist->id,
            'tmdb_id' => 42,
            'tvdb_id' => 9999,
        ]), 0);

        return;
    }

    carAttachMember($group, carChannel($playlist, ['tmdb_id' => '999']), 0);
}

// ── 1. A group-managed Radarr row is released through Radarr ──────────────

it('releases a group-managed radarr row through radarr once its pivot is dropped', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    $row = carOrphanedGroupRow($this->radarr, $group, $playlist, ['arr_library_id' => 77]);

    Http::fake(['*/api/v3/queue/details*' => Http::response([], 200), '*/api/v3/movie/*' => Http::response([], 200)]);

    $released = app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect($released)->toBe(1)
        ->and(CachedContentFile::find($row->id))->toBeNull();

    Http::assertSent(function ($request): bool {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/v3/movie/77')
            && str_contains($request->url(), 'deleteFiles=true');
    });
});

// ── 2. Same for a Sonarr series row ───────────────────────────────────────

it('releases a group-managed sonarr row through sonarr once its pivot is dropped', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'series', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->sonarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    $row = carOrphanedGroupRow($this->sonarr, $group, $playlist, ['arr_library_id' => 77]);

    Http::fake(['*/api/v3/queue/details*' => Http::response([], 200), '*/api/v3/series/*' => Http::response([], 200)]);

    $released = app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect($released)->toBe(1)
        ->and(CachedContentFile::find($row->id))->toBeNull();

    Http::assertSent(function ($request): bool {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/v3/series/77')
            && str_contains($request->url(), 'deleteFiles=true');
    });
});

// ── 3. A failed DELETE keeps the row with its error ───────────────────────

it('keeps a group-managed arr row with its error when radarr rejects the delete', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    $row = carOrphanedGroupRow($this->radarr, $group, $playlist);

    Http::fake(['*/api/v3/queue/details*' => Http::response([], 200), '*/api/v3/movie/*' => Http::response(['message' => 'boom'], 500)]);

    $released = app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    $row->refresh();
    expect($released)->toBe(0)
        ->and($row->status)->toBe(CachedContentFileStatus::Completed)
        ->and($row->last_error_message)->toContain('Could not remove from');
});

// ── 4. A manual arr row is never removed ──────────────────────────────────

it('never releases a manual arr row', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);

    $channel = carChannel($playlist);
    $row = carArrRow($channel, $this->radarr, ['status' => CachedContentFileStatus::Completed]);
    $row->dynamicGroups()->attach($group->id, ['retention' => 'in_group', 'retention_days' => 7, 'dropped_at' => null]);

    app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect(CachedContentFile::find($row->id))->not->toBeNull()
        ->and(Http::assertNothingSent())->toBeNull();
});

// ── 5. A never_expire arr row is kept ─────────────────────────────────────

it('keeps a group-managed arr row whose pivot retention is never_expire', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);

    $channel = carChannel($playlist);
    $row = carArrRow($channel, $this->radarr, [
        'managed_by' => CachedContentManagedBy::DynamicGroup,
        'status' => CachedContentFileStatus::Completed,
    ]);
    $row->dynamicGroups()->attach($group->id, ['retention' => 'never_expire', 'retention_days' => 7, 'dropped_at' => null]);

    app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect(CachedContentFile::find($row->id))->not->toBeNull()
        ->and($row->refresh()->dynamicGroups()->whereKey($group->id)->exists())->toBeTrue()
        ->and(Http::assertNothingSent())->toBeNull();
});

// ── 6. An arr row still downloading is not released ───────────────────────

it('does not release an arr row that is still downloading even when its pivot is gone', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    $row = carOrphanedGroupRow($this->radarr, $group, $playlist, [
        'status' => CachedContentFileStatus::Downloading,
        'bytes_expected' => 1000,
        'bytes_downloaded' => 400,
        'last_progress_at' => now(),
    ]);

    app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect(CachedContentFile::find($row->id))->not->toBeNull()
        ->and(Http::assertNothingSent())->toBeNull();
});

// ── 7. A successful release refreshes the linked media server ─────────────

it('dispatches the media server refresh jobs after a successful release', function () {
    $mediaServer = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $this->radarr->media_server_integration_id = $mediaServer->id;
    $this->radarr->save();

    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    carOrphanedGroupRow($this->radarr, $group, $playlist);

    Http::fake(['*/api/v3/queue/details*' => Http::response([], 200), '*/api/v3/movie/*' => Http::response([], 200)]);

    app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    Bus::assertDispatched(RefreshMediaServerLibraryJob::class);
    Bus::assertDispatched(SyncMediaServer::class);
});

it('refreshes the media server once when a sweep releases several arr rows', function () {
    $mediaServer = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $this->radarr->media_server_integration_id = $mediaServer->id;
    $this->radarr->save();

    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    carOrphanedGroupRow($this->radarr, $group, $playlist);
    carOrphanedGroupRow($this->radarr, $group, $playlist, ['tmdb_id' => '551', 'arr_library_id' => 78]);

    Http::fake(['*/api/v3/queue/details*' => Http::response([], 200), '*/api/v3/movie/*' => Http::response([], 200)]);

    // Null cache locks always acquire, so ShouldBeUnique can't hide per-title dispatches.
    config(['cache.default' => 'null']);
    app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect(CachedContentFile::query()->arr()->count())->toBe(0);
    Bus::assertDispatchedTimes(RefreshMediaServerLibraryJob::class, 1);
});

// ── 8f. Empty scope and missing library id ────────────────────────────────

it('releases a group-managed arr row when the group has no in-scope members at all', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    $row = carOrphanedGroupRow($this->radarr, $group, $playlist, ['arr_library_id' => 77]);

    Http::fake(['*/api/v3/queue/details*' => Http::response([], 200), '*/api/v3/movie/*' => Http::response([], 200)]);

    $released = app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect($released)->toBe(1)
        ->and(CachedContentFile::find($row->id))->toBeNull();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/movie/77'));
});

it('deletes a released arr row with library id 0 locally without calling arr', function () {
    [$playlist, $group] = carPlaylistWithGroup($this->user, 'vod', [
        'cache_enabled' => true,
        'cache_method' => 'arr',
        'cache_arr_integration_id' => $this->radarr->id,
    ]);
    carAttachOtherMember($group, $playlist);
    $row = carOrphanedGroupRow($this->radarr, $group, $playlist, ['arr_library_id' => 0]);

    Http::fake();

    $released = app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect($released)->toBe(1)
        ->and(CachedContentFile::find($row->id))->toBeNull();

    Http::assertNothingSent();
});
