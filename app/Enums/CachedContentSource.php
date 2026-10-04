<?php

namespace App\Enums;

/**
 * Where a cached content row's bytes come from: a direct provider
 * download (local file), or a Radarr/Sonarr request (file stays on the
 * arr host and plays through a media server match).
 */
enum CachedContentSource: string
{
    case Provider = 'provider';
    case Radarr = 'radarr';
    case Sonarr = 'sonarr';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provider => __('Provider'),
            self::Radarr => __('Radarr'),
            self::Sonarr => __('Sonarr'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Provider => 'gray',
            self::Radarr => 'warning',
            self::Sonarr => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Provider => 'heroicon-o-server',
            self::Radarr => 'heroicon-o-film',
            self::Sonarr => 'heroicon-o-tv',
        };
    }

    public function isArr(): bool
    {
        return $this !== self::Provider;
    }
}
