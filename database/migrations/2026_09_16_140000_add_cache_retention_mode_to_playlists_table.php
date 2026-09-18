<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the per-playlist cache-retention override to the `playlists` table.
     *
     * The form field lives on `PlaylistResource::getForm()`'s "Cache" section
     * (next to `share_cache_across_playlists`). When null, the global default
     * `general.cache_retention_mode` (managed in Settings > Integrations > Cache)
     * wins. Values: `never-expire`, `time-based`, `manual`. The per-row
     * `cached_content_files.never_expire` boolean still takes precedence when
     * set, regardless of this column's value.
     *
     * Schema notes:
     * - Nullable on purpose: existing playlists fall back to the global default
     *   without requiring a backfill.
     * - Plain ADD COLUMN with no default is metadata-only in Postgres 11+, so
     *   this does not lock the channels/playlist sync jobs that write to the
     *   table during deploy.
     * - No index: the column is read only during playlist read/edit, never
     *   scanned. Adding an index would cost write throughput for no query
     *   benefit.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->string('cache_retention_mode')
                ->nullable()
                ->after('share_cache_across_playlists');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn('cache_retention_mode');
        });
    }
};
