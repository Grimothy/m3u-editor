<?php

use App\Filament\Pages\BrowseShows;
use App\Filament\Resources\DvrRecordingRules\Pages\CreateDvrRecordingRule;
use App\Filament\Resources\DvrRecordingRules\Pages\ListDvrRecordingRules;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
});

it('admin can create a recording rule attributed to a specific PlaylistAuth', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    $dvrSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $admin->id,
        'playlist_id' => $playlist->id,
    ]);
    $wifeAuth = PlaylistAuth::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Wife Auth',
        'username' => 'wife',
    ]);
    $wifeAuth->assignTo($playlist);

    Livewire::actingAs($admin)
        ->test(CreateDvrRecordingRule::class)
        ->fillForm([
            'playlist_auth_id' => $wifeAuth->id,
            'dvr_setting_id' => $dvrSetting->id,
            'type' => 'series',
            'series_title' => 'Breaking Bad',
            'enabled' => true,
            'priority' => 50,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('dvr_recording_rules', [
        'user_id' => $admin->id,
        'playlist_auth_id' => $wifeAuth->id,
        'dvr_setting_id' => $dvrSetting->id,
        'series_title' => 'Breaking Bad',
    ]);
});

it('admin can create a rule attributed to themselves (owner) with null playlist_auth_id', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    $dvrSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $admin->id,
        'playlist_id' => $playlist->id,
    ]);

    Livewire::actingAs($admin)
        ->test(CreateDvrRecordingRule::class)
        ->fillForm([
            'playlist_auth_id' => null,
            'dvr_setting_id' => $dvrSetting->id,
            'type' => 'series',
            'series_title' => 'Seinfeld',
            'enabled' => true,
            'priority' => 50,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('dvr_recording_rules', [
        'user_id' => $admin->id,
        'playlist_auth_id' => null,
        'series_title' => 'Seinfeld',
    ]);
});

it('admin attempting a foreign PlaylistAuth is rejected', function () {
    $admin = User::factory()->admin()->create();
    $victim = User::factory()->create(['permissions' => ['use_dvr']]);
    $adminPlaylist = Playlist::factory()->for($admin)->create();
    $adminSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $admin->id,
        'playlist_id' => $adminPlaylist->id,
    ]);

    $victimAuth = PlaylistAuth::factory()->create([
        'user_id' => $victim->id,
        'name' => 'Victim Auth',
    ]);

    Livewire::actingAs($admin)
        ->test(CreateDvrRecordingRule::class)
        ->fillForm([
            'playlist_auth_id' => $victimAuth->id,
            'dvr_setting_id' => $adminSetting->id,
            'type' => 'series',
            'series_title' => 'The Office',
            'enabled' => true,
            'priority' => 50,
        ])
        ->call('create')
        ->assertHasFormErrors(['playlist_auth_id']);

    $this->assertDatabaseMissing('dvr_recording_rules', [
        'series_title' => 'The Office',
    ]);
});

it('admin form playlists auth picker is scoped to admins own PlaylistAuth records', function () {
    $admin = User::factory()->admin()->create();
    $victim = User::factory()->create(['permissions' => ['use_dvr']]);

    $adminAuth = PlaylistAuth::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Admin Owner',
    ]);
    $wifeAuth = PlaylistAuth::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Wife Auth',
    ]);
    $victimAuth = PlaylistAuth::factory()->create([
        'user_id' => $victim->id,
        'name' => 'Victim Auth',
    ]);

    $playlist = Playlist::factory()->for($admin)->create();
    DvrSetting::factory()->enabled()->create([
        'user_id' => $admin->id,
        'playlist_id' => $playlist->id,
    ]);

    Livewire::actingAs($admin)
        ->test(CreateDvrRecordingRule::class)
        ->assertFormFieldExists('playlist_auth_id', function ($field) use ($adminAuth, $wifeAuth, $victimAuth): bool {
            $options = $field->getOptions();

            Assert::assertArrayHasKey($adminAuth->id, $options);
            Assert::assertArrayHasKey($wifeAuth->id, $options);
            Assert::assertArrayNotHasKey($victimAuth->id, $options);
            Assert::assertContains('Me (owner)', $options, 'Me (owner) null option must be present');

            return true;
        });
});

it('non-admin does not see other users rules in the resource list', function () {
    $hacker = User::factory()->create(['permissions' => ['use_dvr']]);
    $victim = User::factory()->create(['permissions' => ['use_dvr']]);
    $victimPlaylist = Playlist::factory()->for($victim)->create();
    $victimSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $victim->id,
        'playlist_id' => $victimPlaylist->id,
    ]);

    DvrRecordingRule::factory()->create([
        'user_id' => $victim->id,
        'playlist_auth_id' => null,
        'dvr_setting_id' => $victimSetting->id,
        'type' => 'series',
        'series_title' => 'Private Show',
    ]);

    Livewire::actingAs($hacker)
        ->test(ListDvrRecordingRules::class)
        ->assertCanNotSeeTableRecords(
            DvrRecordingRule::where('series_title', 'Private Show')->get()
        );
});

it('BrowseShows schedule-for-auth picker is in the filter schema', function () {
    $admin = User::factory()->admin()->create();
    $wifeAuth = PlaylistAuth::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Wife Auth',
    ]);
    $playlist = Playlist::factory()->for($admin)->create();
    $playlist->playlistAuths()->attach($wifeAuth);
    DvrSetting::factory()->enabled()->create([
        'user_id' => $admin->id,
        'playlist_id' => $playlist->id,
    ]);

    Livewire::actingAs($admin)
        ->test(BrowseShows::class)
        ->assertSuccessful()
        ->assertSet('scheduleForPlaylistAuthId', null);
});
