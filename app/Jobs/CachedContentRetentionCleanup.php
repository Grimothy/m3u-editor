<?php

namespace App\Jobs;

use App\Models\DynamicGroup;
use App\Services\CachedContentRetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Wraps `CachedContentRetentionService::evaluate()` + `deleteIds()` for
 * scheduled invocation via `routes/console.php`. Schedule: daily at 03:00.
 *
 * Splits "what to delete" (service) from "do the deletion" (job) so the
 * service is unit-testable without touching the queue and the cleanup is
 * observable through Horizon's per-job metrics.
 *
 * Phase 2 / PR E: after the standalone `(user, playlist)` sweep, also
 * walk every DynamicGroup and call `evaluateForDynamicGroup()` for it.
 * The union of both ID lists is deleted in a single chunked `deleteIds`
 * pass so the on-disk + DB cleanup runs through one path. Iteration is
 * over an id list (no hydrated models in the loop).
 */
class CachedContentRetentionCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800; // 30 min - a multi-thousand-row cleanup can take a while

    public function __construct()
    {
        $this->onQueue('cache');
    }

    public function handle(CachedContentRetentionService $service): int
    {
        // Standalone (user, playlist) sweep — covers files that aren't
        // DG-owned (or that are but the DG path has nothing to do this
        // pass).
        $ids = $service->evaluate();

        // Phase 2 / PR E: per-DynamicGroup sweep. Pluck id-only via
        // cursor() so a playlist with hundreds of DG rules doesn't
        // hydrate a model per rule.
        $dgIdCursor = DynamicGroup::query()->cursor();
        foreach ($dgIdCursor as $dg) {
            $ids = $ids->merge($service->evaluateForDynamicGroup((int) $dg->id));
        }

        // Dedupe IDs across both passes — a file linked to multiple DGs
        // whose membership all flipped out-of-scope could be returned by
        // more than one evaluateForDynamicGroup call.
        $ids = $ids->unique()->values();

        $count = $service->deleteIds($ids);

        Log::info("CachedContentRetentionCleanup: deleted {$count} cached files (evaluated ".count($ids).' eligible).');

        return $count;
    }
}
