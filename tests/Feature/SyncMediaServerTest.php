<?php

use App\Jobs\SyncMediaServer;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->integration = MediaServerIntegration::create([
        'name' => 'Test Jellyfin Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'port' => 8096,
        'api_key' => 'test-api-key',
        'enabled' => true,
        'ssl' => false,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'user_id' => $this->user->id,
    ]);
});

it('can create a media server integration', function () {
    expect($this->integration)->toBeInstanceOf(MediaServerIntegration::class);
    expect($this->integration->name)->toBe('Test Jellyfin Server');
    expect($this->integration->type)->toBe('jellyfin');
});

it('has correct initial status', function () {
    expect($this->integration->status)->toBe('idle');
    expect($this->integration->progress)->toBe(0);
    expect($this->integration->movie_progress)->toBe(0);
    expect($this->integration->series_progress)->toBe(0);
});

it('can update sync progress', function () {
    $this->integration->update([
        'status' => 'processing',
        'progress' => 50,
        'movie_progress' => 75,
        'series_progress' => 25,
    ]);

    $this->integration->refresh();

    expect($this->integration->status)->toBe('processing');
    expect($this->integration->progress)->toBe(50);
    expect($this->integration->movie_progress)->toBe(75);
    expect($this->integration->series_progress)->toBe(25);
});

it('can mark sync as completed', function () {
    $this->integration->update([
        'status' => 'completed',
        'progress' => 100,
        'movie_progress' => 100,
        'series_progress' => 100,
        'last_synced_at' => now(),
    ]);

    $this->integration->refresh();

    expect($this->integration->status)->toBe('completed');
    expect($this->integration->progress)->toBe(100);
    expect($this->integration->last_synced_at)->not->toBeNull();
});

it('can mark sync as failed', function () {
    $this->integration->update([
        'status' => 'failed',
        'progress' => 0,
    ]);

    $this->integration->refresh();

    expect($this->integration->status)->toBe('failed');
    expect($this->integration->progress)->toBe(0);
});

it('removes stale movies and groups after sync', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration->update(['playlist_id' => $playlist->id]);

    $currentBatch = 'current-batch-uuid';
    $staleBatch = 'old-stale-batch-uuid';

    // Create a current group and channel (should survive cleanup)
    $currentGroup = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'import_batch_no' => $currentBatch,
    ]);
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $currentGroup->id,
        'import_batch_no' => $currentBatch,
        'is_custom' => false,
    ]);

    // Create a stale group and channel (should be removed)
    $staleGroup = Group::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'import_batch_no' => $staleBatch,
        'custom' => false,
    ]);
    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $staleGroup->id,
        'import_batch_no' => $staleBatch,
        'is_custom' => false,
    ]);

    // Invoke the protected cleanupStaleRecords method via reflection
    $job = new SyncMediaServer($this->integration->id);
    $ref = new ReflectionClass($job);

    $batchProp = $ref->getProperty('batchNo');
    $batchProp->setValue($job, $currentBatch);

    $method = $ref->getMethod('cleanupStaleRecords');
    $method->invoke($job, $this->integration, $playlist);

    // Current records should remain
    expect(Channel::where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(Group::where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(Channel::where('import_batch_no', $currentBatch)->exists())->toBeTrue();
    expect(Group::where('import_batch_no', $currentBatch)->exists())->toBeTrue();

    // Stale records should be gone
    expect(Channel::where('import_batch_no', $staleBatch)->exists())->toBeFalse();
    expect(Group::where('import_batch_no', $staleBatch)->exists())->toBeFalse();
});

it('removes stale series, seasons, episodes, and categories after sync', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration->update(['playlist_id' => $playlist->id]);

    $currentBatch = 'current-batch-uuid';
    $staleBatch = 'old-stale-batch-uuid';

    // Create current series hierarchy (should survive)
    $currentCategory = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'import_batch_no' => $currentBatch,
    ]);
    $currentSeries = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'category_id' => $currentCategory->id,
        'import_batch_no' => $currentBatch,
    ]);
    $currentSeason = Season::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'series_id' => $currentSeries->id,
        'category_id' => $currentCategory->id,
        'import_batch_no' => $currentBatch,
    ]);
    Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'series_id' => $currentSeries->id,
        'season_id' => $currentSeason->id,
        'import_batch_no' => $currentBatch,
    ]);

    // Create stale series hierarchy (should be removed)
    $staleCategory = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'import_batch_no' => $staleBatch,
    ]);
    $staleSeries = Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'category_id' => $staleCategory->id,
        'import_batch_no' => $staleBatch,
    ]);
    $staleSeason = Season::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'series_id' => $staleSeries->id,
        'category_id' => $staleCategory->id,
        'import_batch_no' => $staleBatch,
    ]);
    Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'series_id' => $staleSeries->id,
        'season_id' => $staleSeason->id,
        'import_batch_no' => $staleBatch,
    ]);

    // Invoke the protected cleanupStaleRecords method via reflection
    $job = new SyncMediaServer($this->integration->id);
    $ref = new ReflectionClass($job);

    $batchProp = $ref->getProperty('batchNo');
    $batchProp->setValue($job, $currentBatch);

    $method = $ref->getMethod('cleanupStaleRecords');
    $method->invoke($job, $this->integration, $playlist);

    // Current records should remain
    expect(Series::where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(Season::where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(Episode::where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(Category::where('playlist_id', $playlist->id)->count())->toBe(1);

    // Stale records should be gone
    expect(Series::where('import_batch_no', $staleBatch)->exists())->toBeFalse();
    expect(Season::where('import_batch_no', $staleBatch)->exists())->toBeFalse();
    expect(Episode::where('import_batch_no', $staleBatch)->exists())->toBeFalse();
    expect(Category::where('import_batch_no', $staleBatch)->exists())->toBeFalse();
});

it('preserves categories that still have current series during cleanup', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration->update(['playlist_id' => $playlist->id]);

    $currentBatch = 'current-batch-uuid';
    $staleBatch = 'old-stale-batch-uuid';

    // Create a category with a stale batch but still has a current series
    $sharedCategory = Category::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'import_batch_no' => $staleBatch,
    ]);
    Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'category_id' => $sharedCategory->id,
        'import_batch_no' => $currentBatch,
    ]);

    // Invoke cleanup
    $job = new SyncMediaServer($this->integration->id);
    $ref = new ReflectionClass($job);

    $batchProp = $ref->getProperty('batchNo');
    $batchProp->setValue($job, $currentBatch);

    $method = $ref->getMethod('cleanupStaleRecords');
    $method->invoke($job, $this->integration, $playlist);

    // Category should be preserved because it still has a current series
    expect(Category::where('id', $sharedCategory->id)->exists())->toBeTrue();
    expect(Series::where('playlist_id', $playlist->id)->count())->toBe(1);
});
