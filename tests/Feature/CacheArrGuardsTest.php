<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\CachedContentDispatchService;
use App\Services\CachedContentRetentionService;
use App\Services\MediaSourceMatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Arr-row guards on the provider-only cache paths (Step 2)
|--------------------------------------------------------------------------
|
| Radarr/Sonarr rows (source != provider) have no file on our disk. Every
| pre-arr code path that reads, serves, re-queues, deletes or releases
| cached files must ignore them entirely:
|   - findServableFor / cachedContentFile never hand back an arr row
|   - requeue() and DownloadCachedContentFile refuse them
|   - deleteStoredFile() never touches the disk for them
|   - retention (evaluate / releaseDynamicGroupCaches /
|     markMediaMatchedDropped) and the orphan sweep leave them alone
|   - the dynamic-group byte budget counts provider rows only
*/

uses(RefreshDatabase::class);

/**
 * Bind GeneralSettings with the requested enable_cache / TMDB state.
 */
function cagSettings(bool $enableCache = true, ?string $tmdbApiKey = 'fake-api-key'): GeneralSettings
{
    $settings = new GeneralSettings;
    $settings->enable_cache = $enableCache;
    $settings->tmdb_api_key = $tmdbApiKey;
    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

/**
 * A playlist + DynamicGroup whose dynamic_groups_config holds the matching
 * rule. Cache rule keys go in $ruleOverrides.
 *
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: Playlist, 1: DynamicGroup}
 */
function cagPlaylistWithGroup(string $type = 'vod', array $ruleOverrides = []): array
{
    $rule = array_merge([
        'enabled' => true,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => [],
    ], $ruleOverrides);

    $playlist = Playlist::factory()->create();
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
 * A VOD channel with a cacheable URL on $playlist.
 */
function cagChannel(Playlist $playlist, int $n): Channel
{
    return Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => (string) (100 + $n),
        'url' => "https://provider.example.com/movie/{$n}.mkv",
    ]);
}

/**
 * Attach a Channel or Series member with an explicit TMDB-rank position.
 */
function cagAttachMember(DynamicGroup $group, Channel|Series $item, int $position): void
{
    $relation = $item instanceof Channel ? 'channels' : 'series';
    $group->{$relation}()->attach($item->id, ['position' => $position]);
}

/**
 * Attach a provenance pivot row carrying a retention snapshot.
 */
function cagAttachPivot(CachedContentFile $file, ?DynamicGroup $group, string $retention, ?int $days = 7, ?string $droppedAt = null): void
{
    $file->dynamicGroups()->attach($group?->id, [
        'retention' => $retention,
        'retention_days' => $days,
        'dropped_at' => $droppedAt,
    ]);
}

/**
 * Give $channel an eligible media-server match: a media playlist with an
 * enabled emby integration and an enabled media channel sharing the
 * provider channel's tmdb_id.
 */
function cagMatchToMedia(Playlist $provider, Channel $channel): Channel
{
    $media = Playlist::factory()->for($provider->user)->create();
    $mediaChannel = Channel::factory()->for($media)->for($provider->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => $channel->tmdb_id,
        'url' => 'https://media.example.com/local/'.$channel->id.'.mkv',
    ]);
    MediaServerIntegration::factory()->for($provider->user)->create([
        'type' => 'emby',
        'enabled' => true,
        'playlist_id' => $media->id,
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($provider->refresh());

    return $mediaChannel;
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    cagSettings(true);
});

it('findServableFor returns null when the only Completed row is an arr row with a file_path', function () {
    $playlist = Playlist::factory()->create();
    $channel = cagChannel($playlist, 1);
    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    CachedContentFile::factory()
        ->arrMovie($integration, $channel)
        ->create([
            'status' => CachedContentFileStatus::Completed,
            'disk' => CachedContentFile::DISK,
            'file_path' => 'arr/never-on-our-disk.mp4',
            'file_size_bytes' => 1_000_000,
        ]);

    expect(CachedContentFile::findServableFor($channel))->toBeNull()
        ->and($channel->isCached())->toBeFalse();
});

it('cachedContentFile is null when only an arr row exists, and the arr row lands on arrCachedContentFile', function () {
    $playlist = Playlist::factory()->create();
    $channel = cagChannel($playlist, 1);
    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    $arrRow = CachedContentFile::factory()->arrMovie($integration, $channel)->create();

    $channel->refresh();

    expect($channel->cachedContentFile)->toBeNull()
        ->and($channel->arrCachedContentFile?->id)->toBe($arrRow->id)
        ->and($arrRow->isArr())->toBeTrue();
});

it('requeue refuses an arr row and dispatches nothing', function () {
    $playlist = Playlist::factory()->create();
    $channel = cagChannel($playlist, 1);
    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    $arrRow = CachedContentFile::factory()
        ->arrMovie($integration, $channel)
        ->create(['status' => CachedContentFileStatus::Failed]);

    $requeued = app(CachedContentDispatchService::class)->requeue($arrRow);

    Bus::assertNotDispatched(DownloadCachedContentFile::class);

    expect($requeued)->toBeFalse();

    // The row is untouched (still Failed, still an arr row).
    expect($arrRow->refresh()->status)->toBe(CachedContentFileStatus::Failed)
        ->and($arrRow->isArr())->toBeTrue();
});

it('deleteStoredFile on an arr row leaves the file on disk', function () {
    $playlist = Playlist::factory()->create();
    $channel = cagChannel($playlist, 1);
    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    Storage::disk(CachedContentFile::DISK)->put('arr/should-survive.mp4', 'bytes');

    $arrRow = CachedContentFile::factory()
        ->arrMovie($integration, $channel)
        ->create([
            'status' => CachedContentFileStatus::Completed,
            'disk' => CachedContentFile::DISK,
            'file_path' => 'arr/should-survive.mp4',
        ]);

    $arrRow->deleteStoredFile();

    expect(Storage::disk(CachedContentFile::DISK)->exists('arr/should-survive.mp4'))->toBeTrue();
});

it('evaluate never includes an arr row whose source channel is deleted', function () {
    $playlist = Playlist::factory()->create();
    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    // Positive control: a provider row whose channel vanished IS picked up.
    $providerOrphan = CachedContentFile::factory()->completed()->create([
        'status' => CachedContentFileStatus::Completed,
    ]);
    Channel::whereKey($providerOrphan->cacheable_id)->delete();

    // The arr row whose channel vanished must NOT be picked up.
    $arrChannel = cagChannel($playlist, 2);
    $arrRow = CachedContentFile::factory()
        ->arrMovie($integration, $arrChannel)
        ->create(['status' => CachedContentFileStatus::Completed]);
    $arrChannel->delete();

    $ids = app(CachedContentRetentionService::class)->evaluate();

    expect($ids)->toContain($providerOrphan->id)
        ->and($ids)->not->toContain($arrRow->id);
});

it('the orphan cleanup command leaves an arr row with no file_path alone', function () {
    $playlist = Playlist::factory()->create();
    $channel = cagChannel($playlist, 1);
    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    // Positive control: a stale provider orphan IS deleted.
    $providerOrphan = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(10),
        'created_at' => Carbon::now()->subDays(10),
    ]);

    // The stale arr row (no file_path by design) must survive.
    $arrRow = CachedContentFile::factory()
        ->arrMovie($integration, $channel)
        ->create([
            'file_path' => null,
            'updated_at' => Carbon::now()->subDays(10),
            'created_at' => Carbon::now()->subDays(10),
        ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($providerOrphan->id))->toBeNull()
        ->and(CachedContentFile::find($arrRow->id))->not->toBeNull();
});

it('releaseDynamicGroupCaches releases the provider row on a media match but never the arr row', function () {
    [$playlist, $group] = cagPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_retention' => 'in_group',
    ]);
    $channel = cagChannel($playlist, 1);
    cagAttachMember($group, $channel, 0);
    $playlist->update(['prefer_media_server_sources' => true]);
    cagMatchToMedia($playlist, $channel);

    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    $providerRow = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    $arrRow = CachedContentFile::factory()->arrMovie($integration, $channel)->dynamicGroupManaged()->create();

    cagAttachPivot($providerRow, $group, 'in_group');
    cagAttachPivot($arrRow, $group, 'in_group');

    app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    // Provider row released (media server won), arr row untouched.
    expect(CachedContentFile::find($providerRow->id))->toBeNull()
        ->and(CachedContentFile::find($arrRow->id))->not->toBeNull();

    $arrPivot = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $arrRow->id)
        ->where('dynamic_group_id', $group->id)
        ->first();

    expect($arrPivot)->not->toBeNull()
        ->and($arrPivot->dropped_at)->toBeNull();
});

it('the group budget ignores arr bytes', function () {
    [$playlist, $group] = cagPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_max_gb' => 1,
    ]);
    $channel = cagChannel($playlist, 1);
    cagAttachMember($group, $channel, 0);

    $integration = ArrIntegration::factory()->radarr()->for($playlist->user)->create();

    // An arr row way over the limit, tracked by the group's provenance pivot.
    $arrRow = CachedContentFile::factory()
        ->arrMovie($integration, $channel)
        ->create(['bytes_expected' => 10 * 1024 ** 3]);
    cagAttachPivot($arrRow, $group, 'in_group');

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    // 10 GB of arr bytes must not consume the 1 GB provider budget.
    expect($counts[CacheDispatchResult::Queued->value])->toBe(1);

    $providerRow = CachedContentFile::query()
        ->where('source', 'provider')
        ->where('cacheable_type', (new Channel)->getMorphClass())
        ->where('cacheable_id', $channel->id)
        ->first();

    expect($providerRow)->not->toBeNull()
        ->and($providerRow->managed_by)->toBe(CachedContentManagedBy::DynamicGroup);
});
