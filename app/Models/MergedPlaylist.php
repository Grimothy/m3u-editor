<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Pivots\MergedPlaylistPivot;
use App\Traits\HasDefaultAuth;
use App\Traits\ShortUrlTrait;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class MergedPlaylist extends Model
{
    use HasDefaultAuth;
    use HasFactory;
    use ShortUrlTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dummy_epg' => 'boolean',
        'dummy_epg_days' => 'integer',
        'dummy_epg_fallback_order' => 'array',
        'output_tvg_type' => 'boolean',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_networks_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'sort_by_channel_number' => 'boolean',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class,
        'disable_m3u_xtream_format' => 'boolean',
        'aiostreams_integration_id' => 'integer',
        'hdhr_enabled' => 'boolean',
        'm3u_enabled' => 'boolean',
        'xapi_enabled' => 'boolean',
        'xmltv_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    /**
     * The AIOStreams integration that this merged playlist is permitted to access.
     */
    public function aiostreamsIntegration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class, 'aiostreams_integration_id');
    }

    public function dvrSetting(): HasOne
    {
        return $this->hasOne(DvrSetting::class);
    }

    public function requestSetting(): HasOne
    {
        return $this->hasOne(PlaylistRequestSetting::class);
    }

    public function vodStreamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'vod_stream_profile_id');
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'merged_playlist_playlist')
            ->using(MergedPlaylistPivot::class)
            ->withPivot(['include_live', 'include_vod', 'include_series', 'only_unmatched']);
    }

    /**
     * IDs of the source playlists, optionally limited to those contributing a
     * content type ('live', 'vod' or 'series') via the per-source pivot toggles.
     * Ordered by playlist id: without an ORDER BY, Postgres returns pivot rows in
     * heap order, which changes as soon as a row is updated.
     *
     * @return array<int>
     */
    public function sourcePlaylistIds(?string $contentType = null): array
    {
        $sources = $this->playlists();

        // Not when(): its callback receives the Eloquent builder, on which wherePivot()
        // degrades to a dynamic where on a column literally named "pivot".
        if ($contentType !== null) {
            $sources->wherePivot("include_{$contentType}", true);
        }

        return $sources->orderBy('playlists.id')->pluck('playlists.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Source playlists that expose an Xtream API URL, shaped for the PlaylistAlias
     * credential-swap form. Mirrors CustomPlaylist::getSourcePlaylistsForAlias().
     *
     * @return array<int, array{id: int, name: string, url: string}>
     */
    public function getSourcePlaylistsForAlias(): array
    {
        return $this->playlists()
            ->get()
            ->map(function (Playlist $playlist): array {
                $url = $playlist->xtream_config['url'] ?? null;

                return [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'url' => $url ? rtrim($url, '/') : null,
                ];
            })
            ->filter(fn (array $config): bool => $config['url'] !== null)
            ->values()
            ->toArray();
    }

    public function channels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where(function ($query) {
            // Per-source content-type toggle: a channel only comes through if its
            // source playlist is configured to contribute that content type.
            $query->where(function ($q) {
                $q->where('channels.is_vod', false)->where('merged_playlist_playlist.include_live', true);
            })->orWhere(function ($q) {
                $q->where('channels.is_vod', true)->where('merged_playlist_playlist.include_vod', true);
            });
        })->where(fn ($query) => $query
            ->where('merged_playlist_playlist.only_unmatched', false)
            ->orWhereNotExists(fn ($matches) => $this->scopeToMergeProviders(
                $matches->selectRaw('1')
                    ->from('media_source_matches')
                    ->whereColumn('media_source_matches.media_channel_id', 'channels.id')
            )));
    }

    /**
     * Narrow a media_source_matches query to matches made by the playlists attached to this
     * merge, so a local item is only hidden when a source in the same merge already serves it.
     */
    private function scopeToMergeProviders(QueryBuilder $matches): QueryBuilder
    {
        return $matches->whereIn('media_source_matches.playlist_id', fn ($sources) => $sources
            ->select('merge_sources.playlist_id')
            ->from('merged_playlist_playlist as merge_sources')
            ->whereColumn('merge_sources.merged_playlist_id', 'merged_playlist_playlist.merged_playlist_id'));
    }

    /**
     * Series-level rule for only_unmatched sources: a local series is hidden once any of its
     * episodes is already provided by another source in the merge, so the same show is never
     * listed twice (the provider's entry plays the local episodes it matched).
     */
    private function onlyUnmatchedSeries(string $seriesIdColumn): Closure
    {
        return fn ($query) => $query
            ->where('merged_playlist_playlist.only_unmatched', false)
            ->orWhereNotExists(fn ($matches) => $this->scopeToMergeProviders(
                $matches->selectRaw('1')
                    ->from('episodes as matched_episodes')
                    ->join('media_source_matches', 'media_source_matches.media_episode_id', '=', 'matched_episodes.id')
                    ->whereColumn('matched_episodes.series_id', $seriesIdColumn)
            ));
    }

    public function groups(): HasManyThrough
    {
        return $this->hasManyThrough(
            Group::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function enabled_channels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }

    public function series(): HasManyThrough
    {
        return $this->hasManyThrough(
            Series::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where('merged_playlist_playlist.include_series', true)
            ->where($this->onlyUnmatchedSeries('series.id'));
    }

    public function enabled_series(): HasManyThrough
    {
        return $this->series()->where('enabled', true);
    }

    public function seasons(): HasManyThrough
    {
        return $this->hasManyThrough(
            Season::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where('merged_playlist_playlist.include_series', true)
            ->where($this->onlyUnmatchedSeries('seasons.series_id'));
    }

    public function episodes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Episode::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where('merged_playlist_playlist.include_series', true)
            ->where($this->onlyUnmatchedSeries('episodes.series_id'));
    }

    public function live_channels(): HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): HasManyThrough
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): HasManyThrough
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function playlistViewers(): MorphMany
    {
        return $this->morphMany(PlaylistViewer::class, 'viewerable');
    }

    public function enableProxy(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    // Check playlist user has access to proxy features
                    if (! $this->user?->canUseProxy()) {
                        return false;
                    }
                }

                return $value;
            }
        );
    }
}
