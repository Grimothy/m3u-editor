<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\MediaServerService;
use App\Services\WebDavMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

it('can create a webdav media server integration', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'My NAS WebDAV',
        'type' => 'webdav',
        'host' => 'nas.local',
        'port' => 5005,
        'ssl' => false,
        'webdav_username' => 'admin',
        'webdav_password' => 'secret123',
        'enabled' => true,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'auto_sync' => true,
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['name' => 'Movies', 'path' => '/movies', 'type' => 'movies'],
            ['name' => 'TV Shows', 'path' => '/tvshows', 'type' => 'tvshows'],
        ],
    ]);

    expect($integration)->toBeInstanceOf(MediaServerIntegration::class);
    expect($integration->name)->toBe('My NAS WebDAV');
    expect($integration->type)->toBe('webdav');
    expect($integration->host)->toBe('nas.local');
    expect($integration->port)->toBe(5005);
    expect($integration->webdav_username)->toBe('admin');
});

it('can check if integration is webdav type', function () {
    $webdav = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $jellyfin = MediaServerIntegration::create([
        'name' => 'Jellyfin Server',
        'type' => 'jellyfin',
        'host' => 'jellyfin.local',
        'port' => 8096,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($webdav->isWebDav())->toBeTrue();
    expect($webdav->isLocal())->toBeFalse();
    expect($webdav->isJellyfin())->toBeFalse();

    expect($jellyfin->isWebDav())->toBeFalse();
    expect($jellyfin->isJellyfin())->toBeTrue();
});

it('uses local path config for both local and webdav types', function () {
    $webdav = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $local = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    $jellyfin = MediaServerIntegration::create([
        'name' => 'Jellyfin Server',
        'type' => 'jellyfin',
        'host' => 'jellyfin.local',
        'port' => 8096,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($webdav->usesLocalPathConfig())->toBeTrue();
    expect($local->usesLocalPathConfig())->toBeTrue();
    expect($jellyfin->usesLocalPathConfig())->toBeFalse();
});

it('webdav requires network connectivity', function () {
    $webdav = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $local = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    expect($webdav->requiresNetwork())->toBeTrue();
    expect($local->requiresNetwork())->toBeFalse();
});

it('can create webdav service from integration', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = MediaServerService::make($integration);

    expect($service)->toBeInstanceOf(WebDavMediaService::class);
});

it('hides webdav credentials in serialization', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'webdav_username' => 'admin',
        'webdav_password' => 'secret123',
        'user_id' => $this->user->id,
    ]);

    $array = $integration->toArray();

    expect($array)->not->toHaveKey('webdav_username');
    expect($array)->not->toHaveKey('webdav_password');
    expect($array)->not->toHaveKey('api_key');
});

it('can get local media paths for movies', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['name' => 'Movies', 'path' => '/movies', 'type' => 'movies'],
            ['name' => 'TV Shows', 'path' => '/tvshows', 'type' => 'tvshows'],
            ['name' => 'More Movies', 'path' => '/movies2', 'type' => 'movies'],
        ],
    ]);

    $moviePaths = $integration->getLocalMediaPathsForType('movies');

    expect($moviePaths)->toHaveCount(2);
    expect(array_values($moviePaths)[0]['path'])->toBe('/movies');
    expect(array_values($moviePaths)[1]['path'])->toBe('/movies2');
});

it('generates correct stream URLs for webdav media', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = WebDavMediaService::make($integration);
    $itemId = base64_encode('/movies/Test Movie.mkv');

    $streamUrl = $service->getStreamUrl($itemId);

    expect($streamUrl)->toContain('/webdav-media/');
    expect($streamUrl)->toContain((string) $integration->id);
    expect($streamUrl)->toContain($itemId);
});

it('parses webdav propfind responses', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = WebDavMediaService::make($integration);

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:multistatus xmlns:d="DAV:">'
        .'<d:response>'
        .'<d:href>/media/movies/</d:href>'
        .'<d:propstat>'
        .'<d:prop>'
        .'<d:resourcetype><d:collection/></d:resourcetype>'
        .'<d:displayname>movies</d:displayname>'
        .'</d:prop>'
        .'</d:propstat>'
        .'</d:response>'
        .'<d:response>'
        .'<d:href>/media/movies/Test.Movie.2024.mkv</d:href>'
        .'<d:propstat>'
        .'<d:prop>'
        .'<d:getcontentlength>12345</d:getcontentlength>'
        .'<d:displayname>Test.Movie.2024.mkv</d:displayname>'
        .'</d:prop>'
        .'</d:propstat>'
        .'</d:response>'
        .'</d:multistatus>';

    $method = new ReflectionMethod(WebDavMediaService::class, 'parseWebDavResponse');
    $method->setAccessible(true);

    $items = $method->invoke($service, $xml, '/media');

    expect($items)->toHaveCount(2);
    expect($items[0]['name'])->toBe('movies');
    expect($items[0]['isDirectory'])->toBeTrue();
    expect($items[1]['name'])->toBe('Test.Movie.2024.mkv');
    expect($items[1]['size'])->toBe(12345);
});

it('keeps full nested paths from webdav hrefs', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = WebDavMediaService::make($integration);

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:multistatus xmlns:d="DAV:">'
        .'<d:response>'
        .'<d:href>/media/movies/In%20Your%20Dreams/In%20Your%20Dreams/</d:href>'
        .'<d:propstat>'
        .'<d:prop>'
        .'<d:resourcetype><d:collection/></d:resourcetype>'
        .'<d:displayname>In Your Dreams</d:displayname>'
        .'</d:prop>'
        .'</d:propstat>'
        .'</d:response>'
        .'</d:multistatus>';

    $method = new ReflectionMethod(WebDavMediaService::class, 'parseWebDavResponse');
    $method->setAccessible(true);

    $items = $method->invoke($service, $xml, '/media');

    expect($items)->toHaveCount(1);
    expect($items[0]['path'])->toBe('/media/movies/In Your Dreams/In Your Dreams');
    expect($items[0]['name'])->toBe('In Your Dreams');
});
