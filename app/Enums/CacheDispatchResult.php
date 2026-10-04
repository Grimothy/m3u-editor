<?php

namespace App\Enums;

/**
 * Outcome of asking CachedContentDispatchService to cache one item.
 */
enum CacheDispatchResult: string
{
    case Queued = 'queued';
    case AlreadyCached = 'already_cached';
    case AlreadyQueued = 'already_queued';
    case Unavailable = 'unavailable';
    case Disabled = 'disabled';
    case CoolingDown = 'cooling_down';
    case MediaServerAvailable = 'media_server_available';

    // Arr-stack results (feat/cache-now-arr). ArrMonitoredFallback and
    // ArrFallbackQueued mean the provider leg queued instead.
    case ArrRequested = 'arr_requested';
    case ArrAlreadyAvailable = 'arr_already_available';
    case ArrMonitoredFallback = 'arr_monitored_fallback';
    case ArrFallbackQueued = 'arr_fallback_queued';
}
