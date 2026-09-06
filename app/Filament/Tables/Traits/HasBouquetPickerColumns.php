<?php

namespace App\Filament\Tables\Traits;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The columns the bouquet / alias group pickers add on top of a picker table:
 * a tick for rows an assigned bouquet already covers, and (for merged-playlist
 * pickers spanning more than one source) the owning source playlist.
 *
 * Both are driven by table arguments, so a table used outside a picker - where
 * the arguments are absent - renders neither.
 */
trait HasBouquetPickerColumns
{
    /**
     * Ticks rows whose name is contributed by one of the alias's assigned bouquets
     * (`bouquet_group_names` table argument).
     */
    private static function bouquetMembershipColumn(Table $table): IconColumn
    {
        return IconColumn::make('in_bouquet')
            ->label(__('In bouquet'))
            ->visible(fn (): bool => ! empty($table->getArguments()['bouquet_group_names'] ?? []))
            ->state(fn ($record): bool => in_array($record->name, $table->getArguments()['bouquet_group_names'] ?? [], true))
            ->boolean();
    }

    /**
     * Tells same-named rows apart once the picker spans more than one source
     * playlist (`playlist_ids` table argument).
     */
    private static function sourcePlaylistColumn(Table $table): TextColumn
    {
        return TextColumn::make('playlist.name')
            ->label(__('Source Playlist'))
            ->visible(fn (): bool => count((array) ($table->getArguments()['playlist_ids'] ?? [])) > 1);
    }
}
