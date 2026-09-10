<?php

namespace App\Filament\Resources\DynamicGroups\Widgets;

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
 * Abstract base for the per-type Dynamic Group cache activity widgets.
 *
 * The two thin subclasses — `VodDynamicGroups\Widgets\VodDynamicGroupCacheActivityWidget`
 * (`content_type = 'movie'`) and `SeriesDynamicGroups\Widgets\SeriesDynamicGroupCacheActivityWidget`
 * (`content_type = 'episode'`) — are registered as footer widgets on
 * `ListVodDynamicGroups` and `ListSeriesDynamicGroups` respectively. Each
 * shows the most recent CachedContentFile rows for its own type plus
 * byte-level download progress for in-flight rows.
 *
 * Why split by type now (after running for a while as a single widget):
 * after the "Dynamic Groups" sidebar refactor, the VOD Dynamic Groups
 * and Series Dynamic Groups are two distinct pages. The previous "one
 * widget for both types" pattern made sense when the activity was a
 * footer at the bottom of the unrelated VOD Groups / Categories pages
 * (it had no other home); now that the cache activity has a per-type
 * home, splitting keeps each page's view focused on its own downloads.
 *
 * Polling (`->poll('5s')`) is justified by the live progress column —
 * the 1-MiB-throttled DB writes from
 * `DownloadCachedContentFile::reportDownloadProgress()` are otherwise
 * invisible without a refresh.
 *
 * All the per-record formatters (`getContentLabel`,
 * `getProgressLabel`, `getProgressAttributes`, `getEtaLabel`) are type
 * agnostic — they read fields off the CachedContentFile row directly
 * — so they live on the base and are inherited unchanged.
 *
 * Subclasses only need to set the `contentType` static property. The
 * base automatically narrows `canView()` and the table query to that
 * type.
 */
abstract class DynamicGroupCacheActivityWidget extends BaseWidget
{
    /**
     * The `cached_content_files.content_type` value this widget
     * scopes to. Subclasses MUST set this — the base query and
     * `canView()` both narrow on it. Valid values today are 'movie'
     * (vod-type dynamic groups) and 'episode' (series-type dynamic
     * groups). Declared on the abstract base so PHP allows
     * `static::$contentType` late-static-binding access from the
     * concrete subclass.
     */
    protected static ?string $contentType = null;

    /**
     * When set (and not "all"), narrows the table query to cache
     * rows that belong to a DynamicGroup in the given playlist.
     * Forwarded from the parent page's `activePlaylistTab` so the
     * per-playlist sub-tabs on the Dynamic Groups page scope both
     * the table view AND this widget. Set via the widget's public
     * property by the Livewire parent before render (see
     * ListVodDynamicGroups::content() / ListSeriesDynamicGroups::content()).
     */
    public ?string $activePlaylistId = null;

    /**
     * Custom Blade view that wraps the table in a <x-filament::section>
     * — gives the cache activity its own icon + heading + collapsed
     * state, so it reads as a discrete "clustered section" on the
     * Dynamic Groups listing page rather than just a stacked footer
     * widget. View file lives at
     * resources/views/filament/widgets/dynamic-group-cache-activity-widget.blade.php.
     */
    protected string $view = 'filament.widgets.dynamic-group-cache-activity-widget';

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
        if (! $settings->enable_dynamic_group_cache) {
            return false;
        }

        // Narrow by this widget's content_type so an admin visiting
        // either Dynamic Groups page never sees a confusing empty
        // state caused by only the OTHER type having any rows yet.
        // The per-playlist narrowing happens inside table() — it
        // doesn't affect canView() because the page-level
        // getTabsContentComponent() is always rendered (the widget
        // is just hidden when there's nothing for the active scope).
        return CachedContentFile::query()
            ->where('content_type', static::$contentType)
            ->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Narrow to this widget's content_type so the VOD page
                // doesn't show episode rows and vice versa. When
                // activePlaylistId is set, also filter through the
                // pivot to that playlist's dynamic groups. Limit 10
                // matches the old shared widget so the on-screen
                // density doesn't change.
                CachedContentFile::query()
                    ->where('content_type', static::$contentType)
                    ->when(
                        $this->activePlaylistId && $this->activePlaylistId !== 'all',
                        fn ($q) => $q->whereHas('dynamicGroups', function ($dq) {
                            $dq->where('dynamic_groups.playlist_id', (int) $this->activePlaylistId);
                        }),
                    )
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
                Action::make('viewError')
                    ->label(__('View error'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->tooltip(__('Why did this fail?'))
                    ->visible(fn (CachedContentFile $record): bool => $record->status === CachedContentFileStatus::Failed)
                    ->modalHeading(__('Download failure'))
                    ->modalContent(function (CachedContentFile $record) {
                        $message = $record->last_error_message ?? 'No error message recorded.';
                        $lastFailedAt = $record->last_failed_at?->toDateTimeString() ?? 'never';
                        $failures = (int) ($record->failure_count ?? 0);

                        return view('filament.widgets.partials.download-error-modal', [
                            'message' => $message,
                            'lastFailedAt' => $lastFailedAt,
                            'failures' => $failures,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),

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

    /**
     * Section heading rendered above the table. Shared between the
     * VOD and Series subclasses today — the per-type scope is
     * already conveyed by the parent page (VOD Channels vs Series).
     * If the per-type surface ever needs to disambiguate further
     * (e.g. "VOD Dynamic Group Cache Activity"), this is the seam.
     */
    public function getSectionHeading(): string
    {
        return __('Dynamic Group Cache Activity');
    }

    /**
     * Icon shown in the section header. Per-type so the VOD and
     * Series pages get a slightly different visual — film for VOD,
     * play for Series. The maintainer's "clustered sections"
     * preference leans on each section having its own iconography.
     */
    public function getSectionIcon(): string
    {
        return static::$contentType === 'movie'
            ? 'heroicon-o-film'
            : 'heroicon-o-play';
    }

    /**
     * Body text shown under the heading + used as the ?-tooltip
     * label. Per-type so the VOD and Series explanations don't lie
     * about what the rows represent.
     */
    public function getSectionDescription(): string
    {
        return static::$contentType === 'movie'
            ? __('Live download progress for VOD Dynamic Group cache downloads — Pending, Downloading, Completed, and Failed rows across all your playlists.')
            : __('Live download progress for Series Dynamic Group cache downloads (one row per episode) — Pending, Downloading, Completed, and Failed rows across all your playlists.');
    }

    /**
     * Drives the section's default collapsed state. Auto-collapses
     * when there are no rows for THIS widget's content_type — same
     * scope as canView() so an empty VOD page can't get a confusing
     * "expanded but empty" section just because the Series side has
     * downloads in flight.
     */
    public function hasCacheActivity(): bool
    {
        return CachedContentFile::query()
            ->where('content_type', static::$contentType)
            ->exists();
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
