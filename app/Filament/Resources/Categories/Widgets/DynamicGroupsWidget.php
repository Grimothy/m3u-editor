<?php

namespace App\Filament\Resources\Categories\Widgets;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Services\DynamicGroupCacheDispatchService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;

/**
 * Footer widget on `ListCategories` showing the current user's series-type
 * Dynamic Groups (Trending / Popular / Top Genre / By TV Network / etc.).
 * Rows are fully clickable through to the read-only detail page - see
 * `VodGroups\Widgets\DynamicGroupsWidget` for the full rationale (this
 * widget mirrors it, with "Category" wording throughout since this page's
 * own vocabulary is Categories, not Groups).
 *
 * Built because CJ's own test data (the "Netflix" Dynamic Group) is
 * series-type, so building only the VOD half would leave the identical gap
 * on the Series page.
 *
 * The user-scoping rule (admin sees all, non-admin sees only their own)
 * mirrors `DynamicGroupResource::getEloquentQuery()` so the widget and
 * its link-through target agree on visibility.
 */
class DynamicGroupsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    /**
     * Heading moved into the wrapping `<x-filament::section>` in the
     * shared widget view. Setting this to null prevents
     * `TableWidget::getTableHeading()` from rendering a second duplicate
     * heading inside the table's internal container.
     */
    protected static ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Custom view that wraps `{{ $this->table }}` in a collapsible
     * `<x-filament::section>` with an always-visible "?" info tooltip in
     * the header. Shared with the VOD-side widget — single source of
     * truth for the collapsible + tooltip UX so the two widgets can't
     * drift apart.
     */
    protected string $view = 'filament.widgets.dynamic-groups-table-widget';

    /**
     * Bound from the parent page (`ListCategories::getWidgetData()`).
     * String-cast of the active tab key, which `setupTabs()` maps from
     * `$playlist->id`. `null` = no tab selected = show all (regression guard).
     *
     * Parallel to the VOD-side widget — same reactive semantics, see the
     * docblock on `VodGroups\Widgets\DynamicGroupsWidget::$activePlaylistId`
     * for the full Filament/Livewire wiring explanation. #[Reactive] is
     * required for live tab clicks to actually reach this property.
     */
    #[Reactive]
    public ?string $activePlaylistId = null;

    /**
     * Experimental feature - only render when
     * `config('feature.playlist_tmdb_dynamic_groups')` is enabled, and only
     * when TMDB is actually configured. Without a TMDB API key,
     * `dynamic_group_items` can never be populated (SyncDynamicGroups is a
     * no-op), so showing the widget's "no dynamic groups yet" empty state
     * would misleadingly suggest the feature just needs a rule added rather
     * than a TMDB key.
     */
    public static function canView(): bool
    {
        return (bool) config('feature.playlist_tmdb_dynamic_groups')
            && app(TmdbService::class)->isConfigured();
    }

    /**
     * Shared scoping for both the table's own query and the collapse-state
     * check (`hasDynamicGroups()`) — so a widget that says "I have nothing
     * to show" is reading from exactly the same row set as the table that
     * would render those rows. Mirrors `DynamicGroupResource::getEloquentQuery()`:
     * admin sees all, non-admin sees only their own; per-tab playlist scope
     * when `$activePlaylistId` is set; type filter (series here, vod on the
     * VOD-side widget). Does NOT include `->withCount()` because the
     * collapse-state check only needs `->exists()`.
     */
    protected function baseQuery(): Builder
    {
        return DynamicGroup::query()
            ->where('type', 'series')
            ->when(
                $this->activePlaylistId !== null && $this->activePlaylistId !== '',
                fn ($query) => $query->where('playlist_id', (int) $this->activePlaylistId),
            )
            ->when(
                auth()->check() && ! auth()->user()->isAdmin(),
                fn ($query) => $query->where('dynamic_groups.user_id', auth()->id()),
            );
    }

    /**
     * Drives the widget's default collapsed state — collapsed when there's
     * nothing to show for the current user + active playlist tab, expanded
     * otherwise. Recomputed fresh on every render (CJ confirmed: no
     * persisted collapse state across page loads), so a user who adds new
     * dynamic groups sees the widget auto-expand on their next sync without
     * needing to click it open. Parallel to the VOD-side implementation.
     */
    public function hasDynamicGroups(): bool
    {
        return $this->baseQuery()->exists();
    }

    /**
     * Single source of truth for the copy shown both as the table's
     * empty-state description and as the always-visible header tooltip
     * — mirrors the VOD-side widget so the two can never drift apart.
     */
    public function getDynamicGroupsHelpText(): string
    {
        return __('Add Dynamic Categories in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.');
    }

    /**
     * Section heading, read by the shared blade view. "Categories" wording
     * to match this page's own vocabulary - see
     * `VodGroups\Widgets\DynamicGroupsWidget::getSectionHeading()`.
     */
    public function getSectionHeading(): string
    {
        return __('Dynamic Categories (TMDB)');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery()->withCount('series'))
            ->defaultSort('name')
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
                TextColumn::make('cache_status')
                    ->label(__('Cached'))
                    // Series-type mirror of the VOD widget's "Cached / Total" column.
                    // Key difference vs. the VOD widget: the denominator is the
                    // group's EPISODE count (`$series->episodes->count()`), NOT
                    // the series count — one cached series contributes its full
                    // episode count, matching how the Phase 2 dispatcher iterates
                    // (each episode produces its own download job with its own
                    // fingerprint).
                    //
                    // Lazy-loading `$record->series` and `$series->episodes` is an
                    // N+1 cost per row; acceptable at the scale this widget runs
                    // at (handful of groups per playlist tab) and flagged for
                    // future optimization in the plan's "Known scaling caveat"
                    // note. See the VOD widget's column docblock for the full
                    // rationale on why we don't use a pivot count.
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
                    ->modalHeading(__('Cache selected dynamic categories'))
                    ->modalDescription(__('Queue downloads for every selected Dynamic Category now? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget below.'))
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
            ->emptyStateHeading(__('No Dynamic Categories configured'))
            ->emptyStateDescription($this->getDynamicGroupsHelpText())
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
