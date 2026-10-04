<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentSource;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\User;
use App\Services\Arr\ArrService;
use App\Services\CachedContentDispatchService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MonitorArrSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $integrationId,
        public int $contentId,
        public string $contentTitle,
        public int $userId,
        public ?int $cachedContentFileId = null,
    ) {}

    public function handle(): void
    {
        $integration = ArrIntegration::find($this->integrationId);
        $user = User::find($this->userId);

        if (! $integration || ! $user || (! $integration->isRadarr() && $this->cachedContentFileId === null)) {
            return;
        }

        if (! $integration->isRadarr()) {
            $this->checkTrackedSonarrRow($integration);

            return;
        }

        $service = ArrService::make($integration);
        $releases = $service->fetchReleases($this->contentId);

        $allRejected = empty($releases) || collect($releases)->every(fn ($r) => ! ($r['approved'] ?? false));

        if ($allRejected) {
            if ($this->cachedContentFileId !== null) {
                $this->failCachedContentFile($integration);
            }

            Notification::make()
                ->warning()
                ->title(__('No Approved Releases'))
                ->body(__('All releases found for ":title" are rejected by your quality profile or indexer settings. Try adjusting your settings in Radarr, or use Interactive Search to pick a release manually.', [
                    'title' => $this->contentTitle,
                ]))
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }

    /**
     * Season-scoped release check for a tracked Sonarr row: Sonarr's
     * `/release?seriesId=X` without a seasonNumber returns the general
     * RSS feed, so each tracked season is searched explicitly. A missing
     * row or one covering every season (arr_seasons null) is left alone —
     * a whole-series interactive search is too expensive, and queue
     * polling plus ManualInteractionRequired still catch failures.
     */
    private function checkTrackedSonarrRow(ArrIntegration $integration): void
    {
        $row = CachedContentFile::find($this->cachedContentFileId);

        if (! $row || $row->arr_seasons === null) {
            return;
        }

        $service = ArrService::make($integration);
        $releases = [];

        foreach ($row->arr_seasons as $season) {
            $releases = array_merge($releases, $service->fetchSeasonReleases($this->contentId, (int) $season));
        }

        $allRejected = empty($releases) || collect($releases)->every(fn ($r) => ! ($r['approved'] ?? false));

        if ($allRejected) {
            // No "No Approved Releases" notification here: the row's
            // last_error_message already tells the user what happened.
            $this->failCachedContentFile($integration);
        }
    }

    /**
     * Every release for the tracked cached download was rejected: fail the
     * row and queue a provider copy instead — but only while arr still
     * isn't downloading it. Once arr's own search grabs a release, an
     * interactive search often reports every release as "rejected"
     * (already queued, cutoff met), so the queue decides.
     */
    private function failCachedContentFile(ArrIntegration $integration): void
    {
        $row = CachedContentFile::find($this->cachedContentFileId);

        if (! $row || $row->status !== CachedContentFileStatus::Requested) {
            return;
        }

        $arrHasIt = collect(ArrService::make($integration)->fetchQueue())->contains(
            fn (array $q): bool => (string) ($q['externalId'] ?? '') === (string) ($row->source === CachedContentSource::Radarr ? $row->tmdb_id : $row->tvdb_id)
        );

        if ($arrHasIt) {
            return;
        }

        $row->forceFill([
            'status' => CachedContentFileStatus::Failed,
            'last_failed_at' => now(),
            'failure_count' => $row->failure_count + 1,
            'last_error_message' => __('No release passes your :arr quality profile. Downloading from the provider instead.', [
                'arr' => $row->source->getLabel(),
            ]),
        ])->save();

        app(CachedContentDispatchService::class)->fallbackToProvider($row);
    }

    public function failed(\Throwable $e): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('Search Check Failed'))
            ->body(__('Could not verify release availability for ":title". Check Radarr directly.', [
                'title' => $this->contentTitle,
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
