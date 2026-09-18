<?php

namespace App\Filament\Resources\SeriesDynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Series-only listing surface for DynamicGroup rows.
 *
 * Sister page to `VodDynamicGroups\Pages\ListVodDynamicGroups` — the
 * Dynamic Groups listing that this refactor splits out of the
 * per-type footer widgets, exposed under the existing Series nav
 * section so it sits as a sibling of Categories and Series itself.
 *
 * The query is already filtered to `type = 'series'` by
 * `SeriesDynamicGroupResource::getEloquentQuery()` — no per-row type
 * switch needed here.
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route — single detail
 * page keyed by record id, breadcrumb chains through
 * `CategoryResource` (see `Pages\ViewDynamicGroup`).
 *
 * Cache-specific tabs, actions, and columns are intentionally absent from
 * this navigation-only surface.
 */
class ListSeriesDynamicGroups extends ListRecords
{
    protected static string $resource = SeriesDynamicGroupResource::class;

    /**
     * Per-playlist sub-tabs. Scope the Dynamic Groups view to a single
     * playlist. See the VOD-side docblock for the full rationale.
     */
    public function getTabs(): array
    {
        $base = static::getResource()::getEloquentQuery();

        $allCount = (clone $base)->count();
        // Postgres-safe (see the VOD-side page and the resource's
        // getEloquentQuery() NOTE — no withCount attached here).
        $playlistCounts = (clone $base)
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->groupBy('playlist_id')
            ->pluck('aggregate', 'playlist_id');

        $playlists = Playlist::query()
            ->whereIn('id', $playlistCounts->keys())
            ->orderBy('name')
            ->get();

        $tabs = [
            null => Tab::make(__('All Playlists'))
                ->badge($allCount),
        ];
        foreach ($playlists as $playlist) {
            $tabs[(string) $playlist->id] = Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('dynamic_groups.playlist_id', $playlist->id))
                ->badge($playlistCounts->get($playlist->id, 0));
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // withCount('series') attaches the series pivot count as
            // a subquery column the TextColumn::make('series_count')
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
            ->emptyStateHeading(__('No Dynamic Groups configured'))
            ->emptyStateDescription(__('Add Dynamic Groups in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.'))
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
