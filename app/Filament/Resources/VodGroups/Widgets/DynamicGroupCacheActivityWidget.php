<?php

namespace App\Filament\Resources\VodGroups\Widgets;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Settings\GeneralSettings;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Footer widget on `ListVodGroups` AND `ListCategories` (registered from
 * the Categories side via this same FQN — see `ListCategories::getFooterWidgets()`)
 * showing the most recent `CachedContentFile` rows across all dynamic-group
 * caching activity: status transitions (Pending/Downloading/Completed/Failed),
 * not download percentage. Phase 4 — there is no progress column on the
 * model yet, so this is intentionally a status feed, not a live progress bar.
 *
 * The view is deliberately shared between VOD and Series pages because
 * `CachedContentFile` rows aren't inherently VOD or series until you
 * inspect `content_type` — a single "what's happening across all
 * Dynamic Group caching" view is more useful than splitting it.
 *
 * No polling by default (`->poll()` not configured) — page refresh picks
 * up new activity. A `poll('15s')` would be reasonable future polish
 * but isn't needed at first cut; the recent-activity framing tolerates
 * a few seconds of staleness better than the in-progress bar would.
 *
 * The `getContentLabel()` helper centralizes the content-identity label
 * ("type: tmdb 123") so the column stays readable without an expensive
 * cross-table lookup. Resolving TMDB titles would require either a
 * cached lookup or a join — neither worth the cost for a "what just
 * happened" feed.
 */
class DynamicGroupCacheActivityWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Only render when the cache feature is enabled AND there is at
     * least one row to show — avoids a confusing "no recent activity"
     * empty state on installations that have never run a dispatch.
     */
    public static function canView(): bool
    {
        $settings = app(GeneralSettings::class);

        return (bool) $settings->enable_dynamic_group_cache
            && CachedContentFile::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CachedContentFile::query()
                    ->orderByDesc('updated_at')
                    ->limit(10),
            )
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('content')
                    ->label(__('Content'))
                    ->getStateUsing(fn (CachedContentFile $record): string => self::getContentLabel($record)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CachedContentFileStatus $state): string => $state->getLabel())
                    ->color(fn (CachedContentFileStatus $state): string => $state->getColor())
                    ->icon(fn (CachedContentFileStatus $state): string => $state->getIcon()),
                TextColumn::make('failure_count')
                    ->label(__('Failures'))
                    ->numeric()
                    ->placeholder('0'),
                TextColumn::make('updated_at')
                    ->since()
                    ->label(__('Last Activity'))
                    ->sortable(),
            ])
            ->emptyStateHeading(__('No cache activity yet'))
            ->emptyStateDescription(__('Cached content downloads will appear here once the scheduler runs.'))
            ->emptyStateIcon('heroicon-o-clock')
            ->paginated(false);
    }

    public function getSectionHeading(): string
    {
        return __('Dynamic Group Cache Activity');
    }

    /**
     * Human-readable content identity. Cheap (no DB lookups, no TMDB
     * calls) — meant for an at-a-glance "what just happened" feed, not
     * a fully-resolved title display.
     */
    public static function getContentLabel(CachedContentFile $record): string
    {
        $tmdb = $record->tmdb_id ?: '?';
        $season = $record->season_number !== null ? " S{$record->season_number}" : '';
        $episode = $record->episode_number !== null ? "E{$record->episode_number}" : '';

        return "{$record->content_type}: tmdb {$tmdb}{$season}{$episode}";
    }
}
