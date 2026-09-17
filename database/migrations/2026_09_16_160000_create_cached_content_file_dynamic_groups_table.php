<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table linking CachedContentFile rows to the DynamicGroups that
     * requested them cached.
     *
     * One file can be referenced by many groups (a single cached movie might
     * land in "Trending" and "Top Action" simultaneously) and one group can
     * own many files (a "Marvel Cinematic Universe" group covers 30+ rows).
     * The composite unique key prevents duplicate membership rows.
     *
     * `dropped_at` (nullable timestamp) is the soft-unshare marker. When a
     * DynamicGroup rule is renamed/disabled (SyncDynamicGroups) or its live
     * membership no longer contains the content (retention sweep), we STAMP
     * `dropped_at` instead of cascade-deleting the pivot row. This lets
     * `CachedContentFile::scopeOwnedByDynamicGroup()` continue to filter on
     * `dropped_at IS NULL` for "live" pivot rows while preserving the history
     * for never_expire retention checks (a soft-unshared row is still
     * evidence the file was once requested by a group; the row's CachedContentFile
     * retains `never_expire=true` independently of pivot state).
     *
     * Both FKs cascade on delete so when a CachedContentFile or DynamicGroup
     * row is HARD-deleted, the pivot cleans itself up. Note: PR E's
     * SyncDynamicGroups soft-unshares instead of deleting the DynamicGroup
     * row first, so in practice the pivot row survives the rename/disable
     * path and only `dropped_at` is stamped.
     */
    public function up(): void
    {
        Schema::create('cached_content_file_dynamic_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cached_content_file_id')
                ->constrained('cached_content_files')
                ->cascadeOnDelete();
            $table->foreignId('dynamic_group_id')
                ->constrained('dynamic_groups')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->timestamp('dropped_at')->nullable();

            $table->unique(['cached_content_file_id', 'dynamic_group_id'], 'ccfdg_file_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_content_file_dynamic_groups');
    }
};
