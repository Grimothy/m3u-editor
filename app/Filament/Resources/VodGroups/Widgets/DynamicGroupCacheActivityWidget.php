<?php

namespace App\Filament\Resources\VodGroups\Widgets;

use App\Enums\CachedContentFileStatus;
use App\Livewire\ArrQueueMonitor;
use App\Models\CachedContentFile;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
                TextColumn::make('eta')
                    ->label(__('ETA'))
                    ->placeholder('—')
                    ->getStateUsing(fn (CachedContentFile $record): ?string => self::getEtaLabel($record))
                    ->width('90px'),
                TextColumn::make('failure_count')
                    ->label(__('Failures'))
                    ->numeric()
                    ->placeholder('0'),
                TextColumn::make('updated_at')
                    ->since()
                    ->label(__('Last Activity'))
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('deleteCache')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->tooltip(__('Delete cache'))
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete this cached file?'))
                    ->modalDescription(function (CachedContentFile $record): string {
                        $otherGroups = max(0, $record->dynamicGroups()->count() - 1);
                        $sharedWarning = $otherGroups > 0
                            ? sprintf(
                                ' This file is also cached for %d other %s — deleting removes it from Storage entirely and those groups will fall back to the live source until a new download completes.',
                                $otherGroups,
                                $otherGroups === 1 ? 'group' : 'groups',
                            )
                            : '';

                        return __('This removes the file from Storage and deletes the cached_content_files row. Playback will fall back to the live source.').$sharedWarning;
                    })
                    ->modalSubmitActionLabel(__('Delete'))
                    ->before(function (CachedContentFile $record): void {
                        if (! empty($record->file_path)) {
                            try {
                                Storage::disk($record->resolveStorageDisk())->delete($record->file_path);
                            } catch (\Throwable $e) {
                                Log::warning("DynamicGroupCacheActivityWidget: failed to delete storage file {$record->file_path} for cache entry {$record->id}: {$e->getMessage()}");
                            }
                        }
                    })
                    ->action(function (CachedContentFile $record): void {
                        // Standard $record->delete() cascades the pivot FKs and is
                        // enough on its own — the Storage file is gone by ->before().
                        $record->delete();
                    }),
            ], RecordActionsPosition::BeforeCells)
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

    /**
     * Formatted ETA cell for the widget's ETA column. Returns null when:
     *   - status is not Downloading (placeholder "—" shows)
     *   - bytes_expected is null (chunked transfer, no total known)
     *   - bytes_per_second is null/0 (first window hasn't completed yet)
     *   - last_progress_at is older than 30s (treat as stalled; consistent
     *     with the amber "stalled" indicator on the Progress bar)
     *
     * Format:
     *   < 60s       → "42s"
     *   < 60m       → "2m 14s"
     *   < 24h       → "1h 23m"
     *   >= 24h      → "1d 2h"
     *   <= 0 sec    → null (no point showing "ETA done")
     */
    public static function getEtaLabel(CachedContentFile $record): ?string
    {
        if ($record->status !== CachedContentFileStatus::Downloading) {
            return null;
        }

        $rate = (int) ($record->bytes_per_second ?? 0);
        $expected = $record->bytes_expected !== null ? (int) $record->bytes_expected : null;
        $downloaded = (int) ($record->bytes_downloaded ?? 0);

        if ($rate <= 0 || $expected === null || $expected <= $downloaded) {
            return null;
        }

        // Stalled — last update too old, rate is no longer reliable.
        if ($record->last_progress_at !== null
            && $record->last_progress_at->lt(now()->subSeconds(30))) {
            return null;
        }

        $remaining = $expected - $downloaded;
        $etaSeconds = (int) ceil($remaining / $rate);

        if ($etaSeconds <= 0) {
            return null;
        }

        return self::formatEtaSeconds($etaSeconds);
    }

    /**
     * Format a duration in seconds as a compact human-readable ETA label.
     *
     * Examples: 42 → "42s", 134 → "2m 14s", 4980 → "1h 23m",
     * 90061 → "1d 1h".
     */
    public static function formatEtaSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;

            return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        }

        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);

            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);

        return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
    }
}
