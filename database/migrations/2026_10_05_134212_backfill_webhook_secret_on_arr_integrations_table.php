<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Integrations created before the webhook_secret column existed have no
     * secret, so they have no webhook route. Give each one its own secret.
     */
    public function up(): void
    {
        DB::table('arr_integrations')
            ->whereNull('webhook_secret')
            ->select('id')
            ->chunkById(100, function ($integrations): void {
                foreach ($integrations as $integration) {
                    DB::table('arr_integrations')
                        ->where('id', $integration->id)
                        ->update(['webhook_secret' => (string) Str::uuid()]);
                }
            });
    }

    /**
     * Backfilled secrets can't be told apart from generated ones, and may
     * already be configured in Radarr/Sonarr, so they are kept.
     */
    public function down(): void
    {
        //
    }
};
