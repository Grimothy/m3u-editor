<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Standalone per-Channel / per-Episode cache activity page (PR D).
 *
 * Hosts the CachedContentActivityWidget so operators have a single
 * sidebar destination for "what is happening with the cache right now"
 * (Pending / Downloading / Completed / Failed rows across all playlists
 * owned by the current user, or every playlist if the viewer is an
 * admin). Drops the per-DynamicGroup cache page surface from PR #1500
 * entirely - this page has zero DynamicGroup coupling.
 *
 * Gated on GeneralSettings::enable_cache so the page stays invisible
 * when the feature is off (matches the widget's own canView() and the
 * XtreamStreamController cache-hit gate in PR C).
 */
class CachedDownloadsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.cached-downloads';

    public static function getNavigationLabel(): string
    {
        return __('Cached Downloads');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getModelLabel(): string
    {
        return __('Cached Download');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cached Downloads');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Cached Downloads');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Cached Downloads');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Live download progress for cached VOD channels and Series episodes. Pending, Downloading, Completed, and Failed rows across your playlists are listed below.');
    }

    public static function shouldRegisterNavigation(): bool
    {
        // The page is intentionally always registered; the widget inside
        // gates itself on enable_cache. Operators who don't have the
        // feature enabled still want to be able to deep-link here from
        // documentation, and the empty state on the widget is friendlier
        // than a missing sidebar entry.
        return parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $settings = app(GeneralSettings::class);

        return (bool) ($settings->enable_cache ?? false);
    }
}
