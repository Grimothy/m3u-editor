<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('dvr_settings', 'transcode_recordings')) {
                $table->boolean('transcode_recordings')->default(false)->after('dvr_output_format');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            if (Schema::hasColumn('dvr_settings', 'transcode_recordings')) {
                $table->dropColumn('transcode_recordings');
            }
        });
    }
};
