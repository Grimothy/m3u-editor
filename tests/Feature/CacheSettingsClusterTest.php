<?php

use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders the Integrations settings page with the Cache tab open', function () {
    Livewire::test(ManageIntegrationSettings::class)
        ->assertOk();
});

it('saves the enable_cache toggle', function () {
    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['enable_cache' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((bool) app(GeneralSettings::class)->refresh()->enable_cache)->toBeTrue();
});

it('saves the default_share_cache_across_playlists toggle', function () {
    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['default_share_cache_across_playlists' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((bool) app(GeneralSettings::class)->refresh()->default_share_cache_across_playlists)->toBeTrue();
});

it('saves the cache_retention_mode select value', function () {
    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['cache_retention_mode' => 'never-expire'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->refresh()->cache_retention_mode)->toBe('never-expire');
});

it('does not wipe enable_cache when saving an unrelated field on the same page', function () {
    $settings = app(GeneralSettings::class);
    $settings->enable_cache = true;
    $settings->save();

    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['cache_retention_mode' => 'manual'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = app(GeneralSettings::class)->refresh();

    expect((bool) $fresh->enable_cache)->toBeTrue()
        ->and($fresh->cache_retention_mode)->toBe('manual');
});

it('rejects non-admin users from the integrations settings page', function () {
    auth()->logout();
    $this->actingAs(User::factory()->create());

    expect(ManageIntegrationSettings::canAccess())->toBeFalse();
});

it('opens the Integrations page on the new cache tab', function () {
    request()->merge(['tab' => 'cache']);

    $page = new ManageIntegrationSettings;
    $schema = $page->form(Schema::make($page));

    $tabs = collect($schema->getComponents())
        ->first(fn ($c) => $c instanceof Tabs);

    expect($tabs->getActiveTab())->toBe(4); // tmdb(1) + aiostreams(2) + mediaflow(3) + cache(4)
});
