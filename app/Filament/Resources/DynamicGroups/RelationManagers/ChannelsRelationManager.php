<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use App\Filament\Widgets\CachedContentActivityWidget;
use App\Models\CachedContentFile;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * VOD (Channel) members of the parent DynamicGroup. Visible only when the
 * parent's `type` is `'vod'` - DynamicGroups are single-type by construction
 * (matching their `dynamic_groups_config` rule row), so a series-type parent
 * has zero channels to show and the tab is hidden.
 *
 * Strictly read-only for membership edits - exposes only the Cache Now and
 * Delete Cache row actions, with no bulk actions. Membership is computed by
 * `SyncDynamicGroups` from the parent playlist's
 * `dynamic_groups_config`; this manager is a transparency window, not an edit
 * surface.
 */
class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $title = 'Movies';

    /**
     * Names of the flat record actions this relation manager exposes itself.
     * SetupTable() wires up VodResource's full action set; this whitelist is
     * the ONLY list allowed to survive the strip pass below so that any
     * destructive/mutating action added to VodResource later (delete, edit,
     * fetch_tmdb_ids, sync, probe, manual_tmdb_search, process_vod, ...) does
     * not silently leak onto this read-only membership surface. Filament's
     * mountAction() only honors disabled/authorize (not visibility), so a
     * crafted mountTableAction('delete', ...) call would otherwise still
     * invoke the inherited DeleteAction even when the row action is hidden.
     * The cache ActionGroup's children (cache_now, delete_cache_record) are
     * the only ones this surface intentionally owns.
     *
     * @var array<int, string>
     */
    private const KEPT_FLAT_ACTION_NAMES = [
        'cache_now',
        'delete_cache_record',
    ];

    public static function getNavigationLabel(): string
    {
        return __('Movies');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'vod';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Movies'))
            ->badge($ownerRecord->channels()->count())
            ->icon('heroicon-m-film');
    }

    public function table(Table $table): Table
    {
        // Reuse VodResource's full table setup (columns, filters, eager-loading,
        // pagination, sort) so this view can never drift from the canonical VOD
        // table - the same convention `VodGroups\RelationManagers\VodRelationManager`
        // uses. `$this->ownerRecord->id` is only consulted by setupTable() to decide
        // column visibility (truthy => hide Group/Playlist, same as `showGroup: false,
        // showPlaylist: false` before), not to scope the query - Filament's relation
        // manager machinery already scopes via the `channels` relationship.
        //
        // setupTable() wires up VodResource's full record/bulk actions
        // (edit, delete, fetch metadata, sync, ...). Replace those with the
        // cache-only kebab menu so computed membership stays read-only.
        $cacheColumn = TextColumn::make('cache_progress')
            ->label(__('Cache'))
            ->badge()
            ->getStateUsing(fn (Channel $record): ?CachedContentFile => $this->cachedFileForChannel($record))
            ->formatStateUsing(fn (?CachedContentFile $state): ?string => $state
                ? CachedContentActivityWidget::getProgressLabel($state)
                : null)
            ->color(fn (?CachedContentFile $state): ?string => $state?->status?->getColor())
            ->icon(fn (?CachedContentFile $state): ?string => $state?->status?->getIcon())
            ->placeholder(__('Not cached'));

        $table = VodResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('title')
            ->recordActions([
                ActionGroup::make([
                    VodResource::getCacheNowAction(),
                    Action::make('delete_cache_record')
                        ->label(__('Delete cache'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (Channel $record): bool => $this->cachedFileForChannel($record) !== null)
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete cached file for this movie?'))
                        ->modalDescription(__('Removes the cached file from Storage and deletes the cached_content_files row. Playback will fall back to the live source.'))
                        ->modalSubmitActionLabel(__('Yes, delete cache'))
                        ->action(function (Channel $record): void {
                            $cachedFile = $this->cachedFileForChannel($record);
                            if (! $cachedFile) {
                                return;
                            }

                            CachedContentActivityWidget::deleteCachedFile($cachedFile);

                            Notification::make()
                                ->success()
                                ->title(__('Deleted 1 cached file'))
                                ->send();
                        }),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([]);

        // Filament's recordActions() resets the visible record-actions list but
        // leaves the flat-actions cache (Table::getAction()'s source) populated
        // with everything VodResource::setupTable() wired up: edit, delete,
        // play, view, fetch_tmdb_ids, manual_tmdb_search, sync, probe,
        // process_vod, ... None of those belong on this read-only membership
        // surface, and Livewire::getAction('delete') still resolves to the
        // inherited DeleteAction until they're stripped. Use a whitelist (not a
        // blacklist) so newly-added flat actions on VodResource don't leak
        // through: assertTableActionDoesNotExist('delete', ...) /
        // callTableAction('delete', $channel) must hold here.
        $flatActions = (function (): array {
            return $this->flatActions;
        })->call($table);

        $kept = [];
        foreach (self::KEPT_FLAT_ACTION_NAMES as $name) {
            if (isset($flatActions[$name])) {
                $kept[$name] = $flatActions[$name];
            }
        }

        (function (array $actions): void {
            $this->flatActions = $actions;
        })->call($table, $kept);

        $columns = array_values($table->getColumns());
        $metadataKey = array_search('has_metadata', array_map(
            fn ($column): string => $column->getName(),
            $columns,
        ), true);

        if ($metadataKey !== false) {
            array_splice($columns, $metadataKey, 0, [$cacheColumn]);
        } else {
            $columns[] = $cacheColumn;
        }

        return $table->columns($columns);
    }

    private function cachedFileForChannel(Channel $channel): ?CachedContentFile
    {
        $playlistId = (int) $channel->playlist_id;
        if ($playlistId === 0) {
            return null;
        }

        // PR #1524: lookup follows the same servable rule as playback
        // (see `CachedContentFile::scopeServableForPlaylist()`). The
        // channel's own playlist row is preferred; a Completed row
        // shared by another of the same user's playlists with
        // `share_cache_across_playlists = true` is the fallback.
        return CachedContentFile::query()
            ->servableForPlaylist($playlistId)
            ->where('content_type', 'movie')
            ->where('content_fingerprint', $channel->cacheFingerprint())
            ->orderByRaw('CASE WHEN playlist_id = ? THEN 0 ELSE 1 END', [$playlistId])
            ->first();
    }
}
