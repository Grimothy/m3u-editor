<?php

use App\Enums\DvrRuleType;
use App\Livewire\EpgViewer;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($this->user);
});

it('renders the dvrMap into x-data without a raw double quote breaking the HTML attribute', function () {
    // Regression guard: @json() only escapes quotes *inside* string values, not
    // JSON's own structural quotes, so `dvrMap: @json($map)` rendered literal
    // `"` characters into a double-quoted x-data="..." attribute, truncating it
    // and breaking every Alpine binding in the component (not just the record
    // button) for BOTH Playlist and CustomPlaylist views. Js::from() wraps the
    // payload in JSON.parse('...') with all quotes escaped, which is safe here.
    $playlist = Playlist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => $playlist->id,
    ]);
    Channel::factory()->for($this->user)->for($playlist, 'playlist')->create(['enabled' => true]);

    $html = Livewire::test(EpgViewer::class, ['record' => $playlist])->html();

    $xData = substr($html, strpos($html, 'x-data="'), strpos($html, '})"') - strpos($html, 'x-data="') + 3);

    expect($xData)->not->toContain('dvrMap: {"')
        ->and($xData)->toContain("dvrMap: JSON.parse('");
});

it('marks every channel of a DVR-enabled playlist as recordable (regression)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => $playlist->id,
    ]);

    $channels = Channel::factory()
        ->count(3)
        ->for($this->user)
        ->for($playlist, 'playlist')
        ->create(['enabled' => true]);

    Livewire::test(EpgViewer::class, ['record' => $playlist])
        ->assertSet('dvrEnabledChannelIds', array_fill_keys($channels->pluck('id')->all(), true));
});

it('marks CustomPlaylist channels as recordable when their source playlist has DVR enabled, and the schedule actually creates a rule', function () {
    // Source Playlist A has DVR enabled.
    $source = Playlist::factory()->for($this->user)->create();
    $setting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => $source->id,
    ]);

    // Two channels belong to A — both have real playlist_id pointing at $source.
    $ch1 = Channel::factory()->for($this->user)->for($source, 'playlist')->create(['enabled' => true]);
    $ch2 = Channel::factory()->for($this->user)->for($source, 'playlist')->create(['enabled' => true]);

    // A CustomPlaylist picks up both channels via the BelongsToMany pivot.
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $custom->channels()->attach([$ch1->id, $ch2->id]);

    // Mount against the CustomPlaylist — the per-channel map should still
    // include both channels because they ultimately belong to a DVR-enabled source.
    $component = Livewire::test(EpgViewer::class, ['record' => $custom])
        ->assertSet('dvrEnabledChannelIds', [$ch1->id => true, $ch2->id => true]);

    // Drive a schedule end-to-end to prove the DvrSetting is resolved via the
    // channel's real playlist_id (NOT via $custom->id, which is the wrong table).
    // Using Series rule type avoids the EpgProgramme firstOrCreate path.
    $component
        ->call('openScheduleProgramme', [
            'title' => 'Channel One News',
            'start' => now()->addHour()->toIso8601String(),
            'stop' => now()->addHours(2)->toIso8601String(),
            'category' => 'News',
        ], $ch1->id)
        ->call('mountAction', 'scheduleProgramme')
        ->setActionData(['rule_type' => DvrRuleType::Series->value, 'new_only' => false])
        ->call('callMountedAction');

    expect(DvrRecordingRule::query()
        ->where('channel_id', $ch1->id)
        ->where('dvr_setting_id', $setting->id)
        ->where('type', DvrRuleType::Series->value)
        ->exists())->toBeTrue();
});

it('does NOT mark CustomPlaylist channels as recordable when their source playlist has DVR disabled', function () {
    $source = Playlist::factory()->for($this->user)->create();
    // Enabled=false — explicitly.
    DvrSetting::factory()->for($this->user)->create([
        'playlist_id' => $source->id,
        'enabled' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($source, 'playlist')->create(['enabled' => true]);

    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $custom->channels()->attach($channel->id);

    Livewire::test(EpgViewer::class, ['record' => $custom])
        ->assertSet('dvrEnabledChannelIds', []);
});

it('never marks CustomPlaylist custom channels (no source playlist) as recordable', function () {
    // A second source playlist WITH DVR enabled — used as a positive control
    // to confirm the map would have entries if the test data allowed it.
    $sourceWithDvr = Playlist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => $sourceWithDvr->id,
    ]);
    $realChannel = Channel::factory()->for($this->user)->for($sourceWithDvr, 'playlist')->create(['enabled' => true]);

    // Custom channel: no playlist_id, lives only under the CustomPlaylist via custom_playlist_id.
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $customChannel = Channel::factory()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => $custom->id,
        'is_custom' => true,
        'enabled' => true,
    ]);

    // The real channel is also attached so the map is non-empty — proves the
    // assertion is meaningful (we're checking the custom channel is excluded,
    // not that the map is empty).
    $custom->channels()->attach($realChannel->id);

    Livewire::test(EpgViewer::class, ['record' => $custom])
        ->assertSet('dvrEnabledChannelIds', [$realChannel->id => true])
        // Custom channel must NEVER appear in the map — it has no playlist_id.
        ->assertSet('dvrEnabledChannelIds', function (array $map) use ($customChannel) {
            return ! array_key_exists($customChannel->id, $map);
        });
});
