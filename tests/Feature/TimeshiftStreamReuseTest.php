<?php

/**
 * Catchup players re-request the timeshift URL for every Range/seek. Requests for the same
 * programme window must reuse the proxy stream already serving it (no new stream, capacity
 * check or provider profile reservation), and live requests must never pick up a catchup stream.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);

    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);

    $this->playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
    ]);

    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'url' => 'http://provider.com/live/user/pass/1234.ts',
        'catchup' => '1',
    ]);

    $this->timeshiftKey = '60|2026-10-02:00-00-00||';

    $this->timeshiftRequest = fn (string $date = '2026-10-02:00-00-00') => Request::create('/timeshift', 'GET', [
        'timeshift_duration' => 60,
        'timeshift_date' => $date,
    ]);

    $this->proxyStream = fn (array $overrides = []) => array_replace_recursive([
        'stream_id' => 'catchup-stream-abc',
        'client_count' => 0,
        'last_access' => now()->toIso8601String(),
        'metadata' => [
            'original_channel_id' => (string) $this->channel->id,
            'original_playlist_uuid' => $this->playlist->uuid,
            'transcoding' => 'false',
            'timeshift_key' => $this->timeshiftKey,
        ],
    ], $overrides);

    Redis::shouldReceive('exists')->andReturn(0);
});

test('a seek for the same programme window reuses the idle timeshift stream', function () {
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [($this->proxyStream)()]]),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel, ($this->timeshiftRequest)());

    expect($url)->toContain('stream/catchup-stream-abc');
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'active_only=0'));
});

test('a new stream tagged with its programme window is created when no timeshift stream exists', function () {
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => []]),
        '*/streams' => Http::response(['stream_id' => 'new-catchup-stream']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel, ($this->timeshiftRequest)());

    expect($url)->toContain('stream/new-catchup-stream');
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/streams')
        && ($request['metadata']['timeshift_key'] ?? null) === $this->timeshiftKey);
});

test('an idle timeshift stream past the reuse window is not handed back', function () {
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [
            ($this->proxyStream)(['last_access' => now()->subMinute()->toIso8601String()]),
        ]]),
        '*/streams' => Http::response(['stream_id' => 'new-catchup-stream']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel, ($this->timeshiftRequest)());

    expect($url)->toContain('stream/new-catchup-stream');
});

test('a timeshift stream for a different programme window is not reused', function () {
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [($this->proxyStream)()]]),
        '*/streams' => Http::response(['stream_id' => 'other-window-stream']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel, ($this->timeshiftRequest)('2026-10-02:01-00-00'));

    expect($url)->toContain('stream/other-window-stream');
});

test('a live request never reuses a catchup stream for the same channel', function () {
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [
            ($this->proxyStream)(['client_count' => 1]),
        ]]),
        '*/streams' => Http::response(['stream_id' => 'new-live-stream']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);

    expect($url)->toContain('stream/new-live-stream');
});
