<?php

namespace App\Filament\Resources\VodGroups\Widgets;

use App\Enums\CachedContentFileStatus;
use App\Livewire\ArrQueueMonitor;
use App\Models\CachedContentFile;
use App\Settings\GeneralSettings;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Footer widget on `ListVodGroups` AND `ListCategories` (registered from
 * the Categories side via this same FQN — see `ListCategories::getFooterWidgets()`)
 * showing the most recent `CachedContentFile` rows across all dynamic-group
 * caching activity: status transitions (Pending/Downloading/Completed/Failed)
 * plus byte-level download progress for in-flight rows.
 *
 * The view is deliberately shared between VOD and Series pages because
 * `CachedContentFile` rows aren't inherently VOD or series until you
 * inspect `content_type` — a single "what's happening across all
 * Dynamic Group caching" view is more useful than splitting it.
 *
 * Polling (`->poll('5s')`) is now justified by the live progress column:
 * the 1-MiB-throttled DB writes from `DownloadCachedContentFile::reportDownloadProgress()`
 * are otherwise invisible without a refresh. With at most
 * `dynamic_group_cache_max_concurrent_downloads` (default 2) active
 * downloads, the polling cost is trivial.
 *
 * The `getContentLabel()` helper prefers the resolved TMDB title
 * ("Wicked", "Breaking Bad — I.F.T.") populated by
 * DownloadCachedContentFile::resolveAndStoreTitle() at row-create time.
 * Falls back to the legacy "type: tmdb N S##E##" shape for rows that
 * pre-date the title column or whose TMDB lookup failed.
 *
 * The `getProgressLabel()` helper formats the progress cell. Reuses
 * `ArrQueueMonitor::formatBytes()` so the unit display matches the
 * existing Arr-download widget in this project.
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
            ->poll('5s')
            ->columns([
                TextColumn::make('content')
                    ->label(__('Content'))
                    ->getStateUsing(fn (CachedContentFile $record): string => self::getContentLabel($record)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CachedContentFileStatus $state): string => $state->getLabel())
                    ->color(fn (CachedContentFileStatus $state): string => $state->getColor())
                    ->icon(fn (CachedContentFileStatus $state): string => $state->getIcon()),
                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->placeholder('—')
                    ->getStateUsing(fn (CachedContentFile $record): ?string => self::getProgressLabel($record))
                    ->extraAttributes(fn (CachedContentFile $record): array => self::getProgressAttributes($record))
                    ->width('180px'),
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
     * Human-readable content identity.
     *
     * Prefers the resolved title when present (populated by
     * DownloadCachedContentFile::resolveAndStoreTitle() from TMDB). Falls
     * back to the legacy "type: tmdb N S##E##" shape for rows that
     * pre-date the title column or whose TMDB lookup failed — keeps the
     * widget informative even with partial coverage.
     */
    public static function getContentLabel(CachedContentFile $record): string
    {
        if ($record->title !== null && $record->title !== '') {
            return $record->title;
        }

        $tmdb = $record->tmdb_id ?: '?';
        $season = $record->season_number !== null ? " S{$record->season_number}" : '';
        $episode = $record->episode_number !== null ? "E{$record->episode_number}" : '';

        return "{$record->content_type}: tmdb {$tmdb}{$season}{$episode}";
    }

    /**
     * Formatted progress cell for a CachedContentFile row.
     *
     * Returns null for non-Downloading rows so the column placeholder
     * shows (keeps the table tidy). For Downloading rows with both
     * bytes_downloaded and bytes_expected set, returns "1.23 GB /
     * 4.50 GB (27%)"; if bytes_expected is null (chunked transfer / no
     * Content-Length), returns just "1.23 GB" of current progress.
     */
    public static function getProgressLabel(CachedContentFile $record): ?string
    {
        if ($record->status !== CachedContentFileStatus::Downloading) {
            return null;
        }

        $downloaded = (int) ($record->bytes_downloaded ?? 0);
        if ($downloaded <= 0) {
            return null;
        }

        $current = ArrQueueMonitor::formatBytes($downloaded);
        $expected = $record->bytes_expected !== null
            ? (int) $record->bytes_expected
            : null;

        if ($expected === null || $expected <= 0) {
            return $current;
        }

        $percent = (int) min(100, round(($downloaded / $expected) * 100));

        return sprintf('%s / %s (%d%%)', $current, ArrQueueMonitor::formatBytes($expected), $percent);
    }

    /**
     * Inline-style attributes that paint a thin progress bar behind the
     * text cell when both bytes are known. The bar is purely visual;
     * the actual numbers come from getProgressLabel().
     *
     * Width = min(100, downloaded/expected * 100) percent of the cell.
     * Stale rows (last_progress_at > 30s ago) get a desaturated color so
     * the operator can tell at a glance which downloads have stalled.
     */
    public static function getProgressAttributes(CachedContentFile $record): array
    {
        if ($record->status !== CachedContentFileStatus::Downloading) {
            return [];
        }

        $downloaded = (int) ($record->bytes_downloaded ?? 0);
        $expected = $record->bytes_expected !== null ? (int) $record->bytes_expected : null;

        if ($downloaded <= 0 || $expected === null || $expected <= 0) {
            return [];
        }

        $percent = min(100, (int) round(($downloaded / $expected) * 100));

        $stale = $record->last_progress_at !== null
            && $record->last_progress_at->lt(now()->subSeconds(30));

        $barColor = $stale ? 'bg-amber-400' : 'bg-primary-500';

        return [
            'style' => sprintf(
                'background: linear-gradient(to right, %s %d%%, transparent %d%%); padding: 2px 6px; border-radius: 4px;',
                $barColor,
                $percent,
                $percent,
            ),
        ];
    }
}
