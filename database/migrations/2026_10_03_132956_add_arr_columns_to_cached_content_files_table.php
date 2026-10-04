<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arr-stack columns on cached content files (feat/cache-now-arr).
     *
     * `source` splits rows into provider downloads (local files) and
     * Radarr/Sonarr requests (no file on our disk), so the unique key
     * widens to (cacheable_type, cacheable_id, source). `arr_seasons` is
     * the Sonarr-only requested-seasons list (null = all seasons).
     *
     * Locking (pr-review rule 5): cached_content_files is NOT written by
     * the Process/Sync import chains, but DownloadCachedContentFile
     * updates it continuously. The unique-index swap and the new lookup
     * index take a brief ACCESS EXCLUSIVE lock; the table is small (one
     * row per cached item), so a plain build is acceptable here and is
     * called out in the PR description instead of using CONCURRENTLY,
     * which cannot run inside the migration transaction.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->string('source')->default('provider')->after('managed_by');
            $table->foreignId('arr_integration_id')->nullable()->after('source')->constrained('arr_integrations')->nullOnDelete();
            $table->foreignId('media_request_id')->nullable()->after('arr_integration_id')->constrained('media_requests')->nullOnDelete();
            $table->unsignedBigInteger('arr_library_id')->nullable()->after('media_request_id');
            $table->json('arr_seasons')->nullable()->after('arr_library_id');
            $table->timestamp('fallback_dispatched_at')->nullable()->after('arr_seasons');

            $table->dropUnique('cached_content_files_cacheable_unique');
            $table->unique(['cacheable_type', 'cacheable_id', 'source'], 'cached_content_files_cacheable_source_unique');
            $table->index(['user_id', 'source', 'arr_integration_id'], 'cached_content_files_arr_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->dropIndex('cached_content_files_arr_lookup_index');
            $table->dropUnique('cached_content_files_cacheable_source_unique');
            $table->unique(['cacheable_type', 'cacheable_id'], 'cached_content_files_cacheable_unique');

            $table->dropForeign(['arr_integration_id']);
            $table->dropForeign(['media_request_id']);
            $table->dropColumn(['source', 'arr_integration_id', 'media_request_id', 'arr_library_id', 'arr_seasons', 'fallback_dispatched_at']);
        });
    }
};
