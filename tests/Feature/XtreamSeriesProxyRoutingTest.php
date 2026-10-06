<?php

/**
 * handleSeries() must decide proxying with the same Channel::needsProxy() rule as
 * handleVod(): a pooled-provider source playlist (profiles_enabled) forces the proxy
 * path even when enable_proxy is off, gated on the user's proxy permission.
 */

use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->makeEpisode = function (array $userAttributes, array $playlistAttributes): array {
        $user = User::factory()->create(array_merge(['name' => 'owner'], $userAttributes));
        $playlist = Playlist::factory()->for($user)->createQuietly(array_merge([
            'enable_proxy' => false,
            'profiles_enabled' => false,
            'xtream' => false,
        ], $playlistAttributes));
        $series = Series::factory()->for($user)->for($playlist)->create(['enabled' => true]);
        $season = Season::factory()->for($user)->for($playlist)->for($series)->create();
        $episode = Episode::factory()->for($user)->for($playlist)->for($series)->for($season)->create([
            'container_extension' => 'mp4',
            'url' => 'http://provider.example.com/series/u/p/123.mp4',
        ]);

        return [$user, $playlist, $episode];
    };

    $this->expectProxy = function (bool $proxied): void {
        $mock = Mockery::mock(M3uProxyService::class);
        if ($proxied) {
            $mock->shouldReceive('getEpisodeUrl')->once()->andReturn('http://proxy.test/redirected');
        } else {
            $mock->shouldNotReceive('getEpisodeUrl');
        }
        app()->instance(M3uProxyService::class, $mock);
    };
});

test('episode from a pooled-provider playlist routes via the proxy with enable_proxy off', function () {
    [$user, $playlist, $episode] = ($this->makeEpisode)(['permissions' => ['use_proxy']], ['profiles_enabled' => true]);
    ($this->expectProxy)(true);

    $this->get("/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4")
        ->assertRedirect('http://proxy.test/redirected');
});

test('pooled-provider episode is not proxied when the user cannot use the proxy', function () {
    [$user, $playlist, $episode] = ($this->makeEpisode)(['permissions' => []], ['profiles_enabled' => true]);
    ($this->expectProxy)(false);

    $this->get("/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4")
        ->assertRedirect('http://provider.example.com/series/u/p/123.mp4');
});

test('episode is redirected directly when no proxy flag applies', function () {
    [$user, $playlist, $episode] = ($this->makeEpisode)(['permissions' => ['use_proxy']], []);
    ($this->expectProxy)(false);

    $this->get("/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4")
        ->assertRedirect('http://provider.example.com/series/u/p/123.mp4');
});

test('episode routes via the proxy when the playlist enables it', function () {
    [$user, $playlist, $episode] = ($this->makeEpisode)(['permissions' => ['use_proxy']], ['enable_proxy' => true]);
    ($this->expectProxy)(true);

    $this->get("/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4")
        ->assertRedirect('http://proxy.test/redirected');
});

test('episode routes via the proxy when the request asks for it', function () {
    [$user, $playlist, $episode] = ($this->makeEpisode)(['permissions' => ['use_proxy']], []);
    ($this->expectProxy)(true);

    $this->get("/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4?proxy=true")
        ->assertRedirect('http://proxy.test/redirected');
});
