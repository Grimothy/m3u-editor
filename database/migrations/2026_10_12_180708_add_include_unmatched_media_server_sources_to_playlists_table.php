<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A boolean with a constant default is a metadata-only change on
     * Postgres 11+, so the brief ACCESS EXCLUSIVE lock this takes on `playlists`
     * does not rewrite the table the Process/Sync job chains write to.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('include_unmatched_media_server_sources')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('include_unmatched_media_server_sources');
        });
    }
};
