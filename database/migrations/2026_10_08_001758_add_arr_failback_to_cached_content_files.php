<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arr failback: integrations can opt into having arr-sourced cache
     * requests fall back to a provider download when the arr can't deliver.
     *
     * Schema-safety note (stated for review): plain (non-CONCURRENTLY) DDL
     * is acceptable here — `cached_content_files` holds one row per cached
     * item, is not written by the bulk Process/Sync import job chains, and
     * the new index serves only the small `source='arr'` subset the
     * failback sweep reads.
     */
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table): void {
            $table->boolean('cache_failback')->default(false)->after('cache_cleanup');
        });

        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->string('source')->default('provider')->after('managed_by');
            $table->foreignId('arr_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('arr_requested_at')->nullable();
            $table->timestamp('fallback_dispatched_at')->nullable();
            $table->timestamp('fallback_notified_at')->nullable();
            $table->index(['source', 'arr_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropIndex(['source', 'arr_requested_at']);
            $table->dropConstrainedForeignId('arr_integration_id');
            $table->dropColumn(['source', 'arr_requested_at', 'fallback_dispatched_at', 'fallback_notified_at']);
        });

        Schema::table('arr_integrations', function (Blueprint $table): void {
            $table->dropColumn('cache_failback');
        });
    }
};
