<?php

namespace App\Models;

use App\Enums\CachedContentFileStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $playlist_id
 * @property string $content_type
 * @property string|null $tmdb_id
 * @property string|null $tvdb_id
 * @property int|null $season_number
 * @property int|null $episode_number
 * @property string|null $quality
 * @property string $content_fingerprint
 * @property string|null $title
 * @property string|null $disk
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property int|null $bytes_downloaded
 * @property int|null $bytes_expected
 * @property int|null $bytes_per_second
 * @property Carbon|null $last_progress_at
 * @property CachedContentFileStatus $status
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $last_failed_at
 * @property string|null $last_error_message
 * @property int $failure_count
 * @property bool $never_expire
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> ownedBy(int $userId)
 * @method static Builder<static> ownedByPlaylist(int $playlistId)
 * @method static Builder<static> ownedByDynamicGroup(int $dynamicGroupId)
 */
class CachedContentFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'playlist_id',
        'content_type',
        'tmdb_id',
        'tvdb_id',
        'season_number',
        'episode_number',
        'quality',
        'content_fingerprint',
        'title',
        'disk',
        'file_path',
        'file_size_bytes',
        'bytes_downloaded',
        'bytes_expected',
        'bytes_per_second',
        'last_progress_at',
        'status',
        'last_verified_at',
        'last_failed_at',
        'last_error_message',
        'failure_count',
        'never_expire',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CachedContentFileStatus::class,
            'failure_count' => 'integer',
            'never_expire' => 'boolean',
            'last_verified_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'file_size_bytes' => 'integer',
            'bytes_downloaded' => 'integer',
            'bytes_expected' => 'integer',
            'bytes_per_second' => 'integer',
            'season_number' => 'integer',
            'episode_number' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CachedContentFile $file): void {
            if (empty($file->uuid)) {
                $file->uuid = (string) Str::uuid();
            }

            if (empty($file->content_fingerprint)) {
                $file->content_fingerprint = static::fingerprintFor($file->getAttributes());
            }

            if (static::normalizeContentType($file->content_type) === '') {
                throw new \InvalidArgumentException('CachedContentFile requires non-empty content_type.');
            }
        });
    }

    /**
     * Build a deterministic content fingerprint from identity parts.
     *
     * Format: content_type:tmdb_id:tvdb_id:season_number:episode_number:quality
     * - content_type: lowercased+trimmed, REQUIRED (throws if empty)
     * - quality: lowercased+trimmed
     * - tmdb_id/tvdb_id: string-coerced
     * - season_number/episode_number: (int) cast then stringified (no leading zeros)
     *
     * @param  array{content_type: string, tmdb_id?: string|int|null, tvdb_id?: string|int|null, season_number?: int|null, episode_number?: int|null, quality?: string|null}  $parts
     */
    public static function fingerprintFor(array $parts): string
    {
        $contentType = static::normalizeContentType($parts['content_type'] ?? null);
        if ($contentType === '') {
            throw new \InvalidArgumentException('CachedContentFile::fingerprintFor() requires non-empty content_type.');
        }

        $tmdbId = (string) ($parts['tmdb_id'] ?? '');
        $tvdbId = (string) ($parts['tvdb_id'] ?? '');
        $season = $parts['season_number'] ?? null;
        $episode = $parts['episode_number'] ?? null;
        $seasonStr = ($season === null || $season === '') ? '' : (string) (int) $season;
        $episodeStr = ($episode === null || $episode === '') ? '' : (string) (int) $episode;
        $quality = strtolower(trim((string) ($parts['quality'] ?? '')));

        return $contentType.':'.$tmdbId.':'.$tvdbId.':'.$seasonStr.':'.$episodeStr.':'.$quality;
    }

    /**
     * Normalize a content_type value to its canonical lowercase form.
     */
    public static function normalizeContentType(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * Cache key used to signal cancellation of an in-flight (Downloading)
     * download to the worker running DownloadCachedContentFile::handle().
     * Keyed by row id, which is never reused (Postgres bigserial), so a
     * cancelled row's key can never collide with a later, unrelated
     * dispatch for the same content.
     *
     * Covers BOTH windows:
     *  - Pending rows: the widget writes the flag, then deletes the row.
     *    The worker's `find()` returns null and it exits cleanly before
     *    doing anything.
     *  - Already-reclaimed (Downloading) rows: the worker polls the key
     *    from `checkCancellation()` and aborts the stream.
     *
     * The previous fingerprint-keyed `pendingCancellationCacheKey` was
     * removed because deleting the row before pickup already covers the
     * Pending window, and a fingerprint-scoped flag with a TTL would
     * silently suppress the NEXT legitimate dispatch for the same content
     * if the flag outlived the original cancel.
     */
    public static function cancellationCacheKey(int $id): string
    {
        return "dynamic-group-cache:cancel:{$id}";
    }

    /**
     * Source playlist that owns this cached file.
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * DynamicGroups that requested this file cached (Phase 2 / PR E).
     *
     * Many-to-many via the `cached_content_file_dynamic_groups` pivot.
     * The pivot carries `dropped_at` (nullable timestamp) so the relation
     * can be filtered to "live" (unshared) rows with
     * `->wherePivot('dropped_at', null)` at the call site. The
     * `scopeOwnedByDynamicGroup()` below applies that filter at the query
     * level — callers that just want "the rows this group ever cached"
     * should call `->dynamicGroups()` directly and check `pivot->dropped_at`
     * in PHP.
     *
     * `withPivot('dropped_at')` exposes the column on the pivot model
     * for both reads (retention checks) and writes (SyncDynamicGroups'
     * soft-unshare path).
     */
    public function dynamicGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            DynamicGroup::class,
            'cached_content_file_dynamic_groups',
        )->withPivot('dropped_at')->withTimestamps();
    }

    /**
     * Filter to cached files owned by the given user.
     *
     * Ownership is stored directly on `cached_content_files.user_id` (PR A
     * migration `2026_09_16_120000_add_user_id_to_cached_content_files_table`)
     * so this is a single `where user_id = ?` with no pivot traversal.
     *
     * Rows with `user_id IS NULL` (historical rows predating the column)
     * are intentionally excluded — they have no claimable owner and must
     * not leak across users.
     *
     * Admin bypass is intentionally NOT in this scope. Callers that need
     * admin visibility (the activity widget in PR D) wrap the query at
     * a higher layer: `->when(! auth()->user()?->isAdmin(), fn ($q) =>
     * $q->ownedBy(auth()->id()))`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Filter to cached files owned by the given playlist.
     *
     * Used by PR B's source-owns-file sharing rule: cross-playlist
     * dedup looks for Completed rows stamped with the source playlist's
     * id (and only honors them when that playlist has
     * `share_cache_across_playlists` enabled).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedByPlaylist(Builder $query, int $playlistId): Builder
    {
        return $query->where('playlist_id', $playlistId);
    }

    /**
     * Filter to cached files currently linked to a live (unshared)
     * DynamicGroup pivot row.
     *
     * Phase 2 / PR E addition. `dropped_at IS NULL` is the live-membership
     * gate — a soft-unshared pivot row (renamed/disabled rule, retention
     * sweep that found the content out of membership) is excluded here.
     * The retention sweep uses this scope as its lookup primitive when
     * walking the pivot from the group side; the dispatcher's
     * pivot-INSERT path doesn't read through it (it writes, doesn't read).
     *
     * Implementation: a whereHas on the pivot relationship. The composite
     * index `(dynamic_group_id, cached_content_file_id)` from migration
     * `2026_09_16_160100_add_user_id_index_to_cached_content_file_dynamic_groups`
     * keeps this lookup indexed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedByDynamicGroup(Builder $query, int $dynamicGroupId): Builder
    {
        return $query->whereHas('dynamicGroups', function (Builder $q) use ($dynamicGroupId): void {
            $q->where('dynamic_groups.id', $dynamicGroupId)
                ->whereNull('cached_content_file_dynamic_groups.dropped_at');
        });
    }

    /**
     * Whether this cached file has a completed file on disk.
     */
    public function hasFilePath(): bool
    {
        return $this->status === CachedContentFileStatus::Completed && ! empty($this->file_path);
    }

    /**
     * Resolve the storage disk this cached file lives on.
     */
    public function resolveStorageDisk(): string
    {
        return $this->disk ?: config('filesystems.default');
    }

    /**
     * Resolve the MIME type from the cached file's extension.
     */
    public function resolveMimeType(): string
    {
        return match (strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            default => 'video/mp2t',
        };
    }
}
