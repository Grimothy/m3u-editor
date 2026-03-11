<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.enable_silence_detection')) {
            $this->migrator->add('general.enable_silence_detection', false);
        }
        if (! $this->migrator->exists('general.silence_threshold_db')) {
            $this->migrator->add('general.silence_threshold_db', -50.0);
        }
        if (! $this->migrator->exists('general.silence_duration')) {
            $this->migrator->add('general.silence_duration', 3.0);
        }
        if (! $this->migrator->exists('general.silence_check_interval')) {
            $this->migrator->add('general.silence_check_interval', 10.0);
        }
        if (! $this->migrator->exists('general.silence_failover_threshold')) {
            $this->migrator->add('general.silence_failover_threshold', 3);
        }
        if (! $this->migrator->exists('general.silence_monitoring_grace_period')) {
            $this->migrator->add('general.silence_monitoring_grace_period', 15.0);
        }
    }
};
