<?php

use App\Jobs\DvrRetentionCleanup;
use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

// Check for updates
Schedule::command('app:update-check')
    ->hourly();

// Refresh playlists
Schedule::command('app:refresh-playlist')
    ->everyMinute()
    ->withoutOverlapping();

// Refresh TMDB dynamic groups (trending/popular/etc.) independent of playlist syncs
Schedule::command('app:refresh-dynamic-groups')
    ->dailyAt('04:15')
    ->withoutOverlapping();

// Refresh media server integrations
Schedule::command('app:refresh-media-server-integrations')
    ->everyMinute()
    ->withoutOverlapping();

// AIOStreams-backed VOD/episodes that have never resolved a stream yet
// (unaired-at-add-time episodes now past their air date, or entries whose
// initial resolution attempts all came up empty): resolve a small capped
// batch. Deliberately never touches already-'resolved' entries — re-checking
// a working link is exactly the "poking" pattern some debrid backends
// (TorBox) ban accounts for. Ordinary link rot on resolved content is
// handled on-demand instead, via M3uProxyService::resolveFailoverUrl's
// exhaustion hook at actual playback-failure time.
Schedule::command('app:resolve-pending-aiostreams-candidates')
    ->hourly()
    ->withoutOverlapping();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyMinute()
    ->withoutOverlapping();

// EPG cache health
Schedule::command('app:epg-cache-health-check')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Check backup
Schedule::command('app:run-scheduled-backups')
    ->everyTwoMinutes()
    ->withoutOverlapping();

// Cleanup logos
Schedule::command('app:logo-cleanup --force')
    ->daily()
    ->withoutOverlapping();

// Prune failed jobs
Schedule::command('queue:prune-failed --hours=48')
    ->daily();

// Cache content dispatch - dry-run hourly so operators see what WOULD be
// queued without burning dispatch slots. The non-dry-run path is for ad-hoc
// `php artisan cache:content --playlist=N` kicks (PR B).
Schedule::command('cache:content --dry-run')
    ->hourly()
    ->withoutOverlapping();

// Cache retention cleanup - daily at 03:00. Identifies + deletes cached files
// whose fingerprints are no longer in any live (user, playlist) scope.
Schedule::command('cache:cleanup')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Cache orphan cleanup - daily at 03:30, after retention. Targets abandoned
// rows (file_path IS NULL) older than 7 days that retention deliberately
// skips (no file on disk to clean, but the row itself is dead).
Schedule::command('cache:cleanup-orphans')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Prune old notifications
Schedule::command('app:prune-old-notifications --days=7')
    ->daily();

// Prune old model history (also handles DeviceAuthorization via its Prunable trait)
Schedule::command('model:prune')->daily();

// Ensure m3u-proxy webhook is registered (handles proxy restarts, delayed startup, etc.)
Schedule::command('m3u-proxy:register-webhook')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Refresh provider profile info (every 15 minutes)
Schedule::command('app:refresh-playlist-profiles')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Run scheduled plugin invocations
Schedule::command('plugins:run-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

// Mark abandoned plugin runs stale so operators can resume them.
Schedule::command('plugins:recover-stale-runs')
    ->everyMinute()
    ->withoutOverlapping();

// Check for plugin updates from GitHub repositories
if (config('plugins.update_check.enabled', true)) {
    $updateFrequencyHours = max(1, (int) config('plugins.update_check.frequency_hours', 4));
    Schedule::command('plugins:check-updates')
        ->cron("0 */{$updateFrequencyHours} * * *")
        ->withoutOverlapping();
}

// Note: HLS broadcast files are managed by m3u-proxy service
if (config('proxy.proxy_integration_enabled', true)) {
    // Regenerate network schedules (hourly check, regenerates when needed)
    Schedule::command('networks:regenerate-schedules')
        ->hourly()
        ->withoutOverlapping();

    if (config('dvr.dvr_enabled', true)) {
        // DVR scheduler tick — every minute, trigger and stop scheduled recordings.
        // Runs as a plain command (like app:refresh-playlist/app:refresh-epg) rather
        // than a queued job, so an idle tick doesn't show up in Horizon/queue monitoring.
        Schedule::command('app:dvr-scheduler-tick')->everyMinute()->withoutOverlapping();

        // DVR retention cleanup — run hourly to enforce keepLast, age, and quota policies
        Schedule::job(new DvrRetentionCleanup)->hourly()->withoutOverlapping();
    }
}
