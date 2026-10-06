<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Radarr movie dynamic-group auto-cache added for a rule with "Remove
 * from Radarr after leaving" on. ArrCacheCleanupService removes it once it
 * has been out of every group since `left_at` for the keep days. Without a
 * row, a movie is never removed.
 */
class ArrCacheMovie extends Model
{
    use HasFactory;

    protected $fillable = [
        'arr_integration_id',
        'tmdb_id',
        'arr_movie_id',
        'left_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
            'arr_movie_id' => 'integer',
            'left_at' => 'datetime',
        ];
    }

    /**
     * Stop tracking a movie so cleanup never removes it, like
     * CachedContentFile::keep().
     */
    public static function keep(int $arrIntegrationId, int $tmdbId): void
    {
        static::query()
            ->where('arr_integration_id', $arrIntegrationId)
            ->where('tmdb_id', $tmdbId)
            ->delete();
    }
}
