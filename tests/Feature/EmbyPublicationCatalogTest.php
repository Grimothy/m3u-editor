<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\DynamicGroup;
use App\Models\EmbyLibraryMapping;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a deterministic managed movie catalog with safe metadata and playback URLs', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']);
    $channel = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Provider Name',
        'title' => 'John Wick',
        'title_custom' => 'John Wick: Chapter 4',
        'url' => 'https://provider-user:provider-secret@provider.invalid/movie.mkv',
        'container_extension' => 'mkv',
        'tmdb_id' => 603692,
        'imdb_id' => 'tt10366206',
        'year' => 2023,
        'edition' => 'Theatrical',
        'info' => [
            'original_title' => 'John Wick: Chapter 4',
            'plot' => 'John Wick uncovers a path to defeating The High Table.',
            'genres' => ['Action', 'Thriller'],
            'release_date' => '2023-03-24',
            'vote_average' => 7.7,
            'runtime' => 170,
            'mpaa_rating' => 'R',
            'director' => 'Chad Stahelski',
            'cast' => [
                ['name' => 'Keanu Reeves', 'character' => 'John Wick', 'profile_path' => '/x1MKxQkWwHB5WmpjY9uMwW7D7Ck.jpg'],
                ['name' => 'Donnie Yen', 'character' => 'Caine'],
            ],
            'studios' => [
                ['id' => 923, 'name' => 'Lionsgate'],
                ['id' => 5, 'name' => 'Thunder Road Pictures'],
            ],
            'production_countries' => [
                ['iso_3166_1' => 'US', 'name' => 'United States of America'],
            ],
            'tagline' => 'No way back. One way out.',
            'poster_path' => '/vZloFAK7NmvMGKE7VkF5UHaz0I.jpg',
            'backdrop_path' => '/7I6VUdPj6tQECNHdviJkUHi2vwb.jpg',
        ],
        'movie_data' => [
            'releasedate' => '2023-03-24 00:00:00',
        ],
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'hevc',
                'width' => 3840,
                'height' => 2160,
                'color_transfer' => 'smpte2084',
                'tags' => [],
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'eac3',
                'tags' => ['language' => 'eng'],
            ]],
        ],
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::create([
        'media_server_integration_id' => $integration->id,
        'user_id' => $user->id,
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => $group->name,
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'options' => ['nfo' => true, 'versions' => true],
    ]);

    $first = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $second = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $item = $first['items'][0];
    $variant = $item['variants'][0];

    expect($first)->toBe($second)
        ->and($first['mapping_uuid'])->toBe($mapping->uuid)
        ->and($first['full_snapshot'])->toBeTrue()
        ->and($first['revision'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($item['canonical_id'])->toBe('movie:tmdb:603692')
        ->and($item['media_type'])->toBe('movie')
        ->and($item['display_title'])->toBe('John Wick: Chapter 4')
        ->and($item['original_title'])->toBe('John Wick: Chapter 4')
        ->and($item['original_title_source'])->toBe('info.original_title')
        ->and($item['year'])->toBe(2023)
        ->and($item['ids'])->toBe([
            'tmdb' => 603692,
            'tvdb' => null,
            'imdb' => 'tt10366206',
        ])
        ->and($item['groups'])->toBe(['Action'])
        ->and($item['relative_folder'])->toBe('john-wick-chapter-4-2023')
        ->and($item['base_filename'])->toBe('john-wick-chapter-4-2023')
        ->and($item['nfo']['plot'])->toContain('High Table')
        ->and($item['nfo']['outline'])->toBe('John Wick uncovers a path to defeating The High Table.')
        ->and($item['nfo']['premiered'])->toBe('2023-03-24')
        ->and($item['nfo']['rating'])->toBe(7.7)
        ->and($item['nfo']['runtime'])->toBe(170)
        ->and($item['nfo']['mpaa'])->toBe('R')
        ->and($item['nfo']['director'])->toBe('Chad Stahelski')
        ->and($item['nfo']['cast'])->toBe([
            [
                'name' => 'Keanu Reeves',
                'role' => 'John Wick',
                'thumb' => 'https://image.tmdb.org/t/p/w185/x1MKxQkWwHB5WmpjY9uMwW7D7Ck.jpg',
            ],
            ['name' => 'Donnie Yen', 'role' => 'Caine', 'thumb' => null],
        ])
        ->and($item['nfo']['studio'])->toBe(['Lionsgate', 'Thunder Road Pictures'])
        ->and($item['nfo']['country'])->toBe(['United States of America'])
        ->and($item['nfo']['tagline'])->toBe('No way back. One way out.')
        ->and($item['nfo']['poster'])->toBe('https://image.tmdb.org/t/p/original/vZloFAK7NmvMGKE7VkF5UHaz0I.jpg')
        ->and($item['nfo']['fanart'])->toBe('https://image.tmdb.org/t/p/original/7I6VUdPj6tQECNHdviJkUHi2vwb.jpg')
        ->and($variant['key'])->toBe('2160p-hdr-hevc-eac3-eng-theatrical')
        ->and($variant['preferred']['playback_url'])->toBe(
            "https://m3u-editor.test/movie/tuner/secret/{$channel->id}.mkv?proxy=true"
        )
        ->and($variant['failover'])->toBe([])
        ->and($variant['technical_metadata'])->toBe($channel->stream_stats)
        ->and(json_encode($first))->not->toContain('provider.invalid')
        ->and(json_encode($first))->not->toContain('provider-secret');
});

it('keeps uncertain movie identities separate and excludes disabled or foreign sources', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Unsorted', 'type' => 'vod']);
    $channel = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'uuid' => '3ca47b68-9e2e-4b99-980f-dcae73b2ba67',
        'enabled' => true,
        'is_vod' => true,
        'title' => '../../Untitled / Film',
        'title_custom' => null,
        'name_custom' => null,
        'tmdb_id' => null,
        'tvdb_id' => null,
        'imdb_id' => null,
        'year' => null,
        'edition' => null,
        'info' => null,
        'movie_data' => null,
        'stream_stats' => null,
        'container_extension' => null,
    ]);
    Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => false,
        'is_vod' => true,
        'title' => 'Disabled Movie',
        'tmdb_id' => 1,
    ]);
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    Channel::factory()->for($otherUser)->for($otherPlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Foreign Movie',
        'tmdb_id' => 2,
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => $group->name,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $item = $catalog['items'][0];

    expect($catalog['items'])->toHaveCount(1)
        ->and($item['canonical_id'])->toBe(
            'movie:title:untitled-film:unknown:'.hash('sha256', $channel->uuid)
        )
        ->and($item['relative_folder'])->toBe('untitled-film')
        ->and($item['base_filename'])->toBe('untitled-film')
        ->and($item['ids'])->toBe(['tmdb' => null, 'tvdb' => null, 'imdb' => null])
        ->and($item['nfo']['outline'])->toBeNull()
        ->and($item['nfo']['premiered'])->toBeNull()
        ->and($item['nfo']['rating'])->toBeNull()
        ->and($item['nfo']['runtime'])->toBeNull()
        ->and($item['nfo']['mpaa'])->toBeNull()
        ->and($item['nfo']['director'])->toBeNull()
        ->and($item['nfo']['cast'])->toBe([])
        ->and($item['nfo']['studio'])->toBe([])
        ->and($item['nfo']['country'])->toBe([])
        ->and($item['nfo']['tagline'])->toBeNull()
        ->and($item['nfo']['poster'])->toBeNull()
        ->and($item['nfo']['fanart'])->toBeNull()
        ->and($item['variants'][0]['key'])->toBe('unknown-unknown-unknown-unknown-unknown-unknown')
        ->and(json_encode($catalog))->not->toContain('Disabled Movie')
        ->and(json_encode($catalog))->not->toContain('Foreign Movie')
        ->and($item['variants'][0]['preferred']['source_id'])->toBe($channel->id);
});

it('separates visible variants while ordering same-class provider failover', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Movies', 'type' => 'vod']);
    $hdStats = [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'height' => 1080, 'color_transfer' => 'bt709']],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'tags' => ['language' => 'eng']]],
    ];
    $firstProvider = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Dune',
        'tmdb_id' => 438631,
        'year' => 2021,
        'edition' => 'Theatrical',
        'sort' => 20,
        'stream_stats' => $hdStats,
        'container_extension' => 'mkv',
    ]);
    $preferredProvider = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Dune',
        'tmdb_id' => 438631,
        'year' => 2021,
        'edition' => 'Theatrical',
        'sort' => 10,
        'stream_stats' => $hdStats,
        'container_extension' => 'mkv',
    ]);
    $uhdProvider = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Dune',
        'tmdb_id' => 438631,
        'year' => 2021,
        'edition' => 'Theatrical',
        'sort' => 5,
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'hevc', 'height' => 2160, 'color_transfer' => 'smpte2084']],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'truehd', 'tags' => ['language' => 'eng']]],
        ],
        'container_extension' => 'mkv',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => $group->name,
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $variants = collect($catalog['items'][0]['variants'])->keyBy('key');

    expect($variants)->toHaveCount(2)
        ->and($variants->keys()->all())->toBe([
            '1080p-sdr-h264-aac-eng-theatrical',
            '2160p-hdr-hevc-truehd-eng-theatrical',
        ])
        ->and($variants->get('1080p-sdr-h264-aac-eng-theatrical')['preferred']['source_id'])
        ->toBe($preferredProvider->id)
        ->and($variants->get('1080p-sdr-h264-aac-eng-theatrical')['failover'])
        ->toHaveCount(1)
        ->and($variants->get('1080p-sdr-h264-aac-eng-theatrical')['failover'][0]['source_id'])
        ->toBe($firstProvider->id)
        ->and($variants->get('2160p-hdr-hevc-truehd-eng-theatrical')['preferred']['source_id'])
        ->toBe($uhdProvider->id)
        ->and($variants->get('2160p-hdr-hevc-truehd-eng-theatrical')['failover'])->toBe([]);
});

it('includes configured provider failover candidates without exposing provider URLs', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Movies', 'type' => 'vod']);
    $stats = [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'height' => 1080, 'color_transfer' => 'bt709']],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'tags' => ['language' => 'eng']]],
    ];
    $primary = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Arrival',
        'tmdb_id' => 329865,
        'edition' => null,
        'url' => 'https://primary.invalid/arrival.mkv',
        'stream_stats' => $stats,
        'container_extension' => 'mkv',
    ]);
    $failover = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Arrival',
        'tmdb_id' => 329865,
        'edition' => null,
        'url' => 'https://backup-user:backup-secret@backup.invalid/arrival.mkv',
        'stream_stats' => $stats,
        'container_extension' => 'mkv',
        'is_aio_failover_clone' => true,
    ]);
    ChannelFailover::create([
        'user_id' => $user->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $failover->id,
        'sort' => 1,
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => $group->name,
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $variant = $catalog['items'][0]['variants'][0];

    expect($variant['preferred']['source_id'])->toBe($primary->id)
        ->and($variant['failover'])->toHaveCount(1)
        ->and($variant['failover'][0]['source_id'])->toBe($failover->id)
        ->and($variant['failover'][0]['playback_url'])->toContain("/movie/tuner/secret/{$failover->id}.mkv")
        ->and(json_encode($catalog))->not->toContain('backup.invalid')
        ->and(json_encode($catalog))->not->toContain('backup-secret');
});

it('builds canonical series and episode catalog shapes with local NFO data', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $category = Category::factory()->for($user)->for($playlist)->create(['name' => 'Drama']);
    $series = Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
        'name' => 'Dark',
        'enabled' => true,
        'tmdb_id' => null,
        'tvdb_id' => 334824,
        'imdb_id' => 'tt5753856',
        'release_date' => '2017-12-01',
        'plot' => 'A missing child sets four families on a frantic hunt for answers.',
        'genre' => 'Drama, Mystery',
        'cover' => 'https://image.tmdb.org/t/p/w500/apbrbWs8M9lyOpJYU5WXrpFbk1Z.jpg',
        'backdrop_path' => [],
        'metadata' => [
            'original_name' => 'Dark',
            'plot' => 'A missing child sets four families on a frantic hunt for answers.',
            'vote_average' => 8.4,
            'content_rating' => 'TV-MA',
            'networks' => [['id' => 213, 'name' => 'Netflix']],
            'backdrop_path' => '/xwxPQivGupvHTwJn3FpbXdLyJpf.jpg',
        ],
    ]);
    $season = Season::factory()->for($user)->for($playlist)->for($category)->for($series)->createQuietly([
        'name' => 'Season 1',
        'season_number' => 1,
    ]);
    $episode = Episode::factory()->for($user)->for($playlist)->for($series)->for($season)->createQuietly([
        'enabled' => true,
        'title' => 'Secrets',
        'season' => 1,
        'episode_num' => 1,
        'tmdb_id' => 123456,
        'url' => 'https://provider.invalid/dark-s01e01.mkv',
        'container_extension' => 'mkv',
        'info' => [
            'original_title' => 'Geheimnisse',
            'plot' => 'The disappearance exposes old secrets.',
            'tvdb_id' => 654321,
            'imdb_id' => 'tt7315158',
            'air_date' => '2017-12-01',
            'vote_average' => 8.1,
            'runtime' => 52,
            'still_path' => '/mL6q4p2YSxYbEgm4sZsQt3ZeSoh.jpg',
        ],
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'height' => 1080, 'color_transfer' => 'bt709']],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'tags' => ['language' => 'deu']]],
        ],
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'series_category',
        'source_identifier' => (string) $category->id,
        'source_label' => $category->name,
        'target_library_name' => 'Managed TV',
        'collection_type' => 'tvshows',
        'output_path' => '/srv/emby/managed/tv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $seriesItem = $catalog['items'][0];
    $episodeItem = $seriesItem['episodes'][0];

    expect($seriesItem['canonical_id'])->toBe('series:tvdb:334824')
        ->and($seriesItem['media_type'])->toBe('series')
        ->and($seriesItem['display_title'])->toBe('Dark')
        ->and($seriesItem['original_title'])->toBe('Dark')
        ->and($seriesItem['original_title_source'])->toBe('metadata.original_name')
        ->and($seriesItem['year'])->toBe(2017)
        ->and($seriesItem['ids'])->toBe([
            'tmdb' => null,
            'tvdb' => 334824,
            'imdb' => 'tt5753856',
        ])
        ->and($seriesItem['relative_folder'])->toBe('dark-2017')
        ->and($seriesItem['nfo']['plot'])->toContain('missing child')
        ->and($seriesItem['nfo']['outline'])->toContain('missing child')
        ->and($seriesItem['nfo']['premiered'])->toBe('2017-12-01')
        ->and($seriesItem['nfo']['rating'])->toBe(8.4)
        ->and($seriesItem['nfo']['mpaa'])->toBe('TV-MA')
        ->and($seriesItem['nfo']['studio'])->toBe(['Netflix'])
        ->and($seriesItem['nfo']['poster'])->toBe('https://image.tmdb.org/t/p/w500/apbrbWs8M9lyOpJYU5WXrpFbk1Z.jpg')
        ->and($seriesItem['nfo']['fanart'])->toBe('https://image.tmdb.org/t/p/original/xwxPQivGupvHTwJn3FpbXdLyJpf.jpg')
        ->and($episodeItem['canonical_id'])->toBe('episode:tmdb:123456')
        ->and($episodeItem['series_canonical_id'])->toBe('series:tvdb:334824')
        ->and($episodeItem['media_type'])->toBe('episode')
        ->and($episodeItem['display_title'])->toBe('Secrets')
        ->and($episodeItem['original_title'])->toBe('Geheimnisse')
        ->and($episodeItem['season_number'])->toBe(1)
        ->and($episodeItem['episode_number'])->toBe(1)
        ->and($episodeItem['relative_folder'])->toBe('season-01')
        ->and($episodeItem['base_filename'])->toBe('dark-s01e01-secrets')
        ->and($episodeItem['ids'])->toBe([
            'tmdb' => 123456,
            'tvdb' => 654321,
            'imdb' => 'tt7315158',
        ])
        ->and($episodeItem['nfo']['showtitle'])->toBe('Dark')
        ->and($episodeItem['nfo']['aired'])->toBe('2017-12-01')
        ->and($episodeItem['nfo']['rating'])->toBe(8.1)
        ->and($episodeItem['nfo']['runtime'])->toBe(52)
        ->and($episodeItem['nfo']['thumb'])->toBe('https://image.tmdb.org/t/p/original/mL6q4p2YSxYbEgm4sZsQt3ZeSoh.jpg')
        ->and($episodeItem['variants'][0]['preferred']['playback_url'])->toBe(
            "https://m3u-editor.test/series/tuner/secret/{$episode->id}.mkv?proxy=true"
        )
        ->and(json_encode($catalog))->not->toContain('provider.invalid');
});

it('normalizes xtream movie ratings, runtimes and string cast data in the nfo payload', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Movies', 'type' => 'vod']);
    Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Xtream Movie',
        'tmdb_id' => 42,
        'info' => [
            'cast' => 'First Actor, Second Actor',
        ],
        'movie_data' => [
            'rating_5based' => 4,
            'duration_secs' => 7500,
            'country' => 'France',
            'movie_image' => 'https://provider.invalid/poster.jpg',
        ],
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => $group->name,
    ]);

    $nfo = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret')['items'][0]['nfo'];

    expect($nfo['rating'])->toBe(8)
        ->and($nfo['runtime'])->toBe(125.0)
        ->and($nfo['cast'])->toBe([
            ['name' => 'First Actor', 'role' => null, 'thumb' => null],
            ['name' => 'Second Actor', 'role' => null, 'thumb' => null],
        ])
        ->and($nfo['country'])->toBe(['France'])
        ->and($nfo['poster'])->toBe('https://provider.invalid/poster.jpg');
});

it('nulls enriched series and episode nfo keys when metadata is missing', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $category = Category::factory()->for($user)->for($playlist)->create(['name' => 'Sparse']);
    $series = Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
        'name' => 'Sparse Show',
        'enabled' => true,
        'tmdb_id' => 777,
        'release_date' => null,
        'plot' => null,
        'genre' => '',
        'cover' => null,
        'backdrop_path' => null,
        'metadata' => null,
    ]);
    $season = Season::factory()->for($user)->for($playlist)->for($category)->for($series)->createQuietly([
        'season_number' => 1,
    ]);
    Episode::factory()->for($user)->for($playlist)->for($series)->for($season)->createQuietly([
        'enabled' => true,
        'title' => 'Sparse Episode',
        'season' => 1,
        'episode_num' => 1,
        'info' => null,
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'series_category',
        'source_identifier' => (string) $category->id,
        'source_label' => $category->name,
        'collection_type' => 'tvshows',
    ]);

    $seriesItem = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret')['items'][0];
    $episodeNfo = $seriesItem['episodes'][0]['nfo'];

    expect($seriesItem['nfo']['outline'])->toBeNull()
        ->and($seriesItem['nfo']['premiered'])->toBeNull()
        ->and($seriesItem['nfo']['rating'])->toBeNull()
        ->and($seriesItem['nfo']['mpaa'])->toBeNull()
        ->and($seriesItem['nfo']['studio'])->toBe([])
        ->and($seriesItem['nfo']['poster'])->toBeNull()
        ->and($seriesItem['nfo']['fanart'])->toBeNull()
        ->and($episodeNfo['showtitle'])->toBe('Sparse Show')
        ->and($episodeNfo['aired'])->toBeNull()
        ->and($episodeNfo['rating'])->toBeNull()
        ->and($episodeNfo['runtime'])->toBeNull()
        ->and($episodeNfo['thumb'])->toBeNull();
});

it('merges episodes from duplicate provider series identities', function () {
    $user = User::factory()->create();
    $firstPlaylist = Playlist::factory()->for($user)->createQuietly();
    $secondPlaylist = Playlist::factory()->for($user)->createQuietly();
    $firstCategory = Category::factory()->for($user)->for($firstPlaylist)->create(['name' => 'Drama']);
    $secondCategory = Category::factory()->for($user)->for($secondPlaylist)->create(['name' => 'Drama']);
    $firstSeries = Series::factory()->for($user)->for($firstPlaylist)->for($firstCategory)->createQuietly([
        'name' => 'Shared Series',
        'enabled' => true,
        'tvdb_id' => 12345,
    ]);
    $secondSeries = Series::factory()->for($user)->for($secondPlaylist)->for($secondCategory)->createQuietly([
        'name' => 'Shared Series',
        'enabled' => true,
        'tvdb_id' => 12345,
    ]);
    $firstSeason = Season::factory()->for($user)->for($firstPlaylist)->for($firstCategory)->for($firstSeries)->createQuietly([
        'season_number' => 1,
    ]);
    $secondSeason = Season::factory()->for($user)->for($secondPlaylist)->for($secondCategory)->for($secondSeries)->createQuietly([
        'season_number' => 1,
    ]);
    Episode::factory()->for($user)->for($firstPlaylist)->for($firstSeries)->for($firstSeason)->createQuietly([
        'enabled' => true,
        'title' => 'First Episode',
        'season' => 1,
        'episode_num' => 1,
        'tmdb_id' => null,
    ]);
    Episode::factory()->for($user)->for($secondPlaylist)->for($secondSeries)->for($secondSeason)->createQuietly([
        'enabled' => true,
        'title' => 'Second Episode',
        'season' => 1,
        'episode_num' => 2,
        'tmdb_id' => null,
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'collection_type' => 'tvshows',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');

    expect($catalog['items'])->toHaveCount(1)
        ->and($catalog['items'][0]['canonical_id'])->toBe('series:tvdb:12345')
        ->and(array_column($catalog['items'][0]['episodes'], 'episode_number'))->toBe([1, 2]);
});

it('scopes custom playlist group mappings to the selected group', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Source', 'type' => 'vod']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly();
    $included = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Included Movie',
        'group' => 'Favorites',
        'tmdb_id' => 10,
    ]);
    $excluded = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Excluded Movie',
        'group' => 'Other',
        'tmdb_id' => 20,
    ]);
    $customPlaylist->channels()->attach([$included->id, $excluded->id]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'custom_playlist_group',
        'source_identifier' => (string) $customPlaylist->id,
        'source_label' => 'Favorites',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');

    expect($catalog['items'])->toHaveCount(1)
        ->and($catalog['items'][0]['display_title'])->toBe('Included Movie')
        ->and($catalog['items'][0]['groups'])->toBe(['Favorites'])
        ->and(json_encode($catalog))->not->toContain('Excluded Movie');
});

it('defers unresolved managed mappings from the user publication catalog', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'target_library_id' => null,
        'is_managed' => true,
        'enabled' => true,
        'status' => 'planned',
        'status_summary' => 'Unsafe stale plan',
        'last_planned_revision' => 'unsafe-revision',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildForUser($user, 'tuner', 'secret');

    expect($catalog['mappings'])->toBeEmpty()
        ->and($mapping->refresh()->status)->toBe('pending')
        ->and($mapping->status_summary)->toBe('Pending')
        ->and($mapping->error_summary)->toBeNull()
        ->and($mapping->last_planned_revision)->toBeNull();
});

it('builds a deterministic full user snapshot and records only enabled planned revisions', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $enabled = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All Movies',
        'enabled' => true,
    ]);
    $disabled = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => 'disabled',
        'source_label' => 'Disabled Movies',
        'enabled' => false,
    ]);

    $first = app(EmbyPublicationCatalogService::class)->buildForUser($user, 'tuner', 'secret');
    $second = app(EmbyPublicationCatalogService::class)->buildForUser($user, 'tuner', 'secret');

    expect($first)->toBe($second)
        ->and($first['api_version'])->toBe(1)
        ->and($first['full_snapshot'])->toBeTrue()
        ->and($first['mappings'])->toHaveCount(1)
        ->and($first['mappings'][0]['mapping_uuid'])->toBe($enabled->uuid)
        ->and($first['revision'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($enabled->refresh()->last_planned_revision)->toBe($first['mappings'][0]['revision'])
        ->and($enabled->status)->toBe('planned')
        ->and($disabled->refresh()->last_planned_revision)->toBeNull();
});

it('builds dynamic group movie mappings from current membership and updates their revision', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Movies', 'type' => 'vod']);
    $dynamicGroup = DynamicGroup::factory()->for($user)->for($playlist)->create([
        'type' => 'vod',
        'name' => 'Current Movies',
        'last_synced_at' => now(),
    ]);

    $firstChannel = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'First Movie',
        'tmdb_id' => 1,
    ]);
    $nextChannel = Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Next Movie',
        'tmdb_id' => 2,
    ]);
    $dynamicGroup->channels()->attach($firstChannel);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'dynamic_group',
        'source_identifier' => (string) $dynamicGroup->id,
        'source_label' => $dynamicGroup->name,
        'collection_type' => 'movies',
    ]);

    $first = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');
    $second = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');

    $dynamicGroup->channels()->sync([$nextChannel->id]);

    $updated = app(EmbyPublicationCatalogService::class)->buildMapping($mapping, 'tuner', 'secret');

    expect($first)->toBe($second)
        ->and($first['items'])->toHaveCount(1)
        ->and($first['items'][0]['display_title'])->toBe('First Movie')
        ->and($updated['items'])->toHaveCount(1)
        ->and($updated['items'][0]['display_title'])->toBe('Next Movie')
        ->and($updated['revision'])->not->toBe($first['revision']);
});

it('builds dynamic group series mappings from current membership and empties stale sources', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $category = Category::factory()->for($user)->for($playlist)->create(['name' => 'Dynamic Shows']);
    $dynamicGroup = DynamicGroup::factory()->for($user)->for($playlist)->create([
        'type' => 'series',
        'name' => 'Current Shows',
        'last_synced_at' => now(),
    ]);
    $firstSeries = Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
        'enabled' => true, 'name' => 'First Show', 'tmdb_id' => 101,
    ]);
    $secondSeries = Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
        'enabled' => true, 'name' => 'Second Show', 'tmdb_id' => 202,
    ]);
    $dynamicGroup->series()->attach($firstSeries);
    $firstSeason = Season::factory()->for($user)->for($playlist)->for($category)->for($firstSeries)->createQuietly(['season_number' => 1]);
    $secondSeason = Season::factory()->for($user)->for($playlist)->for($category)->for($secondSeries)->createQuietly(['season_number' => 1]);
    Episode::factory()->for($user)->for($playlist)->for($firstSeries)->for($firstSeason)->createQuietly([
        'enabled' => true, 'title' => 'First Episode', 'season' => 1, 'episode_num' => 1, 'tmdb_id' => 1001,
    ]);
    Episode::factory()->for($user)->for($playlist)->for($secondSeries)->for($secondSeason)->createQuietly([
        'enabled' => true, 'title' => 'Second Episode', 'season' => 1, 'episode_num' => 1, 'tmdb_id' => 2001,
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'dynamic_group',
        'source_identifier' => (string) $dynamicGroup->id,
        'source_label' => $dynamicGroup->name,
        'collection_type' => 'tvshows',
    ]);

    $first = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    $dynamicGroup->series()->sync([$secondSeries->id]);
    $updated = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    $dynamicGroup->update(['enabled' => false]);
    $stale = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);

    expect($first['items'][0]['display_title'])->toBe('First Show')
        ->and($first['items'][0]['episodes'][0]['display_title'])->toBe('First Episode')
        ->and($updated['items'][0]['display_title'])->toBe('Second Show')
        ->and($updated['items'][0]['episodes'][0]['display_title'])->toBe('Second Episode')
        ->and($updated['revision'])->not->toBe($first['revision'])
        ->and($stale['items'])->toBe([]);
});
