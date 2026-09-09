<?php

/**
 * When a channel whose source playlist pools provider profiles
 * (profiles_enabled = true) is streamed through a MergedPlaylist, the
 * XtreamStreamController must take the proxy path even though enable_proxy
 * is off on both the channel and the merged playlist - profile selection
 * and pool distribution only happen on the proxy path.
 */

use App\Models\Channel;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create([
        'name' => 'owner',
        'permissions' => ['use_proxy'],
    ]);

    config([
        'proxy.m3u_proxy_host' => 'http://localhost',
        'proxy.m3u_proxy_port' => 8765,
        'proxy.m3u_proxy_token' => 'test-token',
        'cache.default' => 'array',
    ]);
});

test('pooled-provider channel streamed through a merged playlist routes via the proxy', function () {
    // Source playlist pools provider profiles but has enable_proxy off.
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly([
        'enable_proxy' => false,
        'profiles_enabled' => true,
        'available_streams' => 0,
        'xtream_config' => [
            'url' => 'http://provider.example.com:8080',
            'username' => 'olduser',
            'password' => 'oldpass',
        ],
    ]);

    PlaylistProfile::factory()->for($this->user)->create([
        'playlist_id' => $sourcePlaylist->id,
        'is_primary' => true,
        'priority' => 0,
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'enabled' => true,
        'enable_proxy' => false,
        'url' => 'http://provider.example.com:8080/live/olduser/oldpass/1234.ts',
    ]);

    // Merged playlist wrapping the source, proxy off.
    $merged = MergedPlaylist::factory()->for($this->user)->create(['enable_proxy' => false]);
    $merged->playlists()->attach($sourcePlaylist->id);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'username' => 'merged-user',
        'password' => 'merged-pass',
    ]);
    $auth->assignTo($merged);

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 0,
            'total_clients' => 0,
        ]),
        '*/streams' => Http::response(['stream_id' => 'test-stream-id']),
        '*' => Http::response([], 200),
    ]);

    $response = $this->get("/live/merged-user/merged-pass/{$channel->id}.ts");

    $response->assertRedirect();

    // The proxy path was taken: a stream-create POST hit m3u-proxy rather than
    // a bare redirect to the raw channel URL.
    Http::assertSent(function (ClientRequest $request) {
        return $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/streams');
    });
});
