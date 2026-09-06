<?php

namespace App\Traits;

use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The labels and the "Clear all" hint action shared by the group/category
 * ModalTableSelect builders behind the bouquet form (standard, custom and merged
 * playlist targets). Each builder differs only in the table it is backed by and
 * in how it translates state, not in how the picker presents itself.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
trait HasGroupSelectionModalLabels
{
    protected static function selectLabelFor(string $type): string
    {
        return match ($type) {
            'live' => __('Select live groups'),
            'vod' => __('Select VOD groups'),
            default => __('Select series categories'),
        };
    }

    protected static function modalHeadingFor(string $type): string
    {
        return match ($type) {
            'live' => __('Search live groups'),
            'vod' => __('Search VOD groups'),
            default => __('Search series categories'),
        };
    }

    /**
     * Empties the picker's selection. The action name is derived from the state
     * path so the hidden sibling pickers bound to the same path stay distinct.
     */
    protected static function clearSelectionAction(string $namePrefix, string $statePath): Action
    {
        return Action::make($namePrefix.str_replace('.', '_', $statePath))
            ->label(__('Clear all'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->action(fn (Set $set) => $set($statePath, []))
            ->requiresConfirmation()
            ->modalHeading(__('Clear selection'))
            ->modalSubmitActionLabel(__('Clear'));
    }
}
