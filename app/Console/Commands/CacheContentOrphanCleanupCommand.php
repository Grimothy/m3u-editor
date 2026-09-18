<?php

namespace App\Console\Commands;

use App\Models\CachedContentFile;
use Illuminate\Console\Command;

/**
 * Deletes `CachedContentFile` rows where `file_path IS NULL` AND the row
 * is older than 7 days - the "abandoned download" case where the job
 * never wrote a file to disk (Pending/Downloading that was never picked
 * up, or Failed without ever having a file). These are dead rows that
 * the retention service won't catch (they have no `file_path` to clean
 * up, but the row itself is also no longer in any live scope).
 *
 * Scheduled daily at 03:30 (after the 03:00 retention pass) by
 * routes/console.php. Uses chunkById so a large cleanup doesn't lock the
 * table - matches the rest of the cleanup path's memory / lock profile.
 */
class CacheContentOrphanCleanupCommand extends Command
{
    protected $signature = 'cache:cleanup-orphans
                                {--days=7 : Delete orphan rows older than this many days}
                                {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete abandoned cached_content_files rows (no file on disk) older than N days';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));

        $cutoff = now()->subDays($days);

        $query = CachedContentFile::query()
            ->whereNull('file_path')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();
        $this->line(sprintf(
            '%s %d orphan cached_content_files rows older than %d day(s).',
            $isDryRun ? '[DRY RUN] Identified' : 'Identified',
            $count,
            $days,
        ));

        if ($isDryRun || $count === 0) {
            return self::SUCCESS;
        }

        // chunkById - mutate-safe, bounded memory, no table lock.
        $deleted = 0;
        $query->chunkById(500, function ($rows) use (&$deleted): void {
            foreach ($rows as $row) {
                $row->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} orphan cached_content_files rows.");

        return self::SUCCESS;
    }
}
