<?php

namespace App\Services;

use App\Livewire\ArrQueueMonitor;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\CustomPlaylist;
use App\Models\MediaRequest;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Notifications\Notification as AppNotification;
use App\Services\Arr\ArrService;
use App\Services\Arr\Contracts\ArrIntegrationInterface;
use App\Services\Arr\SonarrService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContentRequestService
{
    /** @return Collection<int, ArrIntegration> */
    public function integrations(Playlist|CustomPlaylist|MergedPlaylist $playlist): Collection
    {
        return ArrIntegration::query()
            ->where('user_id', $playlist->user_id)
            ->enabled()
            ->guestEnabled()
            ->orderBy('name')
            ->get();
    }

    /** @return array<int, string> */
    public function contentTypes(Playlist|CustomPlaylist|MergedPlaylist $playlist): array
    {
        $integrationTypes = $this->integrations($playlist)
            ->pluck('type')
            ->unique();

        return collect([
            'movie' => 'radarr',
            'series' => 'sonarr',
        ])->filter(fn (string $integrationType): bool => $integrationTypes->contains($integrationType))
            ->keys()
            ->all();
    }

    /**
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     searched_providers: int,
     *     unavailable_providers: int
     * }
     */
    public function search(Playlist|CustomPlaylist|MergedPlaylist $playlist, string $term, ?string $type = null): array
    {
        $results = [];
        $searchedProviders = 0;
        $unavailableProviders = 0;

        foreach ($this->integrations($playlist) as $integration) {
            $mediaType = $integration->isRadarr() ? 'movie' : 'series';
            if ($type !== null && $type !== $mediaType) {
                continue;
            }

            $searchedProviders++;

            try {
                $service = ArrService::make($integration);
                $items = $service->search($term);
            } catch (Throwable $throwable) {
                $unavailableProviders++;
                Log::warning('Content request search failed', [
                    'integration_id' => $integration->id,
                    'error' => $throwable->getMessage(),
                ]);

                continue;
            }

            foreach ($items as $item) {
                $externalId = $integration->isRadarr()
                    ? ($item['tmdbId'] ?? null)
                    : ($item['tvdbId'] ?? null);

                if (! $externalId) {
                    continue;
                }

                $resultKey = $mediaType.':'.$externalId;

                if (isset($results[$resultKey])) {
                    continue;
                }

                $results[$resultKey] = [
                    'type' => $mediaType,
                    'external_id' => (string) $externalId,
                    'integration_id' => $integration->id,
                    'integration_name' => $integration->name,
                    'title' => $item['title'] ?? 'Unknown',
                    'year' => $item['year'] ?? null,
                    'overview' => $item['overview'] ?? null,
                    'poster' => $item['poster'] ?? null,
                    'fanart' => $item['fanart'] ?? null,
                    'genres' => $item['genres'] ?? [],
                    'rating' => $item['rating'] ?? null,
                    'runtime' => $item['runtime'] ?? null,
                    'certification' => $item['certification'] ?? null,
                    'seasons' => $mediaType === 'series'
                        ? $this->resolveSeasons($service, $item)
                        : [],
                    'already_available' => (bool) ($item['existsInLibrary'] ?? false),
                ];
            }
        }

        return [
            'results' => array_values($results),
            'searched_providers' => $searchedProviders,
            'unavailable_providers' => $unavailableProviders,
        ];
    }

    /**
     * Build the per-season availability list for a series lookup result.
     *
     * Sonarr's /series/lookup response can echo series-level totals into every
     * season's `statistics.episodeFileCount`, falsely marking all seasons as
     * downloaded. When the series is already in the library, prefer authoritative
     * per-episode status from /episode instead — the same source used by the
     * admin detail panel (see ArrSearch::loadDetailData()).
     *
     * @param  array<string, mixed>  $item
     * @return array<int, array{season_number: int, episode_count: ?int, episode_file_count: ?int, has_file: bool}>
     */
    private function resolveSeasons(ArrIntegrationInterface $service, array $item): array
    {
        $episodeStatus = [];
        $libraryId = isset($item['libraryId']) ? (int) $item['libraryId'] : null;

        if ($libraryId && $service->supportsEpisodes()) {
            try {
                /** @var SonarrService $service */
                /** @var array{status: array<int, array<int, bool>>} $episodeData */
                $episodeData = $service->fetchEpisodeData($libraryId);
                $episodeStatus = $episodeData['status'];
            } catch (Throwable $throwable) {
                Log::warning('Failed to fetch authoritative episode status', [
                    'library_id' => $libraryId,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        return collect($item['seasons'] ?? [])
            ->filter(fn (array $season): bool => is_numeric($season['seasonNumber'] ?? null) && (int) $season['seasonNumber'] >= 0)
            ->map(function (array $season) use ($episodeStatus): array {
                $seasonNumber = (int) $season['seasonNumber'];
                $episodes = $episodeStatus[$seasonNumber] ?? null;

                if ($episodes !== null) {
                    $fileCount = count(array_filter($episodes));

                    return [
                        'season_number' => $seasonNumber,
                        'episode_count' => count($episodes),
                        'episode_file_count' => $fileCount,
                        'has_file' => $fileCount > 0,
                    ];
                }

                return [
                    'season_number' => $seasonNumber,
                    'episode_count' => $season['statistics']['episodeCount'] ?? null,
                    'episode_file_count' => $season['statistics']['episodeFileCount'] ?? null,
                    'has_file' => (int) ($season['statistics']['episodeFileCount'] ?? 0) > 0,
                ];
            })
            ->unique('season_number')
            ->sortBy('season_number')
            ->values()
            ->all();
    }

    /**
     * @return array{ok: bool, code?: string, error?: string, status?: string, request?: array<string, mixed>}
     */
    public function submit(
        Playlist|CustomPlaylist|MergedPlaylist $playlist,
        PlaylistAuth $playlistAuth,
        string $type,
        int $integrationId,
        int $externalId,
        ?array $selectedSeasons = null,
    ): array {
        $integration = $this->integrations($playlist)->firstWhere('id', $integrationId);
        $expectedType = $integration?->isRadarr() ? 'movie' : 'series';

        if (! $integration || $type !== $expectedType) {
            return ['ok' => false, 'code' => 'invalid_integration', 'error' => 'The selected integration is not available.'];
        }

        $alreadyRequested = MediaRequest::query()
            ->where('playlist_auth_id', $playlistAuth->id)
            ->where('arr_integration_id', $integration->id)
            ->where('external_id', (string) $externalId)
            ->where('request_type', $type)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($alreadyRequested) {
            return ['ok' => false, 'code' => 'already_requested', 'error' => 'This title has already been requested.'];
        }

        $service = ArrService::make($integration);

        $lookup = $this->lookupForAdd($integration, $service, $type, $externalId, $selectedSeasons);

        if (! $lookup['ok']) {
            return ['ok' => false, 'code' => $lookup['code'], 'error' => $lookup['error']];
        }

        $payload = $lookup['payload'];
        $title = $lookup['title'];

        if (! $playlistAuth->auto_approve_requests) {
            $mediaRequest = $this->createMediaRequest($playlistAuth, $integration, $type, $externalId, $title, $payload, 'pending');
            if (! $mediaRequest) {
                return ['ok' => false, 'code' => 'already_requested', 'error' => 'This title has already been requested.'];
            }

            return [
                'ok' => true,
                'status' => 'pending_approval',
                'request' => $this->formatRequest($mediaRequest),
            ];
        }

        // Claim the request atomically (via the partial unique index on active
        // statuses) before calling the provider. Concurrent duplicate submissions
        // then race on this DB insert instead of both reaching $service->add(),
        // which would otherwise double-add the same title on the provider side.
        $mediaRequest = $this->createMediaRequest(
            $playlistAuth,
            $integration,
            $type,
            $externalId,
            $title,
            $payload,
            'approved',
            reviewedAt: now(),
        );
        if (! $mediaRequest) {
            return ['ok' => false, 'code' => 'already_requested', 'error' => 'This title has already been requested.'];
        }

        $result = $service->add($payload);
        if (! ($result['ok'] ?? false)) {
            $mediaRequest->delete();

            return [
                'ok' => false,
                'code' => 'submission_failed',
                'error' => 'The request provider could not accept this title.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'approved',
            'request' => $this->formatRequest($mediaRequest),
        ];
    }

    /**
     * Resolve an external ID into an arr add payload: existence check, provider lookup
     * and (for series) season filtering. Shared by the guest flow and the cache.
     *
     * @param  array<int, int>|null  $selectedSeasons
     * @return array{ok: true, payload: array<string, mixed>, title: string}|array{ok: false, code: string, error: string, library_id?: int, has_file?: bool}
     */
    private function lookupForAdd(ArrIntegration $integration, ArrIntegrationInterface $service, string $type, int $externalId, ?array $selectedSeasons): array
    {
        try {
            $exists = $service->checkExists($externalId);
            if ($exists['exists']) {
                return [
                    'ok' => false,
                    'code' => 'already_available',
                    'error' => 'This title is already available.',
                    'library_id' => (int) $exists['id'],
                    'has_file' => (bool) ($exists['has_file'] ?? false),
                ];
            }

            $lookupTerm = ($type === 'movie' ? 'tmdb:' : 'tvdb:').$externalId;
            $externalKey = $type === 'movie' ? 'tmdbId' : 'tvdbId';
            $item = collect($service->search($lookupTerm))->first(
                fn (array $result): bool => (int) ($result[$externalKey] ?? 0) === $externalId,
            );
        } catch (Throwable $throwable) {
            Log::warning('Content request lookup failed', [
                'integration_id' => $integration->id,
                'error' => $throwable->getMessage(),
            ]);

            return ['ok' => false, 'code' => 'provider_unavailable', 'error' => 'The request provider is temporarily unavailable.'];
        }

        if (! $item) {
            return ['ok' => false, 'code' => 'not_found', 'error' => 'The requested title was not found.'];
        }

        $payload = [
            $externalKey => $externalId,
            'title' => $item['title'] ?? null,
            'titleSlug' => $item['titleSlug'] ?? null,
            'images' => $item['images'] ?? [],
            'qualityProfileId' => $integration->quality_profile_id,
            'rootFolderPath' => $integration->root_folder_path,
            $type === 'movie' ? 'searchForMovie' : 'searchForMissingEpisodes' => true,
        ];

        if ($type === 'series' && $selectedSeasons !== null) {
            $availableSeasons = collect($item['seasons'] ?? [])
                ->pluck('seasonNumber')
                ->map(fn (mixed $season): int => (int) $season)
                ->all();

            if (array_diff($selectedSeasons, $availableSeasons) !== []) {
                return ['ok' => false, 'code' => 'invalid_seasons', 'error' => 'One or more selected seasons are unavailable.'];
            }

            $payload['seasons'] = collect($item['seasons'])
                ->map(fn (array $season): array => [
                    'seasonNumber' => (int) $season['seasonNumber'],
                    'monitored' => in_array((int) $season['seasonNumber'], $selectedSeasons, true),
                ])
                ->values()
                ->all();
        }

        return ['ok' => true, 'payload' => $payload, 'title' => $item['title'] ?? 'Unknown'];
    }

    /** @param array<string, mixed> $payload */
    private function createMediaRequest(
        PlaylistAuth $playlistAuth,
        ArrIntegration $integration,
        string $type,
        int $externalId,
        string $title,
        array $payload,
        string $status,
        ?Carbon $reviewedAt = null,
    ): ?MediaRequest {
        try {
            // Wrapped in its own transaction so a unique-constraint failure only
            // rolls back to a savepoint, not the whole ambient transaction - on
            // Postgres, an uncaught-at-the-connection-level failed statement
            // otherwise poisons every later query until an explicit rollback.
            return DB::transaction(fn () => MediaRequest::create([
                'playlist_auth_id' => $playlistAuth->id,
                'arr_integration_id' => $integration->id,
                'title' => $title,
                'external_id' => (string) $externalId,
                'request_type' => $type,
                'payload' => $payload,
                'status' => $status,
                'requested_at' => now(),
                'reviewed_at' => $reviewedAt,
            ]));
        } catch (QueryException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'UNIQUE constraint failed')
                || str_contains($message, 'SQLSTATE[23505')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The enabled arr integrations of one type owned by the user (cache-side; no guest gating).
     *
     * @param  'radarr'|'sonarr'  $arrType
     * @return Collection<int, ArrIntegration>
     */
    public function cacheIntegrations(int $userId, string $arrType): Collection
    {
        return ArrIntegration::query()->where('user_id', $userId)->where('type', $arrType)->enabled()->orderBy('name')->get();
    }

    /**
     * Send a title to Radarr/Sonarr on behalf of the cache (no PlaylistAuth, auto-approved).
     *
     * @param  'movie'|'series'  $type
     * @param  array<int, int>|null  $selectedSeasons  null = all seasons
     * @return array{ok: bool, code?: string, error?: string, media_request?: MediaRequest, library_id?: int, has_file?: bool}
     */
    public function requestForCache(ArrIntegration $integration, string $type, int $externalId, ?array $selectedSeasons = null): array
    {
        $service = ArrService::make($integration);

        $lookup = $this->lookupForAdd($integration, $service, $type, $externalId, $selectedSeasons);
        if (! $lookup['ok']) {
            return $lookup;
        }

        $payload = $lookup['payload'];
        $result = $service->add($payload);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'submission_failed',
                'error' => $result['error'] ?? __('The request provider could not accept this title.'),
            ];
        }

        $mediaRequest = MediaRequest::create([
            'playlist_auth_id' => null,
            'arr_integration_id' => $integration->id,
            'title' => $lookup['title'],
            'external_id' => (string) $externalId,
            'request_type' => $type,
            'payload' => $payload,
            'status' => 'approved',
            'requested_at' => now(),
            'reviewed_at' => now(),
        ]);

        return ['ok' => true, 'media_request' => $mediaRequest, 'library_id' => (int) ($result['data']['id'] ?? 0)];
    }

    /** @return array{requests: array<int, array<string, mixed>>, total: int} */
    public function history(PlaylistAuth $playlistAuth, int $page, int $perPage): array
    {
        $query = MediaRequest::query()
            ->where('playlist_auth_id', $playlistAuth->id)
            ->with('arrIntegration:id,name');

        $total = (clone $query)->count();
        $requests = $query->orderByDesc('requested_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (MediaRequest $request): array => $this->formatRequest($request))
            ->all();

        return ['requests' => $requests, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function status(PlaylistAuth $playlistAuth, int $requestId): ?array
    {
        $mediaRequest = MediaRequest::query()
            ->where('id', $requestId)
            ->where('playlist_auth_id', $playlistAuth->id)
            ->with('arrIntegration')
            ->first();

        if (! $mediaRequest) {
            return null;
        }

        $formatted = $this->formatRequest($mediaRequest);
        $integration = $mediaRequest->arrIntegration;
        if ($mediaRequest->status !== 'approved'
            || ! $integration?->enabled
            || ! $integration->guest_enabled) {
            return $formatted;
        }

        $progress = $this->resolveProgress($mediaRequest);

        if ($progress === null) {
            return $formatted;
        }

        $status = $progress['status'];

        if (in_array($status, ['completed', 'imported'], true)) {
            $status = 'completed';
            if ($progress['can_persist_completed']) {
                $this->completeRequest($mediaRequest);
                $formatted = $this->formatRequest($mediaRequest);
            }
        }

        return array_merge($formatted, [
            'status' => $status,
            'progress' => $progress['progress'],
            'quality' => $progress['quality'],
            'protocol' => $progress['protocol'],
            'size' => $progress['size'],
            'time_left' => $progress['time_left'],
        ]);
    }

    /**
     * Live progress for an approved request, from the arr queue or the latest webhook event. Null when nothing is known yet.
     *
     * @param  array<int, array<string, mixed>>|null  $queue  pre-fetched fetchQueue() result (batch callers); null = fetch here
     * @return array{status: string, tracked_state: ?string, progress: int, size: int, size_left: int, quality: ?string, protocol: ?string, time_left: ?string, can_persist_completed: bool}|null
     */
    public function resolveProgress(MediaRequest $mediaRequest, ?array $queue = null, bool $aggregateEpisodes = false): ?array
    {
        $integration = $mediaRequest->arrIntegration;

        try {
            $records = $queue ?? ArrService::make($integration)->fetchQueue();
        } catch (Throwable $throwable) {
            Log::warning('Content request status lookup failed', [
                'integration_id' => $integration->id,
                'error' => $throwable->getMessage(),
            ]);

            $records = [];
        }

        $matches = collect($records)->filter(function (array $item) use ($mediaRequest): bool {
            if ($mediaRequest->external_id !== null) {
                if (isset($item['externalId'])) {
                    return (string) $item['externalId'] === $mediaRequest->external_id;
                }

                return false;
            }

            return mb_strtolower(trim($item['title']))
                === mb_strtolower(trim($mediaRequest->title));
        })->values();

        $canPersistCompleted = false;

        if ($matches->isNotEmpty()) {
            $queueItem = $matches->first();

            if ($aggregateEpisodes && $mediaRequest->external_id !== null && $matches->count() > 1) {
                $size = (int) $matches->sum(fn (array $item): int => (int) ($item['size'] ?? 0));
                $sizeLeft = (int) $matches->sum(fn (array $item): int => (int) ($item['sizeLeft'] ?? 0));
                $progress = $size > 0 ? (int) round((1 - $sizeLeft / $size) * 100) : 0;
                $queueItem = $matches->first(fn (array $item): bool => in_array($item['status'], ['failed', 'warning'], true)
                    || in_array($item['trackedDownloadState'] ?? null, ['importBlocked', 'importFailed', 'failed', 'failedPending'], true))
                    ?? $queueItem;
                $canPersistCompleted = (string) ($queueItem['externalId'] ?? '') === $mediaRequest->external_id;
            } else {
                $progress = $queueItem['progress'];
                $size = (int) ($queueItem['size'] ?? 0);
                $sizeLeft = (int) ($queueItem['sizeLeft'] ?? 0);
                $canPersistCompleted = $mediaRequest->external_id === null
                    || (string) ($queueItem['externalId'] ?? '') === $mediaRequest->external_id;
            }

            return [
                'status' => ArrQueueMonitor::resolveStatus($queueItem['status'], $queueItem['trackedDownloadState'] ?? null),
                'tracked_state' => $queueItem['trackedDownloadState'] ?? null,
                'progress' => $progress,
                'size' => $size,
                'size_left' => $sizeLeft,
                'quality' => $queueItem['quality'] ?? null,
                'protocol' => $queueItem['protocol'] ?? null,
                'time_left' => $queueItem['timeLeft'] ?? null,
                'can_persist_completed' => $canPersistCompleted,
            ];
        }

        $eventQuery = ArrQueueEvent::query()
            ->where('arr_integration_id', $integration->id)
            ->where('last_event_at', '>=', $mediaRequest->requested_at);

        if ($mediaRequest->external_id !== null) {
            $eventQuery->where('external_id', $mediaRequest->external_id);
        } else {
            $eventQuery->where('title', $mediaRequest->title);
        }

        $event = $eventQuery->orderByDesc('last_event_at')->first();
        if (! $event) {
            return null;
        }

        $size = $event->size;
        $progress = $event->progress;

        return [
            'status' => $event->status,
            'tracked_state' => null,
            'progress' => $progress,
            'size' => $size,
            'size_left' => (int) round($size * (100 - $progress) / 100),
            'quality' => $event->quality,
            'protocol' => null,
            'time_left' => null,
            'can_persist_completed' => true,
        ];
    }

    /** @return array{ok: bool, code?: string} */
    public function dismiss(PlaylistAuth $playlistAuth, int $requestId): array
    {
        $mediaRequest = MediaRequest::query()
            ->where('id', $requestId)
            ->where('playlist_auth_id', $playlistAuth->id)
            ->first();

        if (! $mediaRequest) {
            return ['ok' => false, 'code' => 'request_not_found'];
        }

        if (! in_array($mediaRequest->status, ['completed', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'request_not_dismissible'];
        }

        $mediaRequest->delete();

        return ['ok' => true];
    }

    public function approveRequest(MediaRequest $request, ?int $reviewedByUserId = null): void
    {
        $this->transitionRequest($request, 'pending', [
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $reviewedByUserId,
        ], 'Request Approved', 'success');
    }

    public function rejectRequest(MediaRequest $request, ?int $reviewedByUserId = null): void
    {
        $this->transitionRequest($request, 'pending', [
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $reviewedByUserId,
        ], 'Request Rejected', 'warning');
    }

    public function completeRequest(MediaRequest $request): void
    {
        $this->transitionRequest($request, 'approved', [
            'status' => 'completed',
        ], 'Request Completed', 'success');
    }

    /**
     * Guarded status transition shared by approve/reject/complete: only the request that wins
     * the conditional update (i.e. is still in $fromStatus) broadcasts and notifies, so stale
     * retries and repeated polling can never mint a second event for the same transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transitionRequest(MediaRequest $request, string $fromStatus, array $attributes, string $notificationTitle, string $notificationStatus): void
    {
        $updated = MediaRequest::query()
            ->whereKey($request->getKey())
            ->where('status', $fromStatus)
            ->update($attributes);

        if ($updated !== 1) {
            return;
        }

        $request->refresh();
        $request->broadcastStatus();
        $playlist = $request->playlistAuth?->getAssignedModel();
        if ($playlist) {
            $this->notifyRequester($playlist, $request, $notificationTitle, $notificationStatus);
        }
    }

    private function notifyRequester(Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias $playlist, MediaRequest $request, string $title, string $status): void
    {
        AppNotification::make()
            ->title(__($title))
            ->body($request->title)
            ->status($status)
            ->tvBroadcast($playlist, 'requests', false, $request->playlistAuth, [
                'request_id' => $request->id,
                'request_title' => $request->title,
                'request_status' => $request->status,
            ]);
    }

    /** @return array<string, mixed> */
    private function formatRequest(MediaRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->request_type,
            'external_id' => $request->external_id,
            'title' => $request->title,
            'status' => $request->status === 'pending' ? 'pending_approval' : $request->status,
            'integration_id' => $request->arr_integration_id,
            'integration_name' => $request->arrIntegration?->name,
            'season_number' => $request->season_number,
            'episode_number' => $request->episode_number,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'can_dismiss' => in_array($request->status, ['completed', 'rejected'], true),
        ];
    }
}
