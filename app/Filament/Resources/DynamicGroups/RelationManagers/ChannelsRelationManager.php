<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * VOD (Channel) members of the parent DynamicGroup. Visible only when the
 * parent's `type` is `'vod'` - DynamicGroups are single-type by construction
 * (matching their `dynamic_groups_config` rule row), so a series-type parent
 * has zero channels to show and the tab is hidden.
 *
 * The relation itself is strictly read-only (membership is computed by
 * `SyncDynamicGroups`), but the table exposes a per-row "Cache Now" bulk
 * action so operators can pick which channels to download — useful when
 * the dynamic group has hundreds of items and only a subset is worth
 * pre-caching.
 */
class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $title = 'Movies';

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
        // setupTable() wires up VodResource's record/bulk actions (edit, delete,
        // fetch metadata, sync, ...), which would break this manager's read-only
        // contract - strip the recordActions back out, then add ONLY the Cache Now
        // bulk action so operators can pick which movies to download.
        return VodResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('title')
            ->recordActions([])
            ->bulkActions([
                BulkAction::make('cache_now')
                    ->label(__('Cache Now'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cache selected movies'))
                    ->modalDescription(__('Queue download jobs for every selected movie? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget.'))
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

                        /** @var DynamicGroup $group */
                        $group = $this->ownerRecord;
                        $playlist = $group->playlist;
                        if (! $playlist) {
                            return;
                        }

                        /** @var DynamicGroupCacheDispatchService $service */
                        $service = app(DynamicGroupCacheDispatchService::class);
                        $rule = $service->resolveRuleForGroup($group);

                        $dispatched = 0;
                        $skipped = 0;

                        /** @var Channel $channel */
                        foreach ($records as $channel) {
                            if (! $rule || ! ($rule['cache_enabled'] ?? false)) {
                                $skipped++;

                                continue;
                            }
                            if ($service->dispatchForChannel($playlist, $group, $channel, $rule)) {
                                $dispatched++;
                            } else {
                                $skipped++;
                            }
                        }

                        if ($dispatched === 0) {
                            Notification::make()
                                ->info()
                                ->title(__('No cache jobs queued'))
                                ->body($skipped > 0
                                    ? __('Skipped :skipped — caching not enabled for this group.', ['skipped' => $skipped])
                                    : __('Nothing eligible to cache.')
                                )
                                ->send();

                            return;
                        }

                        $title = $dispatched === 1
                            ? __('Dispatched 1 cache job.')
                            : __('Dispatched :count cache jobs.', ['count' => $dispatched]);
                        Notification::make()
                            ->success()
                            ->title($title)
                            ->body($skipped > 0
                                ? __('Skipped :skipped.', ['skipped' => $skipped])
                                : __('Track progress in the Dynamic Group Cache Activity widget.')
                            )
                            ->duration(10000)
                            ->send();
                    }),
            ]);
    }
}
