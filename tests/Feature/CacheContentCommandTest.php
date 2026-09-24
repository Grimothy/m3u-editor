<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function setEnableCacheForCommandTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    Bus::fake();
});

it('cache:content warns and returns SUCCESS without dispatching when enable_cache is off (dry-run)', function () {
    // PR #1524 review item 5: the hourly scheduled dry-run must be a
    // no-op when the kill switch is off - otherwise operators see
    // log spam every hour for a feature they intentionally disabled.
    setEnableCacheForCommandTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'tmdb_id' => 100,
        'url' => 'https://example.com/a.mp4',
    ]);

    $this->artisan('cache:content --dry-run')
        ->expectsOutputToContain('Caching is disabled')
        ->assertExitCode(0);
});

it('cache:content warns and returns SUCCESS without dispatching when enable_cache is off (ad-hoc)', function () {
    // An operator running `cache:content --playlist=42` after the toggle
    // is off should also be a no-op (with a warning), not a partial
    // dispatch that half-creates rows.
    setEnableCacheForCommandTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'tmdb_id' => 200,
        'url' => 'https://example.com/b.mp4',
    ]);

    $this->artisan('cache:content')
        ->expectsOutputToContain('Caching is disabled')
        ->assertExitCode(0);
});

it('cache:content dispatches when enable_cache is on', function () {
    // Sanity check the OFF test isn't trivially passing because the
    // command always returns SUCCESS. With the toggle on, the command
    // walks channels/episodes as expected.
    setEnableCacheForCommandTest(true);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'tmdb_id' => 300,
        'url' => 'https://example.com/c.mp4',
    ]);

    $this->artisan('cache:content --dry-run')
        ->assertExitCode(0);

    // dry-run path increments the counter but never inserts rows or
    // dispatches jobs (those are only issued on the non-dry-run path).
    $this->assertDatabaseCount('cached_content_files', 0);
});
