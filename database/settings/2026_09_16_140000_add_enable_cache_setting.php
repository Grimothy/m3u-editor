<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.enable_cache')) {
            $this->migrator->add('general.enable_cache', false);
        }
    }
};
