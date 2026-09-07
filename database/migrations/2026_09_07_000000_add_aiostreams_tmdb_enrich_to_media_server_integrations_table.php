<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            // When on (default), the AIOStreams meta proxy enriches the Stremio
            // meta object with TMDB data (rich cast_list, clearlogo, season
            // posters/overviews) so the m3u-tv detail screens reach parity with
            // Xtream VOD/Series. Only has an effect when a TMDB API key is set.
            $table->boolean('aiostreams_tmdb_enrich')->default(true)->after('aiostreams_meta_id_prefixes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn('aiostreams_tmdb_enrich');
        });
    }
};
