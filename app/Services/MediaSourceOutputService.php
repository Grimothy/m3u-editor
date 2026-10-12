<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Output-side view of a provider playlist's VOD movies, series, episodes and groups.
 *
 * With "Include unmatched media server sources" on, a provider playlist also lists
 * the items from the owner's media server playlists that the provider does not
 * already carry (a media_source_matches row means the provider's own entry already
 * plays that local file). Every list and lookup an Xtream client or M3U consumer
 * goes through must use the same set, otherwise a local title would be listed and
 * then 404 when opened or played.
 *
 * Output only: sync, write and delete paths keep using the plain relations, so a
 * provider resync can never touch the local media rows.
 *
 * Playlists without the toggle (and every non-provider playlist type) get their own
 * relation back untouched. Aliases inherit the extra items unless they carry a
 * group/category filter, which fails closed because the filter names provider groups.
 */
class MediaSourceOutputService
{
    /**
     * VOD movies (and live channels, unchanged) the playlist outputs.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     */
    public static function channels($playlist): Relation|Builder
    {
        [$source, $mediaPlaylistIds] = self::resolve($playlist, series: false);

        if ($source === null) {
            return $playlist->channels();
        }

        return Channel::query()->where(fn (Builder $query) => $query
            ->where(self::ownRows($playlist, 'channels', 'channels'))
            ->orWhere(fn (Builder $extra) => $extra
                ->whereIn('channels.playlist_id', $mediaPlaylistIds)
                ->where('channels.is_vod', true)
                ->whereNotExists(self::movieIsMatched($source))));
    }

    /**
     * Series the playlist outputs. A local series is hidden once any of its
     * episodes is matched, so the same show is never listed twice.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     */
    public static function series($playlist): Relation|Builder
    {
        [$source, $mediaPlaylistIds] = self::resolve($playlist, series: true);

        if ($source === null) {
            return $playlist->series();
        }

        return Series::query()->where(fn (Builder $query) => $query
            ->where(self::ownRows($playlist, 'series', 'series'))
            ->orWhere(fn (Builder $extra) => $extra
                ->whereIn('series.playlist_id', $mediaPlaylistIds)
                ->whereNotExists(self::matchedEpisodeOfSeries($source, 'series.id'))));
    }

    /**
     * Episodes the playlist outputs, following the same series rule as series().
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist  $playlist
     */
    public static function episodes($playlist): Relation|Builder
    {
        [$source, $mediaPlaylistIds] = self::resolve($playlist, series: true);

        if ($source === null || ! method_exists($playlist, 'episodes')) {
            return $playlist->episodes();
        }

        return Episode::query()->where(fn (Builder $query) => $query
            ->where(self::ownRows($playlist, 'episodes', 'episodes'))
            ->orWhere(fn (Builder $extra) => $extra
                ->whereIn('episodes.playlist_id', $mediaPlaylistIds)
                ->whereNotExists(self::matchedEpisodeOfSeries($source, 'episodes.series_id'))));
    }

    /**
     * VOD groups the playlist outputs: its own plus the media server playlists'.
     * Empty groups (every item matched) are dropped by the caller through
     * scopeVisibleChannels().
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     */
    public static function vodGroups($playlist): Relation|Builder
    {
        [$source, $mediaPlaylistIds] = self::resolve($playlist, series: false);

        if ($source === null) {
            return $playlist->groups();
        }

        return Group::query()->where(fn (Builder $query) => $query
            ->where(self::ownRows($playlist, 'groups', 'groups'))
            ->orWhereIn('groups.playlist_id', $mediaPlaylistIds));
    }

    /**
     * Narrow a channels query (inside a group's whereHas) to what the playlist outputs,
     * so a local group whose titles are all matched does not show as an empty category.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     */
    public static function scopeVisibleChannels(Builder $channels, $playlist): void
    {
        [$source] = self::resolve($playlist, series: false);

        if ($source === null) {
            return;
        }

        $channels->where(fn (Builder $query) => $query
            ->where('channels.playlist_id', $source->id)
            ->orWhereNotExists(self::movieIsMatched($source)));
    }

    /**
     * Whether the item is a local title the playlist lists only through "Include
     * unmatched media server sources" (it lives on one of the owner's media server
     * playlists, not on the playlist being played). Such an item plays with its own
     * playlist's settings, the same as a matched swap, so the provider's URL transforms
     * and proxy options never touch the media URL.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     */
    public static function isLocalExtra($playlist, Channel|Episode $item): bool
    {
        [$source, $mediaPlaylistIds] = self::resolve($playlist, series: $item instanceof Episode);

        return $source !== null && in_array((int) $item->playlist_id, $mediaPlaylistIds, true);
    }

    /**
     * The provider playlist whose toggle is on, with its eligible media server
     * playlist ids, or [null, []] when the playlist outputs nothing extra.
     *
     * @return array{0: ?Playlist, 1: array<int, int>}
     */
    private static function resolve($playlist, bool $series): array
    {
        $source = $playlist instanceof PlaylistAlias ? $playlist->getEffectivePlaylist() : $playlist;

        if (! $source instanceof Playlist
            || ! $source->prefer_media_server_sources
            || ! $source->include_unmatched_media_server_sources) {
            return [null, []];
        }

        if ($playlist instanceof PlaylistAlias
            && ! empty($series ? $playlist->getAllowedCategoryNames() : $playlist->getAllowedVodGroupNames())) {
            return [null, []];
        }

        $mediaPlaylistIds = array_keys(app(MediaSourceMatchService::class)->eligibleIntegrationPlaylists($source));

        return $mediaPlaylistIds === [] ? [null, []] : [$source, $mediaPlaylistIds];
    }

    /**
     * The playlist's own rows as a where-group: its playlist_id for a provider
     * playlist, or its (alias-filtered) relation for an alias.
     */
    private static function ownRows($playlist, string $relation, string $table): Closure
    {
        if ($playlist instanceof PlaylistAlias) {
            return fn (Builder $query) => $query->whereIn("$table.id", $playlist->{$relation}()->select("$table.id"));
        }

        return fn (Builder $query) => $query->where("$table.playlist_id", $playlist->id);
    }

    /**
     * Correlated EXISTS body: a match row says the provider already serves this local movie.
     */
    private static function movieIsMatched(Playlist $source): Closure
    {
        return fn (QueryBuilder $matches) => $matches->selectRaw('1')
            ->from('media_source_matches')
            ->whereColumn('media_source_matches.media_channel_id', 'channels.id')
            ->where('media_source_matches.playlist_id', $source->id);
    }

    /**
     * Correlated EXISTS body: some episode of the local series is already matched.
     */
    private static function matchedEpisodeOfSeries(Playlist $source, string $seriesIdColumn): Closure
    {
        return fn (QueryBuilder $matches) => $matches->selectRaw('1')
            ->from('episodes as matched_episodes')
            ->join('media_source_matches', 'media_source_matches.media_episode_id', '=', 'matched_episodes.id')
            ->whereColumn('matched_episodes.series_id', $seriesIdColumn)
            ->where('media_source_matches.playlist_id', $source->id);
    }
}
