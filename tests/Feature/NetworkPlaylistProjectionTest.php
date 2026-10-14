<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;
use App\Services\NetworkChannelSyncService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'is_network_playlist' => true,
    ]);
});

it('projects a network into channel and group rows when created', function () {
    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Jellyfin Series',
    ]);

    $channel = Channel::where('network_id', $network->id)->first();

    expect($channel)->not->toBeNull()
        ->and($channel->playlist_id)->toBe($this->playlist->id)
        ->and($channel->name)->toBe($network->name)
        ->and($channel->enabled)->toBeTrue()
        ->and($channel->is_vod)->toBeFalse();

    $group = Group::find($channel->group_id);
    expect($group->name)->toBe('Jellyfin Series')
        ->and($group->playlist_id)->toBe($this->playlist->id);
});

it('reflects network updates in the projected channel', function () {
    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
    ]);

    $network->update(['name' => 'Renamed TV', 'enabled' => false]);

    $channel = Channel::where('network_id', $network->id)->first();
    expect($channel->name)->toBe('Renamed TV')
        ->and($channel->enabled)->toBeFalse();
});

it('moves the projected channel when the network is reassigned to another playlist', function () {
    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Sports',
    ]);

    $other = Playlist::factory()->for($this->user)->create(['is_network_playlist' => true]);

    $network->update(['network_playlist_id' => $other->id]);

    expect(Channel::where('network_id', $network->id)->pluck('playlist_id')->all())->toBe([$other->id])
        // The group left behind in the first playlist is pruned once empty.
        ->and(Group::where('playlist_id', $this->playlist->id)->count())->toBe(0);
});

it('removes the projected channel and emptied group when a network is deleted', function () {
    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Movies',
    ]);
    $groupId = Channel::where('network_id', $network->id)->value('group_id');

    $network->delete();

    expect(Channel::where('network_id', $network->id)->count())->toBe(0)
        ->and(Group::where('id', $groupId)->exists())->toBeFalse();
});

it('keeps a group other channels still use when one network leaves it', function () {
    $keep = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Shared',
    ]);
    $drop = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Shared',
    ]);

    $drop->delete();

    $channel = Channel::where('network_id', $keep->id)->first();
    expect($channel)->not->toBeNull()
        ->and(Group::where('id', $channel->group_id)->exists())->toBeTrue();
});

it('prunes projections for networks no longer attached when syncing a playlist', function () {
    $attached = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'In',
    ]);
    $detached = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Out',
    ]);
    // Detach without firing the refresh hook, simulating stale projections.
    $detached->updateQuietly(['network_playlist_id' => null]);

    app(NetworkChannelSyncService::class)->syncNetworksToPlaylist($this->playlist);

    $networkIds = Channel::where('playlist_id', $this->playlist->id)
        ->whereNotNull('network_id')
        ->pluck('network_id')
        ->all();

    expect($networkIds)->toBe([$attached->id]);
});

it('includes network playlist channels in a merged playlist and honours the include_live toggle', function () {
    Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'group_name' => 'Jellyfin',
    ]);

    $external = Playlist::factory()->for($this->user)->create();
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $external->id,
        'name' => 'External Channel',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach([
        $this->playlist->id => ['include_live' => true, 'include_vod' => false, 'include_series' => false],
        $external->id => ['include_live' => true, 'include_vod' => false, 'include_series' => false],
    ]);

    expect($merged->channels()->count())->toBe(2)
        ->and($merged->groups()->pluck('name')->unique()->values()->all())->toContain('Jellyfin');

    // Excluding the network playlist's live content removes its channels.
    $merged->playlists()->updateExistingPivot($this->playlist->id, ['include_live' => false]);

    expect($merged->channels()->count())->toBe(1)
        ->and($merged->channels()->pluck('channels.name')->all())->toBe(['External Channel']);
});
