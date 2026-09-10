<?php

namespace App\Filament\Resources\SeriesDynamicGroups\Pages;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\Widgets\SeriesDynamicGroupCacheActivityWidget;
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
 * Series-only listing surface for DynamicGroup rows.
 *
 * Sister page to `VodDynamicGroups\Pages\ListVodDynamicGroups` — the
 * Dynamic Groups listing the user wanted split out of the per-type
 * footer widgets, exposed under the existing Series nav section so
 * it sits as a sibling of Categories and Series itself.
 *
 * The query is already filtered to `type = 'series'` by
 * `SeriesDynamicGroupResource::getEloquentQuery()` — no per-row type
 * switch needed here, so the cache-status math is the simple episode
 * version that lives here (the VOD-side gets the channel version).
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route — single detail
 * page keyed by record id, breadcrumb chains through
 * `CategoryResource` (see `Pages\ViewDynamicGroup`).
 */
class ListSeriesDynamicGroups extends ListRecords
{
    protected static string $resource = SeriesDynamicGroupResource::class;

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
            ->where('content_type', SeriesDynamicGroupResource::CONTENT_TYPE)
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
     * Per-playlist sub-tabs. See the VOD-side docblock for the full
     * rationale — these scope BOTH the Dynamic Groups view AND the
     * Download Cache Activity view to a single playlist.
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
     * per-playlist sub-tabs. See the VOD-side docblock for the full
     * rationale — top-level tab swaps the main content; sub-tabs
     * stay constant across top-level switches.
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
                SeriesDynamicGroupCacheActivityWidget::class,
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
            // withCount('series') attaches the series pivot count as a
            // subquery column the TextColumn::make('series_count')
            // reads from. Applied via modifyQueryUsing so it ONLY
            // attaches when the table renders — getTabs() runs a
            // separate groupBy('playlist_id') query against the
            // resource's getEloquentQuery() and that combination blows
            // up Postgres (subquery columns must be in GROUP BY).
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('series'))
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
                TextColumn::make('series_count')
                    ->label(__('Items'))
                    ->numeric(),
                // Group-level "Cached / Total" fraction. See
                // VOD-side page for the full rationale. Per-episode
                // Cached indicators would live on the Series relation
                // manager if/when that surface needs them.
                TextColumn::make('cache_status')
                    ->label(__('Cache progress'))
                    // Series-type mirror of the VOD widget's "Cached / Total"
                    // column. Key difference vs. the VOD page: the
                    // denominator is the group's EPISODE count
                    // ($series->flatMap(fn ($s) => $s->episodes)->count()),
                    // NOT the series count — one cached series contributes
                    // its full episode count, matching how the Phase 2
                    // dispatcher iterates (each episode produces its own
                    // download job with its own fingerprint).
                    //
                    // Lazy-loading `$record->series` and `$series->episodes`
                    // is an N+1 cost per row; acceptable at the scale this
                    // listing runs at (handful of groups per playlist tab)
                    // and flagged for future optimization.
                    ->state(function (DynamicGroup $record): string {
                        $dispatch = app(DynamicGroupCacheDispatchService::class);
                        $rule = $dispatch->resolveRuleForGroup($record);
                        if ($rule === null || ! ($rule['cache_enabled'] ?? false)) {
                            return '—';
                        }

                        $series = $record->series;
                        $total = $series->flatMap(fn ($s) => $s->episodes)->count();
                        if ($total === 0) {
                            return '0/0';
                        }

                        $quality = $dispatch->resolveQuality($rule);
                        $fingerprints = [];
                        foreach ($series as $s) {
                            $seriesTmdbId = $s->tmdb_id !== null ? (string) $s->tmdb_id : null;
                            foreach ($s->episodes as $episode) {
                                $fingerprints[] = CachedContentFile::fingerprintFor([
                                    'content_type' => 'episode',
                                    'tmdb_id' => $seriesTmdbId,
                                    'season_number' => $episode->season,
                                    'episode_number' => $episode->episode_number,
                                    'quality' => $quality,
                                ]);
                            }
                        }

                        if (empty($fingerprints)) {
                            return '0/0';
                        }

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
                    ->modalDescription(__('Queue downloads for every selected Dynamic Group now? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget on the Categories page.'))
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
