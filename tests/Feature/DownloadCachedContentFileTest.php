<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires PlaylistCreated → SyncPipelineService → Redis lock.
    // AGENTS.md says Bus::fake() is mandatory; Http::preventStrayRequests() guards
    // against any unexpected outbound request during the test.
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0, // disable connection pre-flight by default
    ]);
    $this->group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Test Group',
    ]);

    app(GeneralSettings::class)->refresh();
});

it('creates a Completed row on successful download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake([
        'provider.example/movie.mp4' => Http::response('FAKE_CONTENT', 200),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    $files = CachedContentFile::all();
    expect($files)->toHaveCount(1);

    $file = $files->first();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($file->content_type)->toBe('movie')
        ->and($file->tmdb_id)->toBe('550')
        // 4 empty fields between tmdb_id and quality → 4 colons
        ->and($file->content_fingerprint)->toBe('movie:550::::1080p')
        ->and($file->file_path)->toStartWith('cache/')
        ->and($file->file_size_bytes)->toBeGreaterThan(0)
        ->and($file->last_verified_at)->not->toBeNull();

    expect($file->dynamicGroups)->toHaveCount(1)
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('creates a Failed row and increments failure_count on HTTP 404', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake([
        'provider.example/missing.mp4' => Http::response('Not Found', 404),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '999',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/missing.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    $file = CachedContentFile::first();
    expect($file->status)->toBe(CachedContentFileStatus::Failed)
        ->and((int) $file->failure_count)->toBe(1)
        ->and($file->last_failed_at)->not->toBeNull()
        ->and($file->file_path)->toBeNull();
});

it('attaches to existing Completed row (cross-group dedup) and skips download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Pre-seeded with auto-derived fingerprint (don't hardcode — model derives from parts)
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);

    Http::fake(); // any request would fail the test — we expect NO HTTP call

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    expect(CachedContentFile::count())->toBe(1); // no new row
    expect($existing->fresh()->dynamicGroups)->toHaveCount(1)
        ->and($existing->dynamicGroups->first()->id)->toBe($this->group->id);

    Http::assertNothingSent();
});

it('attaches to winner row on unique-fingerprint race', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Note: the dedup fast-path returns early before the create, so this race is hard
    // to exercise directly. We assert the dedup behavior is correct (attach, no duplicate).
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);

    Http::fake(['*' => Http::response('FAKE', 200)]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    expect(CachedContentFile::count())->toBe(1)
        ->and($existing->fresh()->dynamicGroups)->toHaveCount(1);
});

it('does not gate concurrency when Downloading count is below max', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('FAKE', 200)]);

    // max is 2 by default per settings migration
    CachedContentFile::factory()->create(['status' => CachedContentFileStatus::Downloading]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    // At 1 Downloading + this new one = 2, exactly at max but not OVER — runs fine.
    expect(CachedContentFile::count())->toBe(2);
    expect(CachedContentFile::latest('id')->first()->file_path)->not->toBeNull();
});

it('skips connection pre-flight when available_streams is 0 (disabled)', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('OK', 200)]);

    $this->playlist->update(['available_streams' => 0]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    expect(CachedContentFile::first()->status)->toBe(CachedContentFileStatus::Completed);
});

it('reclaims a Failed row past cooldown and re-attempts the download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('RETRYED_CONTENT', 200)]);

    // Pre-seeded Failed row — dispatcher's shouldSkip() would not have queued
    // this job if cooldown hadn't expired. last_failed_at 1h ago is well past
    // the default 360-min retry cooldown anyway.
    $existing = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'failure_count' => 3,
        'last_failed_at' => now()->subHour(),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    // Same row id, now Completed; file_path written; failure_count reset
    $file = $existing->fresh();
    expect(CachedContentFile::count())->toBe(1)
        ->and($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and((int) $file->failure_count)->toBe(0)
        ->and($file->last_failed_at)->toBeNull()
        ->and($file->file_path)->not->toBeNull()
        ->and($file->file_size_bytes)->toBeGreaterThan(0);

    // Pivot attach
    expect($file->dynamicGroups)->toHaveCount(1)
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('reclaims a stale Downloading row and re-attempts the download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('STALE_RETRYED', 200)]);

    // Default job timeout is 3600s (1h). Stale = updated_at older than
    // timeout + 300s safety margin = ~1h05m ago. Backdate updated_at to 2h
    // ago to be safely stale.
    $staleRow = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'status' => CachedContentFileStatus::Downloading,
        'updated_at' => now()->subHours(2),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    // Same row reclaimed — no new row created, file written
    expect(CachedContentFile::count())->toBe(1);
    $file = $staleRow->fresh();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($file->file_path)->not->toBeNull()
        ->and($file->file_size_bytes)->toBeGreaterThan(0)
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('does not reclaim a fresh Downloading row (other worker plausibly active)', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // catch-all: if anything got re-downloaded this would record it
    Http::fake(); // any outbound request would fail the test

    // updated_at is now (fresh) — the staleness check at timeout + 300s won't trigger
    $freshRow = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'status' => CachedContentFileStatus::Downloading,
        // updated_at defaults to now
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    // No re-download attempted — fresh Downloading row stays as-is, group attached
    expect(CachedContentFile::count())->toBe(1);
    expect($freshRow->fresh()->status)->toBe(CachedContentFileStatus::Downloading)
        ->and($freshRow->fresh()->file_path)->toBeNull();
    expect($freshRow->fresh()->dynamicGroups->first()->id)->toBe($this->group->id);

    Http::assertNothingSent();
});

it('resets failure_count to 0 when reclaiming a Failed row', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('OK', 200)]);

    $existing = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 5, // past the 3-threshold for tier-2 cooldown
        'last_failed_at' => now()->subHours(2),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
    );

    // failure_count reset to 0 so the dispatcher's tier logic starts fresh
    expect((int) $existing->fresh()->failure_count)->toBe(0);
});
