<?php

namespace App\Filament\Resources\VodDynamicGroups\Pages;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\Widgets\VodDynamicGroupCacheActivityWidget;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * VOD-only listing surface for DynamicGroup rows.
 *
 * Sister page to `SeriesDynamicGroups\Pages\ListSeriesDynamicGroups` —
 * the "Dynamic Groups" sidebar listing the user wanted split out of the
 * VOD Groups and Series Categories footer widgets. We register TWO
 * Filament resources backed by the same model so each one surfaces
 * under its parent content-type section (VOD Channels / Series).
 *
 * The query is already filtered to `type = 'vod'` by
 * `VodDynamicGroupResource::getEloquentQuery()` — no per-row type
 * switch needed here, so the cache-status math is the simple channel
 * version that lives here (the series-side gets the episode version).
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route — single detail page
 * keyed by record id, breadcrumb chains through `VodGroupResource`.
 */
class ListVodDynamicGroups extends ListRecords
{
    protected static string $resource = VodDynamicGroupResource::class;

    /**
     * Top-level tab state — "groups" (default) or "cache". Set by
     * Filament when the user clicks one of the two top-level tab
     * labels rendered by getTabs(). Used by content() to switch
     * between the Dynamic Groups table and the Download Cache
     * Activity widget.
     */
    public ?string $activeTab = null;

    /**
     * Per-playlist sub-tab state, independent of activeTab. Lets the
     * user drill into a specific playlist's groups or cache activity
     * without losing the selection when they switch top-level tabs.
     * Default "all" = no playlist filter.
     */
    public ?string $activePlaylistTab = 'all';

    /**
     * Top-level tabs: "Dynamic Groups" (the main table) and
     * "Download Cache Activity" (the cache pipeline widget). The
     * per-playlist sub-tabs are NOT here — they're rendered as a
     * second, nested Tabs component inside content() so the two
     * state values stay independent (clicking a playlist on the
     * groups tab carries over to the cache tab).
     */
    public function getTabs(): array
    {
        $groupsCount = static::getResource()::getEloquentQuery()->count();
        $cacheCount = (int) CachedContentFile::query()
            ->where('content_type', VodDynamicGroupResource::CONTENT_TYPE)
            ->count();

        return [
            'groups' => Tab::make(__('Dynamic Groups'))
                ->icon('heroicon-m-squares-2x2')
                ->badge($groupsCount),
            'cache' => Tab::make(__('Download Cache Activity'))
                ->icon('heroicon-m-cloud-arrow-down')
                ->badge($cacheCount),
        ];
    }

    /**
     * Per-playlist sub-tabs. Builds the All + per-playlist tabs that
     * scope BOTH the Dynamic Groups view AND the Download Cache
     * Activity view to a single playlist. Called by content() — the
     * returned tabs are rendered as a separate Tabs component so
     * the activePlaylistTab state is independent of the top-level
     * activeTab.
     */
    public function getPlaylistSubTabs(): array
    {
        $base = static::getResource()::getEloquentQuery();

        $allCount = (clone $base)->count();
        $playlistCounts = (clone $base)
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->groupBy('playlist_id')
            ->pluck('aggregate', 'playlist_id');

        $playlists = Playlist::query()
            ->whereIn('id', $playlistCounts->keys())
            ->orderBy('name')
            ->get();

        $tabs = [
            'all' => Tab::make(__('All Playlists'))
                ->badge($allCount),
        ];
        foreach ($playlists as $playlist) {
            $tabs[(string) $playlist->id] = Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('dynamic_groups.playlist_id', $playlist->id))
                ->badge($playlistCounts->get($playlist->id, 0));
        }

        return $tabs;
    }

    /**
     * Render the active top-level tab's content + the shared
     * per-playlist sub-tabs. The two top-level tabs swap the
     * main content (Dynamic Groups table vs Download Cache Activity
     * widget) while the sub-tabs stay the same — so "Playlist A" on
     * the groups view carries over when you switch to the cache
     * view, which is what makes the pattern feel like the existing
     * VOD Channels page.
     */
    public function content(Schema $schema): Schema
    {
        $isCacheTab = $this->activeTab === 'cache';

        $subTabs = Tabs::make('playlistTabs')
            ->livewireProperty('activePlaylistTab')
            ->contained(false)
            ->tabs($this->getPlaylistSubTabs())
            ->hidden(empty($this->getPlaylistSubTabs()));

        $main = $isCacheTab
            ? Livewire::make(
                VodDynamicGroupCacheActivityWidget::class,
                // Re-evaluated on every parent render so the widget
                // sees the latest sub-tab selection. Mirrors the
                // getWidgetData() pattern the old per-playlist
                // DynamicGroupsWidget used.
                fn (): array => ['activePlaylistId' => $this->activePlaylistTab],
            )
            : EmbeddedTable::make();

        return $schema
            ->components([
                $subTabs,
                $this->getTabsContentComponent(),
                $main,
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // withCount('channels') attaches the channels pivot count as
            // a subquery column the TextColumn::make('channels_count')
            // reads from. Applied via modifyQueryUsing so it ONLY
            // attaches when the table renders — getTabs() runs a
            // separate groupBy('playlist_id') query against the
            // resource's getEloquentQuery() and that combination blows
            // up Postgres (subquery columns must be in GROUP BY).
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('channels'))
            ->recordUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->formatStateUsing(fn (DynamicGroup $record): string => DynamicGroupResource::sourceLabelFor($record->type)[$record->source] ?? $record->source)
                    ->badge(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('channels_count')
                    ->label(__('Items'))
                    ->numeric(),
                // Group-level "Cached / Total" fraction. Per-movie
                // Cached indicators live on ChannelsRelationManager
                // inside ViewDynamicGroup (the movie grid), not here.
                TextColumn::make('cache_status')
                    ->label(__('Cache progress'))
                    // "Cached / Total" for vod-type groups that have
                    // caching enabled. Same fingerprint-based dedup math
                    // the old DynamicGroupsWidget used — see that
                    // widget's docblock for the rationale on why we
                    // don't pivot-count.
                    ->state(function (DynamicGroup $record): string {
                        $dispatch = app(DynamicGroupCacheDispatchService::class);
                        $rule = $dispatch->resolveRuleForGroup($record);
                        if ($rule === null || ! ($rule['cache_enabled'] ?? false)) {
                            return '—';
                        }

                        $channels = $record->channels;
                        $total = $channels->count();
                        if ($total === 0) {
                            return '0/0';
                        }

                        $quality = $dispatch->resolveQuality($rule);
                        $fingerprints = $channels
                            ->map(fn ($channel) => CachedContentFile::fingerprintFor([
                                'content_type' => 'movie',
                                'tmdb_id' => $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null,
                                'quality' => $quality,
                            ]))
                            ->all();

                        $cached = CachedContentFile::query()
                            ->whereIn('content_fingerprint', $fingerprints)
                            ->where('status', CachedContentFileStatus::Completed)
                            ->count();

                        return "{$cached}/{$total}";
                    }),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                Action::make('view')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
            ], RecordActionsPosition::BeforeCells)
            ->bulkActions([
                BulkAction::make('cache_now')
                    ->label(__('Cache Now'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cache selected dynamic groups'))
                    ->modalDescription(__('Queue downloads for every selected Dynamic Group now? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget on the VOD Groups page.'))
                    ->modalSubmitActionLabel(__('Yes, cache now'))
                    ->action(function (Collection $records): void {
                        $settings = app(GeneralSettings::class);
                        if (! $settings->enable_dynamic_group_cache) {
                            Notification::make()
                                ->warning()
                                ->title(__('Dynamic Group Caching is disabled'))
                                ->body(__('Enable it in Preferences → Dynamic Groups before queueing cache downloads.'))
                                ->duration(10000)
                                ->send();

                            return;
                        }

                        $result = app(DynamicGroupCacheDispatchService::class)->dispatchForGroups($records);

                        if ($result['dispatched'] === 0) {
                            Notification::make()
                                ->info()
                                ->title(__('No cache jobs queued'))
                                ->body(__('Processed :processed of :total groups.', [
                                    'processed' => $result['groups_processed'],
                                    'total' => $result['groups_total'],
                                ]))
                                ->send();

                            return;
                        }

                        $title = $result['dispatched'] === 1
                            ? __('Dispatched 1 cache job.')
                            : __('Dispatched :count cache jobs.', ['count' => $result['dispatched']]);

                        Notification::make()
                            ->success()
                            ->title($title)
                            ->body(__('Processed :processed of :total groups. Track progress in the Dynamic Group Cache Activity widget below.', [
                                'processed' => $result['groups_processed'],
                                'total' => $result['groups_total'],
                            ]))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('No Dynamic Groups configured'))
            ->emptyStateDescription(__('Add Dynamic Groups in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.'))
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
