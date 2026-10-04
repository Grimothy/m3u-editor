<?php

namespace App\Enums;

/**
 * Which automated feature owns a cached content file.
 *
 * NULL (no case) means the file is manual (Cache Now) or pinned
 * (`never_expire` retention), and automated retention never deletes it.
 * Retention only ever releases files whose value matches its own feature:
 * dynamic-group retention releases `DynamicGroup` files. `source`
 * (CachedContentSource) records where the bytes come from; `managed_by`
 * only records who controls the lifecycle, so arr rows a group requested
 * are also `DynamicGroup`.
 */
enum CachedContentManagedBy: string
{
    case DynamicGroup = 'dynamic_group';
}
