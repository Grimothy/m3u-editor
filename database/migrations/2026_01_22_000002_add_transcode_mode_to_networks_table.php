<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the boolean `transcode_on_server` with a string `transcode_mode`
     * that supports three modes:
     * - 'direct': Direct stream, no transcoding
     * - 'server': Transcode on media server (Jellyfin/Emby)
     * - 'local': Transcode locally with FFmpeg
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // Add the new transcode_mode column
            $table->string('transcode_mode', 20)->default('direct')->after('hls_list_size');
        });

        // Migrate existing data: transcode_on_server = true means 'direct' (confusing, I know)
        // The old logic was: true = "let server transcode" but actually used static=true (direct)
        // So we'll map: transcode_on_server = true → 'direct', false → 'local'
        \DB::table('networks')->where('transcode_on_server', true)->update(['transcode_mode' => 'direct']);
        \DB::table('networks')->where('transcode_on_server', false)->update(['transcode_mode' => 'local']);

        // Drop the old column
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('transcode_on_server');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // Add back the old boolean column
            $table->boolean('transcode_on_server')->default(true)->after('hls_list_size');
        });

        // Migrate data back
        \DB::table('networks')->where('transcode_mode', 'direct')->update(['transcode_on_server' => true]);
        \DB::table('networks')->where('transcode_mode', 'server')->update(['transcode_on_server' => true]);
        \DB::table('networks')->where('transcode_mode', 'local')->update(['transcode_on_server' => false]);

        // Drop the new column
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('transcode_mode');
        });
    }
};
