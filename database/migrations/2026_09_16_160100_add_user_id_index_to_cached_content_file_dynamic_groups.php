<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite index on `(dynamic_group_id, cached_content_file_id)` to
     * support the retention sweep's lookup pattern.
     *
     * `CachedContentRetentionService::evaluateForDynamicGroup()` walks the
     * pivot from the group side to find the set of files linked to the
     * group. The composite unique key `(cached_content_file_id,
     * dynamic_group_id)` from migration 1 already gives an index on
     * `(cached_content_file_id, dynamic_group_id)`; the reverse direction
     * `(dynamic_group_id, cached_content_file_id)` is the retention sweep's
     * primary access path and benefits from its own index.
     *
     * The index covers `dropped_at IS NULL` filtering in the same access
     * path via Postgres partial-index support — but since SQLite (test env)
     * doesn't support partial indexes the same way, the explicit composite
     * index is the portable choice. The query is selective enough on
     * `(dynamic_group_id, dropped_at)` that the planner uses it for the
     * retention sweep across both engines.
     */
    public function up(): void
    {
        Schema::table('cached_content_file_dynamic_groups', function (Blueprint $table): void {
            $table->index(
                ['dynamic_group_id', 'cached_content_file_id'],
                'ccfdg_group_file_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_file_dynamic_groups', function (Blueprint $table): void {
            $table->dropIndex('ccfdg_group_file_idx');
        });
    }
};
