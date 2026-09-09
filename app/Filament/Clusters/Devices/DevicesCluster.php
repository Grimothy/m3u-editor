<?php

namespace App\Filament\Clusters\Devices;

use App\Filament\Clusters\Devices\Pages\PairDevice;
use App\Filament\Resources\TvDevices\TvDeviceResource;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

class DevicesCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $slug = 'devices';

    protected static ?int $navigationSort = 6;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin()
            && (TvDeviceResource::isPushRelayEnabled() || TvDeviceResource::isDevicePairingEnabled());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return TvDeviceResource::isPushRelayEnabled() || TvDeviceResource::isDevicePairingEnabled();
    }

    /**
     * Registered Devices (push relay) first, Device Pairing second - the order
     * the tabs used before this cluster replaced them.
     *
     * @return array<class-string>
     */
    public static function getClusteredComponents(): array
    {
        return [
            TvDeviceResource::class,
            PairDevice::class,
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Devices');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Devices');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }
}
