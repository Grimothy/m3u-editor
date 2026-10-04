<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.cache_primary_method')) {
            $this->migrator->add('general.cache_primary_method', 'provider');
        }

        if (! $this->migrator->exists('general.cache_radarr_integration_id')) {
            $this->migrator->add('general.cache_radarr_integration_id', null);
        }

        if (! $this->migrator->exists('general.cache_sonarr_integration_id')) {
            $this->migrator->add('general.cache_sonarr_integration_id', null);
        }
    }
};
