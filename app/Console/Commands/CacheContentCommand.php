<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Services\CachedContentDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator for the standalone per-Channel / per-Episode cache.
 *
 * Walks every Playlist (or the one specified by `--playlist=`) and
 * dispatches `DownloadCachedContentFile` jobs for every enabled Channel
 * and Episode in scope. The actual dedup / sharing / fingerprint logic
 * lives in `CachedContentDispatchService` - this command is just the
 * scheduled-orchestration loop + dry-run reporting.
 *
 * Schedule: `cache:content --dry-run` hourly (see routes/console.php).
 * The hourly dry-run produces a log summary without actually dispatching,
 * so operators get visibility into "what would have been queued" without
 * burning dispatch slots every hour. The non-dry-run path is for ad-hoc
 * `php artisan cache:content --playlist=42` kicks.
 */
class CacheContentCommand extends Command
{
    protected $signature = 'cache:content
                                {--playlist= : Limit to a single playlist ID}
                                {--dry-run : Report what would be dispatched without queuing jobs}';

    protected $description = 'Walk every playlist\'s enabled channels/episodes and dispatch DownloadCachedContentFile jobs';

    public function __construct(protected CachedContentDispatchService $dispatchService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $playlistId = $this->option('playlist');

        $totalDispatched = 0;
        $totalSkipped = 0;
        $totalPlaylists = 0;

        // Playlist::query()->cursor() - never ->all()/->get() (playlist
        // table grows with users, pr-review-standards §1).
        $playlistQuery = Playlist::query()
            ->when($playlistId !== null, fn ($q) => $q->where('id', (int) $playlistId));

        foreach ($playlistQuery->cursor() as $playlist) {
            $totalPlaylists++;
            $playlistDispatched = 0;
            $playlistSkipped = 0;

            // Channel/movie dispatch loop
            foreach (Channel::query()
                ->where('playlist_id', $playlist->id)
                ->where('enabled', true)
                ->cursor() as $channel) {
                if ($isDryRun) {
                    $playlistDispatched++;

                    continue;
                }

                $jobs = $this->dispatchService->dispatchForChannel($channel);
                if ($jobs->isEmpty()) {
                    $playlistSkipped++;
                } else {
                    $playlistDispatched++;
                }
            }

            // Episode dispatch loop
            foreach (Episode::query()
                ->where('playlist_id', $playlist->id)
                ->where('enabled', true)
                ->cursor() as $episode) {
                if ($isDryRun) {
                    $playlistDispatched++;

                    continue;
                }

                $jobs = $this->dispatchService->dispatchForEpisode($episode);
                if ($jobs->isEmpty()) {
                    $playlistSkipped++;
                } else {
                    $playlistDispatched++;
                }
            }

            $totalDispatched += $playlistDispatched;
            $totalSkipped += $playlistSkipped;

            $this->line(sprintf(
                '  Playlist %d (%s): %s=%d, skipped=%d',
                $playlist->id,
                $playlist->name ?? '(no name)',
                $isDryRun ? 'would-dispatch' : 'dispatched',
                $playlistDispatched,
                $playlistSkipped,
            ));

            if ($isDryRun) {
                Log::info("cache:content --dry-run playlist={$playlist->id}: would-dispatch={$playlistDispatched}");
            }
        }

        $this->info(sprintf(
            '%s %d playlists: %s=%d, skipped=%d',
            $isDryRun ? '[DRY RUN]' : 'Walked',
            $totalPlaylists,
            $isDryRun ? 'would-dispatch' : 'dispatched',
            $totalDispatched,
            $totalSkipped,
        ));

        return self::SUCCESS;
    }
}
