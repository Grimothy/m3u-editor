<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamp the owning user directly on each cached file so the read-side
     * filter (`CachedContentFile::scopeOwnedBy($userId)`) is a single
     * `where user_id = ?` instead of a pivot traversal.
     *
     * Nullable on purpose: PR A lands this column ahead of any writer,
     * so historical rows stay readable as `user_id IS NULL` until PR B's
     * dispatcher starts stamping it. `scopeOwnedBy($userId)` deliberately
     * excludes NULL rows so pre-existing data never leaks across users.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('uuid')
                ->constrained('users')
                ->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
