<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Network;
use App\Models\Playlist;
use Illuminate\Support\Facades\Log;

/**
 * Projects Networks into real Channel + Group rows on their network playlist,
 * so merged playlists, M3U/Xtream output and channel counts see network
 * content without special-casing (issue #1303).
 */
class NetworkChannelSyncService
{
    /**
     * Project every network that outputs to a network playlist into Channel
     * and Group rows, and drop projections for networks that no longer output
     * to it. Unlike merged/custom playlists (where the include_networks_in_m3u
     * toggle governs), a network playlist's whole purpose is exposing its
     * networks, so the projection is unconditional.
     */
    public function syncNetworksToPlaylist(Playlist $playlist): int
    {
        if (! $playlist->is_network_playlist) {
            return 0;
        }

        $networkIds = [];

        foreach ($playlist->networks()->get() as $network) {
            $this->syncNetworkAsChannel($playlist, $network);
            $networkIds[] = $network->id;
        }

        // Drop projections for networks that no longer output to this playlist.
        Channel::where('playlist_id', $playlist->id)
            ->whereNotNull('network_id')
            ->when($networkIds !== [], fn ($query) => $query->whereNotIn('network_id', $networkIds))
            ->get(['id', 'group_id'])
            ->each(function (Channel $channel): void {
                $channel->delete();
                $this->pruneGroupIfEmpty($channel->group_id);
            });

        Log::info('Projected networks to playlist', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'networks' => count($networkIds),
        ]);

        return count($networkIds);
    }

    /**
     * Sync a single network as a channel.
     */
    public function syncNetworkAsChannel(Playlist $playlist, Network $network, ?Group $group = null): Channel
    {
        if (! $group) {
            $group = $this->getOrCreateNetworksGroup($playlist, $network);
        }

        $channel = Channel::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'network_id' => $network->id,
            ],
            [
                'user_id' => $playlist->user_id,
                'name' => $network->name,
                'title' => $network->name,
                'url' => $network->stream_url,
                'logo' => $network->logo,
                'group_id' => $group->id,
                'group_internal' => $group->name,
                'channel' => $network->channel_number,
                'enabled' => $network->enabled,
                'is_vod' => false,
                'stream_id' => 'network-'.$network->id,
                'source_id' => 'network-'.$network->id,
            ]
        );

        return $channel;
    }

    /**
     * Refresh a network's projection after it changes: (re)create the channel
     * when missing, move it when the network is reassigned to another
     * playlist, and prune groups the projection no longer populates.
     */
    public function refreshNetworkChannel(Network $network): void
    {
        // Stale projections: rows in playlists the network no longer outputs to.
        $this->removeNetworkChannelsFor($network, $network->network_playlist_id);

        if ($network->network_playlist_id === null) {
            return;
        }

        $playlist = Playlist::find($network->network_playlist_id);

        if (! $playlist) {
            return;
        }

        $existing = Channel::where('network_id', $network->id)
            ->where('playlist_id', $playlist->id)
            ->first(['group_id']);

        $channel = $this->syncNetworkAsChannel($playlist, $network);

        // The network moved between groups: prune the one it left behind once
        // no other channel projects into it.
        if ($existing && $existing->group_id !== null && $existing->group_id !== $channel->group_id) {
            $this->pruneGroupIfEmpty($existing->group_id);
        }
    }

    /**
     * Remove a network's projected channels everywhere (or everywhere except
     * one playlist), pruning any groups left empty by the removal.
     */
    public function removeNetworkChannelsFor(Network $network, ?int $exceptPlaylistId = null): void
    {
        Channel::where('network_id', $network->id)
            ->when($exceptPlaylistId !== null, fn ($query) => $query->where('playlist_id', '!=', $exceptPlaylistId))
            ->get(['id', 'group_id'])
            ->each(function (Channel $channel): void {
                $channel->delete();
                $this->pruneGroupIfEmpty($channel->group_id);
            });
    }

    /**
     * Get or create the group for a network within a playlist.
     * Uses the network's configured group_name, falling back to "Networks".
     */
    protected function getOrCreateNetworksGroup(Playlist $playlist, ?Network $network = null): Group
    {
        return $this->getOrCreateGroup($playlist, $network?->effective_group_name ?? 'Networks');
    }

    /**
     * Get or create a group by name within a playlist. Exposed for the network
     * playlist's Xtream surface, which resolves stable numeric category IDs
     * backed by these projected rows.
     */
    public function getOrCreateGroup(Playlist $playlist, string $groupName): Group
    {
        return Group::firstOrCreate(
            [
                'playlist_id' => $playlist->id,
                'name' => $groupName,
            ],
            [
                'user_id' => $playlist->user_id,
                'name_internal' => $groupName,
                'enabled' => true,
            ]
        );
    }

    /**
     * Delete a group that no longer has any channels, so renamed or removed
     * networks don't leave ghost categories behind in merged output.
     */
    protected function pruneGroupIfEmpty(?int $groupId): void
    {
        if (! $groupId || Channel::where('group_id', $groupId)->exists()) {
            return;
        }

        Group::where('id', $groupId)->delete();
    }
}
