<?php

namespace App\Filament\Resources\TvDevices\Pages;

use App\Filament\Resources\TvDevices\TvDeviceResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListTvDevices extends ListRecords
{
    protected static string $resource = TvDeviceResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Every M3U TV app install that has talked to this server, across all platforms. Rows are added automatically the first time a device syncs and pruned automatically after :days days without contact - except revoked devices, which are kept until you delete them. "Last seen" is the last successful sync or call-home.', ['days' => config('services.push_relay.stale_days', 60)]);
    }

    public function getHeaderActions(): array
    {
        return [];
    }
}
