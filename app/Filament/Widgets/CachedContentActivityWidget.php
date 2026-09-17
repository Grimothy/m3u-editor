<?php

namespace App\Filament\Widgets;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Livewire\ArrQueueMonitor;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\PlaylistUrlService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Standalone per-Channel / per-Episode cache activity widget (PR D).
 *
 * Replaces PR #1500's DynamicGroupCacheActivityWidget surface entirely
 * (no DynamicGroup coupling at any layer). Lists every CachedContentFile
 * row that the current user owns (admins see everything), with byte-level
 * progress / ETA / status / error-message cells for in-flight rows.
 *
 * Visible only when GeneralSettings::enable_cache is true; the page that
 * hosts it (CachedDownloadsPage) gates on the same setting at the
 * navigation level. The widget itself is fully table-driven: pagination
 * goes through Filament's default paginator (no ->all() / ->get() over
 * an unbounded set, see pr-review-standards rule 1).
 *
 * Per-row actions (retry / cancel / deleteCache) and bulk actions
 * (bulkRetry / bulkCancel / bulkDelete) mirror the PR #1500 UX; the
 * retry path resolves the underlying Channel or Episode from the row's
 * stored `playlist_id` + identity fields rather than traversing a
 * DynamicGroup pivot (which no longer exists in the standalone data
 * layer). Ownership is re-verified at the per-row and bulk-action level
 * via `canActOnRecord()` so a non-admin can't act on another user's
 * cache file even if they manage to put its id into the selection.
 */
class CachedContentActivityWidget extends BaseWidget
{
    protected string $view = 'filament.widgets.cached-content-activity-widget';

    protected static bool $isLazy = false;

    protected static ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Render only when the cache feature is enabled AND the viewer is
     * authenticated. The widget stays out of the layout entirely when
     * `enable_cache` is false so the host page (CachedDownloadsPage) is
     * effectively empty in that state.
     */
    public static function canView(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        return (bool) (app(GeneralSettings::class)->enable_cache ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CachedContentFile::query()
                    ->with(['playlist:id,name,uuid'])
                    ->when(
                        ! (auth()->user()?->isAdmin() ?? false),
                        fn ($q) => $q->ownedBy((int) auth()->id()),
                    )
                    ->addSelect([
                        // Read-side projection for getContentLabel(): resolves
                        // the matching Channel's display_title for movie rows.
                        // Mirrors Channel::getDisplayTitleAttribute() precedence
                        // (title_custom -> title -> name_custom -> name) and
                        // folds empty strings into NULL so getContentLabel()
                        // can fall through cleanly. Eloquent Builder is used
                        // for the WHERE / LIMIT so global scopes (e.g.
                        // ExcludeAioFailoverClonesScope on Channel) are
                        // inherited; selectRaw only sets the SELECT clause
                        // and does not bypass scopes.
                        'movie_source_title' => Channel::query()
                            ->selectRaw("nullif(trim(coalesce(title_custom, title, name_custom, name)), '')")
                            ->whereColumn('channels.playlist_id', 'cached_content_files.playlist_id')
                            ->where('channels.is_vod', true)
                            ->where(function ($q) {
                                $q->where(function ($q) {
                                    $q->whereNotNull('cached_content_files.tmdb_id')
                                        ->whereColumn('channels.tmdb_id', 'cached_content_files.tmdb_id');
                                })->orWhere(function ($q) {
                                    $q->whereNotNull('cached_content_files.tvdb_id')
                                        ->whereColumn('channels.tvdb_id', 'cached_content_files.tvdb_id');
                                });
                            })
                            ->limit(1),
                        // Read-side projection for episode rows: resolves the
                        // matching Episode's title through its parent Series.
                        // Strict identity rule: cached row must have non-null
                        // tmdb_id OR tvdb_id; if both null the subquery
                        // returns no row and the projection is NULL, which
                        // getContentLabel() falls through to the fingerprint.
                        'episode_source_title' => Episode::query()
                            ->selectRaw("nullif(trim(coalesce(episodes.title, '')), '')")
                            ->join('series', 'series.id', '=', 'episodes.series_id')
                            ->whereColumn('series.playlist_id', 'cached_content_files.playlist_id')
                            ->whereColumn('episodes.season', 'cached_content_files.season_number')
                            ->whereColumn('episodes.episode_num', 'cached_content_files.episode_number')
                            ->where(function ($q) {
                                $q->where(function ($q) {
                                    $q->whereNotNull('cached_content_files.tmdb_id')
                                        ->whereColumn('series.tmdb_id', 'cached_content_files.tmdb_id');
                                })->orWhere(function ($q) {
                                    $q->whereNotNull('cached_content_files.tvdb_id')
                                        ->whereColumn('series.tvdb_id', 'cached_content_files.tvdb_id');
                                });
                            })
                            ->limit(1),
                    ])
                    ->orderByDesc('updated_at'),
            )
            ->defaultSort('updated_at', 'desc')
            ->defaultPaginationPageOption(15)
            ->paginated([10, 25, 50])
            ->poll('5s')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->placeholder('—')
                    ->getStateUsing(fn (CachedContentFile $record): string => self::getContentLabel($record))
                    ->searchable(['title', 'tmdb_id', 'tvdb_id'])
                    ->wrap()
                    ->limit(60),
                TextColumn::make('content_type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'movie' => __('VOD'),
                        'episode' => __('Episode'),
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'movie' => 'info',
                        'episode' => 'primary',
                        default => 'gray',
                    })
                    ->width('90px'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (CachedContentFileStatus $state): string => $state->getLabel())
                    ->color(fn (CachedContentFileStatus $state): string => $state->getColor())
                    ->icon(fn (CachedContentFileStatus $state): string => $state->getIcon()),
                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->placeholder('—')
                    ->getStateUsing(fn (CachedContentFile $record): ?string => self::getProgressLabel($record))
                    ->extraAttributes(fn (CachedContentFile $record): array => self::getProgressAttributes($record))
                    ->width('220px'),
                TextColumn::make('eta')
                    ->label(__('ETA'))
                    ->placeholder('—')
                    ->getStateUsing(fn (CachedContentFile $record): ?string => self::getEtaLabel($record))
                    ->width('90px'),
                TextColumn::make('failure_count')
                    ->label(__('Failures'))
                    ->numeric()
                    ->placeholder('0')
                    ->width('90px'),
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->placeholder('—')
                    ->toggleable()
                    ->width('140px'),
                TextColumn::make('updated_at')
                    ->since()
                    ->label(__('Last Activity'))
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('viewError')
                        ->label(__('View error'))
                        ->icon('heroicon-o-exclamation-triangle')
                        ->visible(fn (CachedContentFile $record): bool => $record->status === CachedContentFileStatus::Failed)
                        ->modalHeading(__('Download failure'))
                        ->modalContent(function (CachedContentFile $record) {
                            $message = $record->last_error_message ?? __('No error message recorded.');
                            $lastFailedAt = $record->last_failed_at?->toDateTimeString() ?? __('never');
                            $failures = (int) ($record->failure_count ?? 0);

                            return view('filament.widgets.partials.download-error-modal', [
                                'message' => $message,
                                'lastFailedAt' => $lastFailedAt,
                                'failures' => $failures,
                            ]);
                        })
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close')),

                    Action::make('retry')
                        ->label(__('Retry download'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->visible(fn (CachedContentFile $record): bool => $record->status === CachedContentFileStatus::Failed)
                        ->requiresConfirmation()
                        ->modalHeading(__('Retry this download?'))
                        ->modalDescription(__('Re-dispatch the DownloadCachedContentFile job for this row, bypassing the failure cooldown. The worker will reclaim the row from Failed to Downloading atomically.'))
                        ->modalSubmitActionLabel(__('Retry'))
                        ->action(function (CachedContentFile $record): void {
                            if (! self::retryCachedFile($record)) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Could not retry'))
                                    ->body(__('No source Channel or Episode could be resolved for this row. The underlying content may have been removed.'))
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Retry queued'))
                                ->body(__('Track progress in this widget.'))
                                ->send();
                        }),

                    Action::make('cancel')
                        ->label(__('Cancel download'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn (CachedContentFile $record): bool => in_array($record->status, [
                            CachedContentFileStatus::Pending,
                            CachedContentFileStatus::Downloading,
                        ], true))
                        ->requiresConfirmation()
                        ->modalHeading(__('Cancel this in-flight download?'))
                        ->modalDescription(__('Removes the tracking row and any partial storage file, and stops the transfer. An active download stops within a few seconds; a not-yet-started one may rarely still begin if a worker was already about to pick it up.'))
                        ->modalSubmitActionLabel(__('Cancel download'))
                        ->action(function (CachedContentFile $record): void {
                            self::deleteCachedFile($record);

                            Notification::make()
                                ->success()
                                ->title(__('Download cancelled'))
                                ->body(__('The row and any partial file were removed.'))
                                ->send();
                        }),

                    Action::make('deleteCache')
                        ->label(__('Delete cache'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete this cached file?'))
                        ->modalDescription(__('This removes the file from Storage and deletes the cached_content_files row. Playback will fall back to the live source.'))
                        ->modalSubmitActionLabel(__('Delete'))
                        ->action(function (CachedContentFile $record): void {
                            self::deleteCachedFile($record);

                            Notification::make()
                                ->success()
                                ->title(__('Cached file deleted'))
                                ->body(__('The cached file was removed from Storage.'))
                                ->send();
                        }),
                ]),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkRetry')
                        ->label(__('Retry selected'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading(__('Retry selected downloads?'))
                        ->modalDescription(__('Re-dispatch the DownloadCachedContentFile job for every Failed row in the selection, bypassing the failure cooldown. Pending, Downloading, and Completed rows are skipped.'))
                        ->modalSubmitActionLabel(__('Retry selected'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records = self::filterToOwnedRecords($records);
                            $retried = 0;
                            $skipped = 0;
                            foreach ($records as $record) {
                                if ($record->status !== CachedContentFileStatus::Failed) {
                                    $skipped++;

                                    continue;
                                }
                                if (self::retryCachedFile($record)) {
                                    $retried++;
                                } else {
                                    $skipped++;
                                }
                            }
                            Notification::make()
                                ->success()
                                ->title($retried === 1 ? __('Retry queued for 1 row') : __('Retry queued for :count rows', ['count' => $retried]))
                                ->body($skipped > 0 ? __(':skipped row(s) skipped (not Failed, or no source resolvable).', ['skipped' => $skipped]) : null)
                                ->send();
                        }),

                    BulkAction::make('bulkCancel')
                        ->label(__('Cancel selected'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('Cancel selected in-flight downloads?'))
                        ->modalDescription(__('Removes the tracking row and any partial storage file for every Pending or Downloading row in the selection, and stops the transfers. Failed and Completed rows are skipped.'))
                        ->modalSubmitActionLabel(__('Cancel selected'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records = self::filterToOwnedRecords($records);
                            $cancelled = 0;
                            $skipped = 0;
                            foreach ($records as $record) {
                                if (! in_array($record->status, [
                                    CachedContentFileStatus::Pending,
                                    CachedContentFileStatus::Downloading,
                                ], true)) {
                                    $skipped++;

                                    continue;
                                }
                                self::deleteCachedFile($record);
                                $cancelled++;
                            }
                            Notification::make()
                                ->success()
                                ->title($cancelled === 1 ? __('Cancelled 1 download') : __('Cancelled :count downloads', ['count' => $cancelled]))
                                ->body($skipped > 0 ? __(':skipped row(s) skipped (not in-flight).', ['skipped' => $skipped]) : null)
                                ->send();
                        }),

                    BulkAction::make('bulkDelete')
                        ->label(__('Delete selected'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete selected cached files?'))
                        ->modalDescription(__('Removes the row and storage file for every selected row. Any row still Pending or Downloading is also signaled to abort and stops within a few seconds.'))
                        ->modalSubmitActionLabel(__('Delete selected'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records = self::filterToOwnedRecords($records);
                            $count = 0;
                            foreach ($records as $record) {
                                self::deleteCachedFile($record);
                                $count++;
                            }
                            Notification::make()
                                ->success()
                                ->title($count === 1 ? __('Deleted 1 cached file') : __('Deleted :count cached files', ['count' => $count]))
                                ->send();
                        }),
                ]),
            ])
            ->emptyStateHeading(__('No cache activity yet'))
            ->emptyStateDescription(__('Cached content downloads will appear here once the dispatcher runs or you click "Cache Now" on a Channel or Episode row.'))
            ->emptyStateIcon('heroicon-o-clock');
    }

    /**
     * Section heading rendered above the table by the wrapping blade view.
     */
    public function getSectionHeading(): string
    {
        return __('Cache Activity');
    }

    /**
     * Section description / tooltip text. Used both as the heading body
     * and the after-header help icon's tooltip.
     */
    public function getSectionDescription(): string
    {
        return __('Live download progress for cached VOD channels and Series episodes. Pending, Downloading, Completed, and Failed rows across your playlists are listed here.');
    }

    /**
     * Human-readable content identity for the title column.
     *
     * 3-tier resolution:
     *  1. The cached row's own `title` column (currently always null
     *     because dispatch + job never populate it; left in place as a
     *     future backfill seam).
     *  2. The widget query's `movie_source_title` / `episode_source_title`
     *     projection, resolved via Channel::getDisplayTitleAttribute() or
     *     Episode::getDisplayTitleAttribute() against the source row that
     *     matches the cached row's playlist + tmdb/tvdb identity.
     *  3. The structured fingerprint label as a final fallback so legacy
     *     rows and rows whose source records were deleted remain informative.
     */
    public static function getContentLabel(CachedContentFile $record): string
    {
        if ($record->title !== null && $record->title !== '') {
            return $record->title;
        }

        $contentType = $record->content_type;
        if (in_array($contentType, ['movie', 'vod'], true)) {
            $sourceTitle = trim((string) ($record->movie_source_title ?? ''));
            if ($sourceTitle !== '') {
                return $sourceTitle;
            }
        } elseif ($contentType === 'episode') {
            $sourceTitle = trim((string) ($record->episode_source_title ?? ''));
            if ($sourceTitle !== '') {
                return $sourceTitle;
            }
        }

        $tmdb = $record->tmdb_id ?: '?';
        $season = $record->season_number !== null ? " S{$record->season_number}" : '';
        $episode = $record->episode_number !== null ? "E{$record->episode_number}" : '';

        return "{$record->content_type}: tmdb {$tmdb}{$season}{$episode}";
    }

    /**
     * Formatted progress cell. Status-aware so every row produces a label
     * (the column placeholder only shows when this returns null):
     *  - Pending: translated status label ("Pending").
     *  - Downloading: bytes / total + percent (existing behavior).
     *  - Completed: final file size (file_size_bytes with bytes_downloaded
     *    fallback). Falls back to the translated "Completed" label when
     *    no size is recorded.
     *  - Failed: last-known bytes_downloaded. Falls back to the translated
     *    "Failed" label when no bytes are recorded. Detailed error message
     *    remains available via the per-row viewError action.
     */
    public static function getProgressLabel(CachedContentFile $record): ?string
    {
        $status = $record->status;

        if ($status === CachedContentFileStatus::Pending) {
            return $status->getLabel();
        }

        if ($status === CachedContentFileStatus::Downloading) {
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

        if ($status === CachedContentFileStatus::Completed) {
            $size = $record->file_size_bytes ?? $record->bytes_downloaded;

            return ($size !== null && $size > 0)
                ? ArrQueueMonitor::formatBytes((int) $size)
                : $status->getLabel();
        }

        if ($status === CachedContentFileStatus::Failed) {
            $size = $record->bytes_downloaded;

            return ($size !== null && $size > 0)
                ? ArrQueueMonitor::formatBytes((int) $size)
                : $status->getLabel();
        }

        return null;
    }

    /**
     * Inline-style attributes that paint a thin progress bar behind the
     * text cell when both bytes are known. Stale rows (last_progress_at
     * older than 30s) get an amber bar to flag a stalled download.
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
     * Formatted ETA cell. Returns null for non-Downloading rows, stalled
     * rows (last_progress_at > 30s), or rows without both bytes_expected
     * and bytes_per_second.
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

    /**
     * Shared delete implementation for both per-row deleteCache / cancel
     * actions and the bulk variants. Removes the storage file (when
     * present) then deletes the row. Cancellation is signalled BEFORE the
     * delete so an in-flight worker picks up the flag instead of running
     * to completion against an orphaned file - see
     * DownloadCachedContentFile::checkCancellation() and the
     * pendingCancellationCacheKey consumption path.
     */
    public static function deleteCachedFile(CachedContentFile $record): void
    {
        if (! self::canActOnRecord($record)) {
            return;
        }

        if (in_array($record->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
            // Set the row marker for Pending too: the worker may have
            // reclaimed it to Downloading between our read and deletion.
            Cache::put(CachedContentFile::cancellationCacheKey($record->id), true, now()->addHours(48));
        }
        if ($record->status === CachedContentFileStatus::Pending) {
            Cache::put(CachedContentFile::pendingCancellationCacheKey($record->content_fingerprint), true, now()->addMinutes(10));
        }

        if (! empty($record->file_path)) {
            try {
                Storage::disk($record->resolveStorageDisk())->delete($record->file_path);
            } catch (\Throwable $e) {
                Log::warning("CachedContentActivityWidget: failed to delete storage file {$record->file_path} for cache entry {$record->id}: {$e->getMessage()}");
            }
        }
        $record->delete();
    }

    /**
     * Re-dispatch DownloadCachedContentFile for a Failed row, bypassing
     * the failure cooldown. Resolves the source Channel or Episode from
     * the row's stored playlist_id + identity fields. Clears failure
     * bookkeeping so the worker's atomic UPDATE always wins the reclaim
     * race.
     *
     * Returns false when:
     *  - The current user can't act on the row (ownership check).
     *  - No matching Channel or Episode can be resolved.
     *  - The row isn't in a Failed state.
     */
    public static function retryCachedFile(CachedContentFile $record): bool
    {
        if (! self::canActOnRecord($record)) {
            return false;
        }

        if ($record->status !== CachedContentFileStatus::Failed) {
            return false;
        }

        $playlist = $record->playlist;
        if (! $playlist) {
            return false;
        }

        $item = match ($record->content_type) {
            'movie' => self::resolveChannel($playlist, $record),
            'episode' => self::resolveEpisode($playlist, $record),
            default => null,
        };
        if (! $item) {
            return false;
        }

        $oldPath = $record->getOriginal('file_path');

        $record->update([
            'failure_count' => 0,
            'last_failed_at' => null,
            'last_error_message' => null,
            'bytes_downloaded' => 0,
            'bytes_expected' => null,
            'bytes_per_second' => null,
            'last_progress_at' => null,
            'file_path' => null,
        ]);

        if (! empty($oldPath)) {
            try {
                Storage::disk($record->resolveStorageDisk())->delete($oldPath);
            } catch (\Throwable $e) {
                Log::warning("CachedContentActivityWidget: failed to delete storage file on retry for cache entry {$record->id}: {$e->getMessage()}");
            }
        }

        DownloadCachedContentFile::dispatch($item, $record->id)->onQueue('cache');

        return true;
    }

    /**
     * Ownership check mirroring the widget's query. The query uses
     * `->ownedBy(auth()->id())` for non-admins; the cache-hit row + bulk
     * handlers run their own last-line check via this method so a
     * non-admin can't act on another user's row by ID guessing.
     */
    private static function canActOnRecord(CachedContentFile $record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $record->user_id === (int) $user->id;
    }

    /**
     * Bulk-action helper: drop any rows the current user can't act on.
     */
    private static function filterToOwnedRecords(Collection $records): Collection
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin()) {
            return $records;
        }

        return $records->filter(fn (CachedContentFile $record): bool => $record->user_id === (int) $user->id);
    }

    /**
     * Resolve the underlying Channel row for a movie-type cache row.
     */
    private static function resolveChannel(Playlist $playlist, CachedContentFile $record): ?Channel
    {
        $query = Channel::query()->where('playlist_id', $playlist->id)->where('is_vod', true);

        if ($record->tmdb_id) {
            $query->where('tmdb_id', (int) $record->tmdb_id);
        }
        if ($record->tvdb_id) {
            $query->where('tvdb_id', (int) $record->tvdb_id);
        }

        $channel = $query->first();
        if (! $channel) {
            return null;
        }

        $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
        if (! $url) {
            return null;
        }

        return $channel;
    }

    /**
     * Resolve the underlying Episode row for an episode-type cache row.
     */
    private static function resolveEpisode(Playlist $playlist, CachedContentFile $record): ?Episode
    {
        $series = Series::query()
            ->where('playlist_id', $playlist->id)
            ->when($record->tmdb_id, fn ($q) => $q->where('tmdb_id', (int) $record->tmdb_id))
            ->when($record->tvdb_id, fn ($q) => $q->where('tvdb_id', (int) $record->tvdb_id))
            ->first();
        if (! $series) {
            return null;
        }

        $episode = Episode::query()
            ->where('series_id', $series->id)
            ->where('season', (int) ($record->season_number ?? 0))
            ->where('episode_num', (int) ($record->episode_number ?? 0))
            ->first();
        if (! $episode) {
            return null;
        }

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        if (! $url) {
            return null;
        }

        return $episode;
    }
}
