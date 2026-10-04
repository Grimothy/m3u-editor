<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional link from an arr integration to the media server whose
     * library contains its root folder. When set, arr imports (and
     * removals) trigger a library refresh + rescan on that server so new
     * titles become playable sooner.
     */
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->foreignId('media_server_integration_id')->nullable()->constrained('media_server_integrations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropForeign(['media_server_integration_id']);
            $table->dropColumn('media_server_integration_id');
        });
    }
};
