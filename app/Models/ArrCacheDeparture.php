<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Radarr movie that dynamic-group auto-cache added and tagged for
 * cleanup, which has left every group since `left_at`. See
 * ArrCacheCleanupService.
 */
class ArrCacheDeparture extends Model
{
    use HasFactory;

    protected $fillable = [
        'arr_integration_id',
        'arr_movie_id',
        'tmdb_id',
        'left_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arr_movie_id' => 'integer',
            'tmdb_id' => 'integer',
            'left_at' => 'datetime',
        ];
    }

    public function arrIntegration(): BelongsTo
    {
        return $this->belongsTo(ArrIntegration::class);
    }
}
