<?php

use App\Enums\CachedContentSource;
use App\Enums\CacheDispatchResult;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Series;
use App\Services\CachedContentDispatchService;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory()->create() fires PlaylistListener -> SyncPipelineService
    // -> dispatch(ProcessM3uImport). Bus::fake() catches that.
    Bus::fake();
    Http::preventStrayRequests();
});

it('defaults an existing-style provider row to the provider source', function () {
    $row = CachedContentFile::factory()->create();

    expect($row->source)->toBe(CachedContentSource::Provider);
});

it('allows a provider row and an arr movie row for the same channel', function () {
    $provider = CachedContentFile::factory()->create();
    $channel = $provider->cacheable;
    $integration = ArrIntegration::factory()->radarr()->create();

    $arr = CachedContentFile::factory()->arrMovie($integration, $channel)->create();

    expect($arr->source)->toBe(CachedContentSource::Radarr)
        ->and(CachedContentFile::query()
            ->where('cacheable_type', $channel->getMorphClass())
            ->where('cacheable_id', $channel->getKey())
            ->count())->toBe(2);
});

it('rejects a second arr row for the same channel and source', function () {
    $provider = CachedContentFile::factory()->create();
    $channel = $provider->cacheable;
    $integration = ArrIntegration::factory()->radarr()->create();

    CachedContentFile::factory()->arrMovie($integration, $channel)->create();
    CachedContentFile::factory()->arrMovie($integration, $channel)->create();
})->throws(UniqueConstraintViolationException::class);

it('rolls back cleanly: drops arr rows, keeps provider rows, and restores the old unique key', function () {
    $provider = CachedContentFile::factory()->create();
    $channel = $provider->cacheable;
    $integration = ArrIntegration::factory()->radarr()->create();
    CachedContentFile::factory()->arrMovie($integration, $channel)->create();

    $migration = require database_path('migrations/2026_10_03_132956_add_arr_columns_to_cached_content_files_table.php');
    $migration->down();

    expect(CachedContentFile::find($provider->id))->not->toBeNull()
        ->and(CachedContentFile::query()->count())->toBe(1)
        ->and(Schema::hasColumn('cached_content_files', 'source'))->toBeFalse();

    // The restored old unique key: a second row for the same cacheable
    // fails. Raw insert with only the old columns — the model now expects
    // `source`, which the rolled-back schema no longer has.
    $threw = false;

    try {
        DB::table('cached_content_files')->insert([
            'uuid' => (string) Str::uuid(),
            'cacheable_type' => $channel->getMorphClass(),
            'cacheable_id' => $channel->getKey(),
            'content_type' => 'movie',
            'content_fingerprint' => 'rollback-duplicate-fingerprint',
        ]);
    } catch (UniqueConstraintViolationException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    // Put the current schema back for anything else running in this file.
    $migration->up();

    expect(Schema::hasColumn('cached_content_files', 'source'))->toBeTrue();
});

it('scopes provider and arr rows separately', function () {
    $provider = CachedContentFile::factory()->create();
    $integration = ArrIntegration::factory()->radarr()->create();
    $arr = CachedContentFile::factory()->arrMovie($integration, $provider->cacheable)->create();
    $series = Series::factory()->create([
        'user_id' => $provider->user_id,
        'playlist_id' => $provider->playlist_id,
    ]);
    CachedContentFile::factory()->arrSeries(ArrIntegration::factory()->sonarr()->create(), $series, [1])->create();

    $providers = CachedContentFile::query()->provider()->get();
    $arrs = CachedContentFile::query()->arr()->get();

    expect($providers)->toHaveCount(1)
        ->and($providers->first()->id)->toBe($provider->id)
        ->and($arrs)->toHaveCount(2)
        ->and($arrs->pluck('id')->all())->toContain($arr->id);
});

it('builds a notification for every arr dispatch result without UnhandledMatchError', function () {
    $channel = CachedContentFile::factory()->create()->cacheable;
    $episode = CachedContentFile::factory()->forEpisode('60625', 1, 5)->create()->cacheable;

    $results = [
        CacheDispatchResult::ArrRequested,
        CacheDispatchResult::ArrAlreadyAvailable,
        CacheDispatchResult::ArrMonitoredFallback,
        CacheDispatchResult::ArrFallbackQueued,
    ];

    foreach ($results as $result) {
        expect(CachedContentDispatchService::cacheNowNotification($channel, $result))->toBeInstanceOf(Notification::class)
            ->and(CachedContentDispatchService::cacheNowNotification($episode, $result))->toBeInstanceOf(Notification::class);
    }
});

it('titles an all-arr summary as sent to Radarr/Sonarr instead of nothing queued', function () {
    $notification = CachedContentDispatchService::summaryNotification([
        CacheDispatchResult::ArrRequested->value => 1,
    ]);

    expect($notification->getTitle())->toBe(__('Sent 1 to Radarr/Sonarr'));
});

it('counts arr fallback provider downloads as queued in the summary title', function () {
    $notification = CachedContentDispatchService::summaryNotification([
        CacheDispatchResult::ArrFallbackQueued->value => 2,
    ]);

    expect($notification->getTitle())->toBe(__('Queued 2 episodes for caching'));
});
