<?php

use App\Filament\Resources\PlaylistAuths\Pages\ListPlaylistAuths;
use App\Models\PlaylistAuth;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('rejects a playlist auth password containing URL-breaking characters', function (string $password) {
    Livewire::test(ListPlaylistAuths::class)
        ->callAction('create', data: [
            'name' => 'Living room TV',
            'username' => 'tvuser',
            'password' => $password,
        ])
        ->assertHasActionErrors(['password']);

    $this->assertDatabaseMissing('playlist_auths', ['username' => 'tvuser']);
})->with([
    'server url' => 'https://m3u-editor.example.ts.net',
    'slash' => 'tv/pass',
    'question mark' => 'tv?pass',
    'hash' => 'tv#pass',
    'percent' => 'tv%pass',
    'space' => 'tv pass',
]);

it('rejects a playlist auth username containing URL-breaking characters', function () {
    Livewire::test(ListPlaylistAuths::class)
        ->callAction('create', data: [
            'name' => 'Living room TV',
            'username' => 'tv/user',
            'password' => 'tvpass1234',
        ])
        ->assertHasActionErrors(['username']);

    $this->assertDatabaseCount('playlist_auths', 0);
});

it('creates a playlist auth with path-safe credentials', function () {
    Livewire::test(ListPlaylistAuths::class)
        ->callAction('create', data: [
            'name' => 'Living room TV',
            'username' => 'tv.user-1',
            'password' => 'tvpass1234!',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('playlist_auths', [
        'username' => 'tv.user-1',
        'password' => 'tvpass1234!',
        'user_id' => $this->user->id,
    ]);
});

it('rejects saving URL-breaking characters into an existing playlist auth', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'tvuser',
        'password' => 'tvpass1234',
    ]);

    Livewire::test(ListPlaylistAuths::class)
        ->callAction(TestAction::make('edit')->table($auth), data: [
            'password' => 'https://m3u-editor.example.ts.net',
        ])
        ->assertHasActionErrors(['password']);

    expect($auth->fresh()->password)->toBe('tvpass1234');
});
